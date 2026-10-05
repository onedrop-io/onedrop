//! Running projects on this computer (DESK-010): a WebSocket kept open to the device relay while signed in, over which
//! the hosted app manages sandbox containers in the computer's Docker (`/rpc/*`) and the gateway reaches their ports
//! (`/port/<container>/<port>/<path>`, HTTP or WebSocket). Text messages are JSON; binary ones are
//! `[u32 big-endian stream id][u8 kind][payload]`. Also Docker's status for the bridge, and downloading the image.

use crate::api::{Api, DeviceLink};
use crate::desktop::{self, changed, Backoff};
use crate::docker::{self, DockerStatus};
use crate::tunnel;
use bytes::Bytes;
use futures_util::{SinkExt, StreamExt};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::collections::{BTreeMap, HashMap};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};
use tauri::async_runtime::JoinHandle;
use tauri::{AppHandle, Manager};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::sync::mpsc;
use tokio::task::AbortHandle;
use tokio_tungstenite::tungstenite::client::IntoClientRequest;
use tokio_tungstenite::tungstenite::http::{HeaderName, HeaderValue};
use tokio_tungstenite::tungstenite::protocol::frame::coding::CloseCode;
use tokio_tungstenite::tungstenite::protocol::CloseFrame;
use tokio_tungstenite::tungstenite::Message;

/// Binary message kinds.
pub const BODY: u8 = 0;
pub const WS_TEXT: u8 = 1;
pub const WS_BINARY: u8 = 2;

/// Body chunks are at most this big.
pub const MAX_CHUNK: usize = 512 * 1024;

/// The app's keep-alive, which the relay answers without waking.
const PING_EVERY: Duration = Duration::from_secs(25);

/// This long without a word from the relay, the connection is gone.
const SILENCE: Duration = Duration::from_secs(90);

/// A relayed request gives up after this long, so the relay isn't left waiting.
const REQUEST_LIMIT: Duration = Duration::from_secs(600);

/// How often an install without a relay is asked again (it may get one).
const UNAVAILABLE_RETRY: Duration = Duration::from_secs(600);

/// An empty tar archive: two zero blocks.
const EMPTY_TAR: [u8; 1024] = [0; 1024];

/// Headers that belong to one connection, not passed on.
const HOP_BY_HOP: &[&str] = &[
    "connection",
    "keep-alive",
    "proxy-connection",
    "transfer-encoding",
    "te",
    "trailer",
    "upgrade",
];

/// The device link's status, for the bridge.
#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct DeviceStatus {
    available: bool,
    state: &'static str,
    error: Option<String>,
}

pub struct Relay {
    status: Mutex<DeviceStatus>,
    image: Mutex<String>,
    task: Mutex<Option<JoinHandle<()>>>,
    /// Docker's status while the image downloads.
    pulling: Mutex<Option<DockerStatus>>,
    pull: tokio::sync::Mutex<()>,
}

impl Default for Relay {
    fn default() -> Self {
        Self {
            status: Mutex::new(DeviceStatus {
                available: true,
                state: "off",
                error: None,
            }),
            image: Mutex::new(docker::DEFAULT_IMAGE.into()),
            task: Mutex::new(None),
            pulling: Mutex::new(None),
            pull: tokio::sync::Mutex::new(()),
        }
    }
}

fn set(app: &AppHandle, available: bool, state: &'static str, error: Option<String>) {
    let next = DeviceStatus {
        available,
        state,
        error,
    };
    let relay = app.state::<Relay>();
    let mut status = relay.status.lock().unwrap();

    if *status != next {
        *status = next;
        drop(status);
        changed(app);
    }
}

fn image(app: &AppHandle) -> String {
    app.state::<Relay>().image.lock().unwrap().clone()
}

/// A binary message for a stream.
pub fn encode_frame(id: u32, kind: u8, payload: &[u8]) -> Vec<u8> {
    let mut frame = Vec::with_capacity(5 + payload.len());
    frame.extend_from_slice(&id.to_be_bytes());
    frame.push(kind);
    frame.extend_from_slice(payload);

    frame
}

/// A binary message's stream id, kind and payload.
pub fn decode_frame(bytes: &[u8]) -> Option<(u32, u8, &[u8])> {
    if bytes.len() < 5 {
        return None;
    }

    let id = u32::from_be_bytes([bytes[0], bytes[1], bytes[2], bytes[3]]);

    Some((id, bytes[4], &bytes[5..]))
}

/// A text message from the relay.
#[derive(Debug, Deserialize)]
#[serde(tag = "t")]
enum FromRelay {
    #[serde(rename = "req")]
    Request {
        id: u32,
        method: String,
        path: String,
        #[serde(default)]
        headers: Vec<(String, String)>,
        #[serde(default)]
        body: bool,
    },
    #[serde(rename = "end")]
    End { id: u32 },
    #[serde(rename = "abort")]
    Abort { id: u32 },
    #[serde(rename = "ws")]
    WebSocket {
        id: u32,
        path: String,
        #[serde(default)]
        headers: Vec<(String, String)>,
    },
    #[serde(rename = "ws-close")]
    WebSocketClose {
        id: u32,
        code: Option<u16>,
        reason: Option<String>,
    },
    #[serde(rename = "ping")]
    Ping,
    #[serde(other)]
    Other,
}

/// A message for a relayed WebSocket.
enum WsIn {
    Text(String),
    Binary(Bytes),
    Close(u16, String),
}

/// A stream in flight: where its request body or WebSocket messages go, and its task.
#[derive(Default)]
struct Entry {
    body: Option<mpsc::UnboundedSender<Bytes>>,
    ws: Option<mpsc::UnboundedSender<WsIn>>,
    task: Option<AbortHandle>,
}

type Streams = Arc<Mutex<HashMap<u32, Entry>>>;

/// Writing one stream's answer to the relay.
#[derive(Clone)]
struct Out {
    id: u32,
    tx: mpsc::Sender<Message>,
}

impl Out {
    async fn text(&self, value: Value) {
        let _ = self.tx.send(Message::Text(value.to_string().into())).await;
    }

    async fn head(&self, status: u16, headers: Vec<(String, String)>) {
        self.text(json!({ "t": "res", "id": self.id, "status": status, "headers": headers }))
            .await;
    }

    async fn chunk(&self, bytes: &[u8]) {
        for part in bytes.chunks(MAX_CHUNK) {
            let _ = self
                .tx
                .send(Message::Binary(encode_frame(self.id, BODY, part).into()))
                .await;
        }
    }

    async fn end(&self) {
        self.text(json!({ "t": "end", "id": self.id })).await;
    }

    async fn abort(&self, reason: &str) {
        self.text(json!({ "t": "abort", "id": self.id, "reason": reason }))
            .await;
    }

    async fn json(&self, status: u16, value: Value) {
        self.head(
            status,
            vec![("content-type".into(), "application/json".into())],
        )
        .await;
        self.chunk(value.to_string().as_bytes()).await;
        self.end().await;
    }

    async fn error(&self, status: u16, message: &str) {
        self.json(status, json!({ "error": message })).await;
    }
}

/// Something the computer can't do, with the status to answer.
struct Failure(u16, String);

impl From<String> for Failure {
    fn from(message: String) -> Self {
        Failure(500, message)
    }
}

/// `/port/<container>/<port><path>`: the container, its port, and the path (with its query) on it.
pub fn parse_port_path(path: &str) -> Option<(String, u16, String)> {
    let rest = path.strip_prefix("/port/")?;
    let (container, rest) = rest.split_once('/')?;
    let (port, rest) = match rest.find(['/', '?']) {
        Some(at) => (&rest[..at], &rest[at..]),
        None => (rest, ""),
    };
    let rest = if rest.starts_with('?') || rest.is_empty() {
        format!("/{rest}")
    } else {
        rest.to_string()
    };

    docker::check_name(container).ok()?;

    Some((container.to_string(), port.parse().ok()?, rest))
}

/// A query parameter's value.
fn query_value(query: &str, name: &str) -> Option<String> {
    url::form_urlencoded::parse(query.as_bytes())
        .find(|(key, _)| key == name)
        .map(|(_, value)| value.into_owned())
}

/// Read a request body to the end (small JSON bodies).
async fn read_body(body: &mut mpsc::UnboundedReceiver<Bytes>) -> Result<Vec<u8>, Failure> {
    let mut bytes = vec![];

    while let Some(chunk) = body.recv().await {
        bytes.extend_from_slice(&chunk);

        if bytes.len() > 64 * 1024 * 1024 {
            return Err(Failure(413, "The request is too big.".into()));
        }
    }

    Ok(bytes)
}

#[derive(Deserialize)]
struct CreateBody {
    name: String,
    image: String,
    #[serde(default)]
    env: BTreeMap<String, String>,
    ports: docker::Ports,
}

#[derive(Deserialize)]
struct IdBody {
    id: String,
}

#[derive(Deserialize)]
struct ExecBody {
    id: String,
    command: Vec<String>,
    #[serde(default)]
    env: BTreeMap<String, String>,
    #[serde(default)]
    detach: bool,
    user: Option<String>,
    timeout: Option<u64>,
}

#[derive(Deserialize)]
struct OutdatedBody {
    id: String,
    image: String,
}

fn parse<T: for<'de> Deserialize<'de>>(bytes: &[u8]) -> Result<T, Failure> {
    serde_json::from_slice(bytes).map_err(|error| Failure(400, format!("Bad request: {error}")))
}

/// The container a request names, if it's one the app may touch.
fn container(id: &str) -> Result<&str, Failure> {
    docker::check_name(id).map_err(|message| Failure(403, message))?;

    Ok(id)
}

/// The JSON `/rpc/*` calls.
async fn rpc(name: &str, body: &[u8], device: u64) -> Result<Value, Failure> {
    match name {
        "create" => {
            let body: CreateBody = parse(body)?;
            container(&body.name)?;
            let id =
                docker::create(&body.name, &body.image, &body.env, &body.ports, device).await?;

            Ok(json!({ "id": id }))
        }
        "start" | "wake" => {
            let body: IdBody = parse(body)?;
            let woke = docker::wake(container(&body.id)?).await?;

            Ok(if name == "wake" {
                json!({ "woke": woke })
            } else {
                json!({})
            })
        }
        "stop" => {
            let body: IdBody = parse(body)?;
            docker::stop(container(&body.id)?).await?;

            Ok(json!({}))
        }
        "suspend" => {
            let body: IdBody = parse(body)?;
            docker::suspend(container(&body.id)?).await?;

            Ok(json!({}))
        }
        "destroy" => {
            let body: IdBody = parse(body)?;
            docker::destroy(container(&body.id)?).await?;

            Ok(json!({}))
        }
        "exec" => {
            let body: ExecBody = parse(body)?;
            let output = docker::exec(
                container(&body.id)?,
                &body.command,
                &body.env,
                body.detach,
                body.user.as_deref(),
                Duration::from_secs(body.timeout.unwrap_or(120).clamp(1, 590)),
            )
            .await?;

            Ok(json!({ "exit": output.code, "stdout": output.stdout, "stderr": output.stderr }))
        }
        "outdated" => {
            let body: OutdatedBody = parse(body)?;

            Ok(json!({ "outdated": docker::outdated(container(&body.id)?, &body.image).await? }))
        }
        _ => Err(Failure(404, format!("No such call: {name}"))),
    }
}

/// `/rpc/copy-out?id=&path=`: a tar of the folder's contents (an empty one for a folder that isn't there).
async fn copy_out(query: &str, out: &Out) -> Result<(), Failure> {
    let id = query_value(query, "id").unwrap_or_default();
    let path = query_value(query, "path").unwrap_or_default();
    container(&id)?;

    let mut child = docker::copy_out_command(&id, &path)
        .map_err(|message| Failure(400, message))?
        .spawn()
        .map_err(|error| error.to_string())?;
    let mut stdout = child.stdout.take().expect("piped stdout");
    let mut stderr = child.stderr.take().expect("piped stderr");
    let errors = tokio::spawn(async move {
        let mut text = String::new();
        let _ = stderr.read_to_string(&mut text).await;

        text
    });
    let mut buffer = vec![0u8; MAX_CHUNK];
    let first = stdout
        .read(&mut buffer)
        .await
        .map_err(|error| error.to_string())?;

    if first == 0 {
        let status = child.wait().await.map_err(|error| error.to_string())?;
        let stderr = errors.await.unwrap_or_default();

        if !status.success() && !stderr.contains("Could not find the file") {
            return Err(Failure(500, stderr.trim().to_string()));
        }

        out.head(
            200,
            vec![("content-type".into(), "application/x-tar".into())],
        )
        .await;
        out.chunk(&EMPTY_TAR).await;
        out.end().await;

        return Ok(());
    }

    out.head(
        200,
        vec![("content-type".into(), "application/x-tar".into())],
    )
    .await;
    out.chunk(&buffer[..first]).await;

    loop {
        match stdout.read(&mut buffer).await {
            Ok(0) => break,
            Ok(read) => out.chunk(&buffer[..read]).await,
            Err(error) => {
                out.abort(&error.to_string()).await;
                return Ok(());
            }
        }
    }

    let status = child.wait().await.map_err(|error| error.to_string())?;

    if status.success() {
        out.end().await;
    } else {
        out.abort(errors.await.unwrap_or_default().trim()).await;
    }

    Ok(())
}

/// `/rpc/copy-in?id=&path=&root=`: unpack the request's tar in the container.
async fn copy_in(
    query: &str,
    body: &mut mpsc::UnboundedReceiver<Bytes>,
    out: &Out,
) -> Result<(), Failure> {
    let id = query_value(query, "id").unwrap_or_default();
    let path = query_value(query, "path").unwrap_or_default();
    let root = query_value(query, "root").as_deref() == Some("1");
    container(&id)?;

    if root {
        docker::wake(&id).await?;
    } else {
        docker::prepare_copy_in(&id, &path).await?;
    }

    let mut child = docker::copy_in_command(&id, &path, root)
        .map_err(|message| Failure(400, message))?
        .spawn()
        .map_err(|error| error.to_string())?;
    let mut stdin = child.stdin.take().expect("piped stdin");

    while let Some(chunk) = body.recv().await {
        if stdin.write_all(&chunk).await.is_err() {
            break;
        }
    }

    drop(stdin);
    let output = child
        .wait_with_output()
        .await
        .map_err(|error| error.to_string())?;

    if !output.status.success() {
        return Err(Failure(
            500,
            String::from_utf8_lossy(&output.stderr).trim().to_string(),
        ));
    }

    if !root {
        docker::finish_copy_in(&id, &path).await?;
    }

    out.json(200, json!({})).await;

    Ok(())
}

/// Published ports, remembered: asking Docker for every request would be slow.
fn port_cache() -> &'static Mutex<HashMap<(String, u16), u16>> {
    static CACHE: OnceLock<Mutex<HashMap<(String, u16), u16>>> = OnceLock::new();

    CACHE.get_or_init(Default::default)
}

async fn host_port(container: &str, port: u16, fresh: bool) -> Result<u16, Failure> {
    let key = (container.to_string(), port);

    if !fresh {
        if let Some(found) = port_cache().lock().unwrap().get(&key) {
            return Ok(*found);
        }
    }

    let found = docker::published_port(container, port)
        .await
        .map_err(|message| Failure(502, message))?;
    port_cache().lock().unwrap().insert(key, found);

    Ok(found)
}

fn local_client() -> &'static reqwest::Client {
    static CLIENT: OnceLock<reqwest::Client> = OnceLock::new();

    // No redirects followed and no decompression (the body passes through as it is, Content-Encoding and all).
    CLIENT.get_or_init(|| {
        reqwest::Client::builder()
            .no_proxy()
            .redirect(reqwest::redirect::Policy::none())
            .connect_timeout(Duration::from_secs(10))
            .build()
            .expect("an HTTP client")
    })
}

fn passed_on(name: &str) -> bool {
    !HOP_BY_HOP.contains(&name.to_ascii_lowercase().as_str())
}

/// `/port/…` as HTTP: the container's port answers, streamed both ways.
async fn port_http(
    method: &str,
    path: &str,
    headers: &[(String, String)],
    has_body: bool,
    body: mpsc::UnboundedReceiver<Bytes>,
    out: &Out,
) -> Result<(), Failure> {
    let (container, port, rest) =
        parse_port_path(path).ok_or_else(|| Failure(404, "Not a sandbox port.".into()))?;
    let method = reqwest::Method::from_bytes(method.as_bytes())
        .map_err(|_| Failure(400, "Bad method.".into()))?;
    let mut body = Some(body);
    let mut response = None;

    // Once from the cache, once more with a fresh port in case the container was recreated.
    for fresh in [false, true] {
        let local = host_port(&container, port, fresh).await?;
        let mut request =
            local_client().request(method.clone(), format!("http://127.0.0.1:{local}{rest}"));

        for (name, value) in headers.iter().filter(|(name, _)| passed_on(name)) {
            request = request.header(name.as_str(), value.as_str());
        }

        if has_body {
            let Some(chunks) = body.take() else {
                break;
            };
            let stream = futures_util::stream::unfold(chunks, |mut chunks| async move {
                chunks
                    .recv()
                    .await
                    .map(|chunk| (Ok::<Bytes, std::io::Error>(chunk), chunks))
            });
            request = request.body(reqwest::Body::wrap_stream(stream));
        }

        match request.send().await {
            Ok(answer) => {
                response = Some(answer);
                break;
            }
            Err(error) if error.is_connect() && !fresh && !has_body => continue,
            Err(error) => {
                return Err(Failure(
                    502,
                    format!("The sandbox's port {port} didn't answer: {error}"),
                ))
            }
        }
    }

    let response = response
        .ok_or_else(|| Failure(502, format!("The sandbox's port {port} didn't answer.")))?;
    let head: Vec<(String, String)> = response
        .headers()
        .iter()
        .filter(|(name, _)| passed_on(name.as_str()))
        .filter_map(|(name, value)| Some((name.to_string(), value.to_str().ok()?.to_string())))
        .collect();

    out.head(response.status().as_u16(), head).await;

    let mut stream = response.bytes_stream();

    while let Some(chunk) = stream.next().await {
        match chunk {
            Ok(chunk) => out.chunk(&chunk).await,
            Err(error) => {
                out.abort(&error.to_string()).await;
                return Ok(());
            }
        }
    }

    out.end().await;

    Ok(())
}

/// A relayed request, answered.
async fn request(
    method: String,
    path: String,
    headers: Vec<(String, String)>,
    has_body: bool,
    mut body: mpsc::UnboundedReceiver<Bytes>,
    out: Out,
    device: u64,
) {
    let (route, query) = path.split_once('?').unwrap_or((path.as_str(), ""));

    let result = if route.starts_with("/port/") {
        port_http(&method, &path, &headers, has_body, body, &out).await
    } else if method != "POST" {
        Err(Failure(405, "Use POST.".into()))
    } else if route == "/rpc/copy-out" {
        copy_out(query, &out).await
    } else if route == "/rpc/copy-in" {
        copy_in(query, &mut body, &out).await
    } else if let Some(name) = route.strip_prefix("/rpc/") {
        match read_body(&mut body).await {
            Ok(bytes) => match rpc(name, &bytes, device).await {
                Ok(value) => {
                    out.json(200, value).await;
                    Ok(())
                }
                Err(failure) => Err(failure),
            },
            Err(failure) => Err(failure),
        }
    } else {
        Err(Failure(404, "Not found.".into()))
    };

    if let Err(Failure(status, message)) = result {
        out.error(status, &message).await;
    }
}

/// A close code the local socket may be sent.
fn sendable(code: u16) -> u16 {
    match code {
        1000..=1003 | 1007..=1014 | 3000..=4999 => code,
        _ => 1000,
    }
}

/// `/port/…` as a WebSocket: open the container's, then pass messages both ways.
async fn websocket(
    path: String,
    headers: Vec<(String, String)>,
    mut from_relay: mpsc::UnboundedReceiver<WsIn>,
    out: Out,
) {
    let Some((container, port, rest)) = parse_port_path(&path) else {
        out.error(404, "Not a sandbox port.").await;
        return;
    };
    let local = match host_port(&container, port, false).await {
        Ok(local) => local,
        Err(Failure(status, message)) => {
            out.error(status, &message).await;
            return;
        }
    };
    let Ok(mut request) = format!("ws://127.0.0.1:{local}{rest}").into_client_request() else {
        out.error(400, "Bad path.").await;
        return;
    };

    for (name, value) in &headers {
        let lower = name.to_ascii_lowercase();

        if !passed_on(&lower)
            || (lower.starts_with("sec-websocket-") && lower != "sec-websocket-protocol")
            || lower == "content-length"
        {
            continue;
        }

        if let (Ok(name), Ok(value)) = (
            HeaderName::from_bytes(name.as_bytes()),
            HeaderValue::from_str(value),
        ) {
            request.headers_mut().insert(name, value);
        }
    }

    let (socket, response) = match tunnel::connect_request(request).await {
        Ok(connected) => connected,
        Err(error) => {
            port_cache().lock().unwrap().remove(&(container, port));
            out.error(502, &error).await;
            return;
        }
    };
    let protocol = response
        .headers()
        .get("sec-websocket-protocol")
        .and_then(|value| value.to_str().ok())
        .map(String::from);

    out.text(json!({ "t": "ws-ok", "id": out.id, "protocol": protocol }))
        .await;

    let (mut sink, mut stream) = socket.split();

    loop {
        tokio::select! {
            message = stream.next() => match message {
                Some(Ok(Message::Text(text))) => {
                    let _ = out.tx.send(Message::Binary(encode_frame(out.id, WS_TEXT, text.as_bytes()).into())).await;
                }
                Some(Ok(Message::Binary(bytes))) => {
                    let _ = out.tx.send(Message::Binary(encode_frame(out.id, WS_BINARY, &bytes).into())).await;
                }
                Some(Ok(Message::Close(frame))) => {
                    let (code, reason) = frame
                        .map(|frame| (u16::from(frame.code), frame.reason.to_string()))
                        .unwrap_or((1000, String::new()));
                    out.text(json!({ "t": "ws-close", "id": out.id, "code": code, "reason": reason })).await;
                    return;
                }
                Some(Ok(_)) => {}
                Some(Err(_)) | None => {
                    out.text(json!({ "t": "ws-close", "id": out.id, "code": 1011, "reason": "The sandbox closed the connection" })).await;
                    return;
                }
            },
            message = from_relay.recv() => match message {
                Some(WsIn::Text(text)) => {
                    if sink.send(Message::Text(text.into())).await.is_err() {
                        return;
                    }
                }
                Some(WsIn::Binary(bytes)) => {
                    if sink.send(Message::Binary(bytes)).await.is_err() {
                        return;
                    }
                }
                Some(WsIn::Close(code, reason)) => {
                    let _ = sink
                        .send(Message::Close(Some(CloseFrame { code: CloseCode::from(sendable(code)), reason: reason.into() })))
                        .await;
                    return;
                }
                None => {
                    let _ = sink.send(Message::Close(None)).await;
                    return;
                }
            },
        }
    }
}

/// Run a stream's task, forgetting the stream when it's done (or given up on after REQUEST_LIMIT).
fn spawn_stream<F>(
    streams: &Streams,
    id: u32,
    entry: Entry,
    out: Out,
    limit: Option<Duration>,
    task: F,
) where
    F: std::future::Future<Output = ()> + Send + 'static,
{
    streams.lock().unwrap().insert(id, entry);

    let forget = streams.clone();
    let handle = tokio::spawn(async move {
        match limit {
            Some(limit) => {
                if tokio::time::timeout(limit, task).await.is_err() {
                    out.abort("The computer took too long to answer.").await;
                }
            }
            None => task.await,
        }

        forget.lock().unwrap().remove(&id);
    });

    if let Some(entry) = streams.lock().unwrap().get_mut(&id) {
        entry.task = Some(handle.abort_handle());
    }
}

/// One connection to the relay, until it closes or fails.
async fn connection(url: &str, device: u64, connected: impl FnOnce()) -> Result<(), String> {
    let socket = tunnel::connect(url).await?;
    connected();

    let (mut sink, mut stream) = socket.split();
    let (tx, mut outgoing) = mpsc::channel::<Message>(256);
    let streams: Streams = Default::default();
    let mut ping = tokio::time::interval(PING_EVERY);
    let mut heard = Instant::now();

    let result = loop {
        tokio::select! {
            message = stream.next() => {
                heard = Instant::now();

                match message {
                    Some(Ok(Message::Text(text))) => {
                        let Ok(message) = serde_json::from_str::<FromRelay>(&text) else {
                            continue;
                        };

                        match message {
                            FromRelay::Request { id, method, path, headers, body } => {
                                let (body_tx, body_rx) = mpsc::unbounded_channel();
                                let out = Out { id, tx: tx.clone() };
                                let entry = Entry {
                                    body: body.then_some(body_tx),
                                    ..Default::default()
                                };

                                spawn_stream(
                                    &streams,
                                    id,
                                    entry,
                                    out.clone(),
                                    Some(REQUEST_LIMIT),
                                    request(method, path, headers, body, body_rx, out, device),
                                );
                            }
                            FromRelay::End { id } => {
                                if let Some(entry) = streams.lock().unwrap().get_mut(&id) {
                                    entry.body = None;
                                }
                            }
                            FromRelay::Abort { id } => {
                                if let Some(task) = streams.lock().unwrap().remove(&id).and_then(|entry| entry.task) {
                                    task.abort();
                                }
                            }
                            FromRelay::WebSocket { id, path, headers } => {
                                let (ws_tx, ws_rx) = mpsc::unbounded_channel();
                                let out = Out { id, tx: tx.clone() };
                                let entry = Entry {
                                    ws: Some(ws_tx),
                                    ..Default::default()
                                };

                                spawn_stream(&streams, id, entry, out.clone(), None, websocket(path, headers, ws_rx, out));
                            }
                            FromRelay::WebSocketClose { id, code, reason } => {
                                if let Some(ws) = streams.lock().unwrap().get(&id).and_then(|entry| entry.ws.clone()) {
                                    let _ = ws.send(WsIn::Close(code.unwrap_or(1000), reason.unwrap_or_default()));
                                }
                            }
                            // Straight to the socket: waiting on the queue this loop drains could stall it.
                            FromRelay::Ping => {
                                if let Err(error) = sink.send(Message::Text(json!({ "t": "pong" }).to_string().into())).await {
                                    break Err(error.to_string());
                                }
                            }
                            FromRelay::Other => {}
                        }
                    }
                    Some(Ok(Message::Binary(bytes))) => {
                        if let Some((id, kind, payload)) = decode_frame(&bytes) {
                            let streams = streams.lock().unwrap();
                            let entry = streams.get(&id);

                            match kind {
                                BODY => {
                                    if let Some(body) = entry.and_then(|entry| entry.body.as_ref()) {
                                        let _ = body.send(Bytes::copy_from_slice(payload));
                                    }
                                }
                                WS_TEXT | WS_BINARY => {
                                    if let Some(ws) = entry.and_then(|entry| entry.ws.as_ref()) {
                                        let _ = ws.send(if kind == WS_TEXT {
                                            WsIn::Text(String::from_utf8_lossy(payload).into_owned())
                                        } else {
                                            WsIn::Binary(Bytes::copy_from_slice(payload))
                                        });
                                    }
                                }
                                _ => {}
                            }
                        }
                    }
                    Some(Ok(Message::Close(_))) | None => break Ok(()),
                    Some(Err(error)) => break Err(error.to_string()),
                    Some(Ok(_)) => {}
                }
            }
            Some(message) = outgoing.recv() => {
                if let Err(error) = sink.send(message).await {
                    break Err(error.to_string());
                }
            }
            _ = ping.tick() => {
                if heard.elapsed() > SILENCE {
                    break Err("The relay stopped answering.".into());
                }

                if let Err(error) = sink.send(Message::Text(json!({ "t": "ping" }).to_string().into())).await {
                    break Err(error.to_string());
                }
            }
        }
    };

    for (_, entry) in streams.lock().unwrap().drain() {
        if let Some(task) = entry.task {
            task.abort();
        }
    }

    result
}

/// Keep the computer linked while signed in: reconnect with a fresh ticket each time, waiting longer after failures.
async fn keep_linked(app: AppHandle, api: Api, mut first: Option<DeviceLink>) {
    let mut backoff = Backoff::new(Duration::from_secs(60));

    loop {
        let link = match first.take() {
            Some(link) => Ok(link),
            None => api.device().await,
        };

        match link {
            Ok(link) => {
                if let Some(image) = link.image.filter(|image| !image.is_empty()) {
                    *app.state::<Relay>().image.lock().unwrap() = image;
                }

                let Some(url) = link.url else {
                    set(&app, false, "off", None);
                    tokio::time::sleep(UNAVAILABLE_RETRY).await;
                    continue;
                };

                set(&app, true, "connecting", None);
                let started = Instant::now();
                let device = desktop::device_id(&app).or(link.id).unwrap_or(0);
                let result = connection(&url, device, || set(&app, true, "connected", None)).await;

                if started.elapsed() > Duration::from_secs(60) {
                    backoff.reset();
                }

                match result {
                    Ok(()) => set(&app, true, "connecting", None),
                    Err(error) => set(&app, true, "error", Some(error)),
                }
            }
            Err(error) => set(&app, true, "error", Some(error.message)),
        }

        tokio::time::sleep(backoff.next()).await;
    }
}

/// Start the device link (signing in).
pub fn start(app: &AppHandle, link: Option<DeviceLink>) {
    let Ok(api) = desktop::api(app) else {
        return;
    };
    let task = tauri::async_runtime::spawn(keep_linked(app.clone(), api, link));

    if let Some(old) = app.state::<Relay>().task.lock().unwrap().replace(task) {
        old.abort();
    }
}

/// Stop the device link (signing out).
pub fn stop(app: &AppHandle) {
    if let Some(task) = app.state::<Relay>().task.lock().unwrap().take() {
        task.abort();
    }

    set(app, true, "off", None);
}

#[tauri::command]
pub fn devices_status(app: AppHandle) -> DeviceStatus {
    app.state::<Relay>().status.lock().unwrap().clone()
}

#[tauri::command]
pub async fn docker_status(app: AppHandle) -> DockerStatus {
    if let Some(pulling) = app.state::<Relay>().pulling.lock().unwrap().clone() {
        return pulling;
    }

    docker::status(&image(&app)).await
}

/// Download the sandbox image (one download at a time; a second caller waits for the first).
#[tauri::command]
pub async fn docker_prepare(app: AppHandle) -> Result<DockerStatus, String> {
    let relay = app.state::<Relay>();
    let _one = relay.pull.lock().await;
    let image = image(&app);
    let status = docker::status(&image).await;

    if status.state != "ready" || status.has_image {
        return Ok(status);
    }

    let pulling = |progress: f64| DockerStatus {
        state: "pulling",
        detail: status.detail.clone(),
        progress: Some(progress),
        has_image: false,
    };

    *relay.pulling.lock().unwrap() = Some(pulling(0.0));
    changed(&app);

    let mut shown = 0.0;
    let result = docker::pull(&image, |progress| {
        if progress - shown >= 0.01 {
            shown = progress;
            *app.state::<Relay>().pulling.lock().unwrap() = Some(pulling(progress));
            changed(&app);
        }
    })
    .await;

    *relay.pulling.lock().unwrap() = None;
    changed(&app);
    result?;

    Ok(docker::status(&image).await)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn frames_carry_the_stream_and_kind() {
        let frame = encode_frame(258, WS_BINARY, b"hi");

        assert_eq!(frame, vec![0, 0, 1, 2, 2, b'h', b'i']);
        assert_eq!(decode_frame(&frame), Some((258, WS_BINARY, &b"hi"[..])));
        assert_eq!(decode_frame(&[0, 0, 0, 1, 0]), Some((1, BODY, &b""[..])));
        assert_eq!(decode_frame(&[0, 0, 1]), None);
        assert_eq!(encode_frame(u32::MAX, BODY, b"")[..4], [255, 255, 255, 255]);
    }

    #[test]
    fn relay_messages_parse() {
        let request: FromRelay = serde_json::from_str(
            r#"{"t":"req","id":1,"method":"POST","path":"/rpc/exec","headers":[["content-type","application/json"]],"body":true}"#,
        )
        .unwrap();

        assert!(matches!(
            request,
            FromRelay::Request {
                id: 1,
                body: true,
                ..
            }
        ));
        assert!(matches!(
            serde_json::from_str::<FromRelay>(r#"{"t":"ping"}"#).unwrap(),
            FromRelay::Ping
        ));
        assert!(matches!(
            serde_json::from_str::<FromRelay>(r#"{"t":"ws-close","id":2,"code":1000,"reason":""}"#)
                .unwrap(),
            FromRelay::WebSocketClose {
                id: 2,
                code: Some(1000),
                ..
            }
        ));
        assert!(matches!(
            serde_json::from_str::<FromRelay>(r#"{"t":"later"}"#).unwrap(),
            FromRelay::Other
        ));
    }

    #[test]
    fn port_paths_name_a_container_port_and_path() {
        assert_eq!(
            parse_port_path("/port/onedrop-project-9-ab/7681/ws?x=1"),
            Some(("onedrop-project-9-ab".into(), 7681, "/ws?x=1".into()))
        );
        assert_eq!(
            parse_port_path("/port/onedrop-project-9-ab/8000"),
            Some(("onedrop-project-9-ab".into(), 8000, "/".into()))
        );
        assert_eq!(
            parse_port_path("/port/onedrop-project-9-ab/8000?a=b"),
            Some(("onedrop-project-9-ab".into(), 8000, "/?a=b".into()))
        );
        assert_eq!(parse_port_path("/port/postgres/5432/"), None);
        assert_eq!(parse_port_path("/port/onedrop-project-9-ab/x/"), None);
    }

    #[test]
    fn queries_are_read() {
        assert_eq!(
            query_value("id=onedrop-project-1&path=%2Fworkspace&root=1", "path").as_deref(),
            Some("/workspace")
        );
        assert_eq!(query_value("id=a", "root"), None);
    }

    #[test]
    fn close_codes_the_socket_cant_send_become_normal() {
        assert_eq!(sendable(1000), 1000);
        assert_eq!(sendable(1006), 1000);
        assert_eq!(sendable(1005), 1000);
        assert_eq!(sendable(1011), 1011);
        assert_eq!(sendable(4001), 4001);
    }

    #[tokio::test]
    async fn bad_calls_get_errors() {
        assert!(matches!(rpc("nope", b"{}", 1).await, Err(Failure(404, _))));
        assert!(matches!(
            rpc("stop", b"{\"id\":\"postgres\"}", 1).await,
            Err(Failure(403, _))
        ));
        assert!(matches!(
            rpc("exec", b"not json", 1).await,
            Err(Failure(400, _))
        ));
    }

    #[tokio::test]
    async fn responses_end_even_when_empty() {
        let (tx, mut rx) = mpsc::channel(16);
        let out = Out { id: 7, tx };

        out.json(200, json!({})).await;
        drop(out);

        let mut messages = vec![];

        while let Some(message) = rx.recv().await {
            messages.push(message);
        }

        assert_eq!(messages.len(), 3);
        let text =
            |message: &Message| serde_json::from_str::<Value>(message.to_text().unwrap()).unwrap();

        assert_eq!(
            text(&messages[0]),
            json!({"t":"res","id":7,"status":200,"headers":[["content-type","application/json"]]})
        );
        assert_eq!(
            decode_frame(&messages[1].clone().into_data()),
            Some((7, BODY, &b"{}"[..]))
        );
        assert_eq!(text(&messages[2]), json!({"t":"end","id":7}));
    }

    #[tokio::test]
    async fn requests_over_the_relay_are_answered_and_ended() {
        use tokio::net::TcpListener;

        // A stand-in relay: asks for an unknown call (no body, so no `end` from it) and for a container the app
        // mustn't touch, then hangs up.
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        let relay = tokio::spawn(async move {
            let (stream, _) = listener.accept().await.unwrap();
            let mut socket = tokio_tungstenite::accept_async(stream).await.unwrap();
            let send = |value: Value| Message::Text(value.to_string().into());

            socket
                .send(send(json!({"t":"req","id":1,"method":"POST","path":"/rpc/nope","headers":[],"body":false})))
                .await
                .unwrap();
            socket
                .send(send(json!({"t":"req","id":2,"method":"POST","path":"/rpc/stop","headers":[],"body":true})))
                .await
                .unwrap();
            socket
                .send(Message::Binary(
                    encode_frame(2, BODY, br#"{"id":"postgres"}"#).into(),
                ))
                .await
                .unwrap();
            socket.send(send(json!({"t":"end","id":2}))).await.unwrap();

            let mut seen: HashMap<u32, (u16, Vec<u8>, bool)> = HashMap::new();

            while seen.values().filter(|(_, _, ended)| *ended).count() < 2 {
                match socket.next().await.unwrap().unwrap() {
                    Message::Text(text) => {
                        let value: Value = serde_json::from_str(&text).unwrap();
                        let id = value["id"].as_u64().unwrap_or(0) as u32;
                        let entry = seen.entry(id).or_default();

                        match value["t"].as_str().unwrap() {
                            "res" => entry.0 = value["status"].as_u64().unwrap() as u16,
                            "end" => entry.2 = true,
                            _ => {}
                        }
                    }
                    Message::Binary(bytes) => {
                        let (id, kind, payload) = decode_frame(&bytes).unwrap();
                        assert_eq!(kind, BODY);
                        seen.entry(id).or_default().1.extend_from_slice(payload);
                    }
                    _ => {}
                }
            }

            socket.close(None).await.unwrap();

            seen
        });

        let mut connected = false;
        let result = connection(&format!("ws://127.0.0.1:{port}/"), 4, || connected = true).await;
        let seen = relay.await.unwrap();

        assert!(connected);
        assert!(result.is_ok());
        assert_eq!(seen[&1].0, 404);
        assert_eq!(seen[&2].0, 403);
        assert!(String::from_utf8_lossy(&seen[&2].1).contains("onedrop-project-"));
    }
}
