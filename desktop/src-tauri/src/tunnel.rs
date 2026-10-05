//! WebSockets to a sandbox's tunnel (DESK-007..009): where to connect for a ticket (the preview's address, or the
//! container's own port for a project on this computer), and piping bytes between a connection and a WebSocket.

use crate::api::{use_tls, Ticket};
use crate::docker;
use futures_util::{SinkExt, StreamExt};
use rustls_platform_verifier::ConfigVerifierExt;
use std::sync::{Arc, OnceLock};
use std::time::Duration;
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};
use tokio::net::TcpStream;
use tokio_tungstenite::tungstenite::client::IntoClientRequest;
use tokio_tungstenite::tungstenite::handshake::client::{Request, Response};
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::{Connector, MaybeTlsStream, WebSocketStream};
use url::Url;

pub type Socket = WebSocketStream<MaybeTlsStream<TcpStream>>;

/// How long opening a WebSocket may take.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(20);

/// TLS that trusts what the system trusts (a company's own certificate authority too).
fn connector() -> Connector {
    static CONFIG: OnceLock<Option<Arc<rustls::ClientConfig>>> = OnceLock::new();

    let config = CONFIG.get_or_init(|| {
        use_tls();
        rustls::ClientConfig::with_platform_verifier()
            .ok()
            .map(Arc::new)
    });

    match config {
        Some(config) => Connector::Rustls(config.clone()),
        None => Connector::Plain,
    }
}

/// Open a WebSocket for a request (its headers included).
pub async fn connect_request(request: Request) -> Result<(Socket, Response), String> {
    let secure = request.uri().scheme_str() == Some("wss");
    let connector = if secure { Some(connector()) } else { None };
    let connecting =
        tokio_tungstenite::connect_async_tls_with_config(request, None, true, connector);

    match tokio::time::timeout(CONNECT_TIMEOUT, connecting).await {
        Err(_) => Err("The sandbox didn't answer in time.".into()),
        Ok(Err(tokio_tungstenite::tungstenite::Error::Http(response))) => {
            let body = response
                .body()
                .as_ref()
                .map(|body| String::from_utf8_lossy(body).trim().to_string())
                .filter(|body| !body.is_empty() && body.len() < 300);

            Err(match body {
                Some(body) => format!(
                    "The sandbox refused the connection ({}): {body}",
                    response.status()
                ),
                None => format!(
                    "The sandbox refused the connection ({}).",
                    response.status()
                ),
            })
        }
        Ok(Err(error)) => Err(format!("Couldn't connect to the sandbox: {error}")),
        Ok(Ok(connected)) => Ok(connected),
    }
}

/// Open a WebSocket.
pub async fn connect(url: &str) -> Result<Socket, String> {
    let request = url
        .into_client_request()
        .map_err(|error| error.to_string())?;

    Ok(connect_request(request).await?.0)
}

/// The tunnel's address on this computer, for a project in its Docker.
pub fn local_url(port: u16, ticket: &str) -> String {
    let mut url = Url::parse(&format!("ws://127.0.0.1:{port}/__onedrop/tunnel")).expect("a URL");
    url.query_pairs_mut().append_pair("ticket", ticket);

    url.into()
}

/// Where to connect for a ticket: its URL, or for a project on this computer the container's published proxy port.
pub async fn url_for(ticket: &Ticket, this_device: Option<u64>) -> Result<String, String> {
    if let Some(url) = &ticket.url {
        return Ok(url.clone());
    }

    let Some(device) = &ticket.device else {
        return Err("The server gave no address for the sandbox.".into());
    };

    if this_device.is_some_and(|id| id != device.id) {
        return Err("This project runs on another computer.".into());
    }

    Ok(local_url(
        docker::proxy_port(&device.container).await?,
        &ticket.ticket,
    ))
}

/// The address to dial one network connection on (DESK-009): the control connection's, with `dial` and `token`
/// instead of the ticket.
pub fn dial_url(control: &str, id: &str, token: &str) -> Result<String, String> {
    let mut url = Url::parse(control).map_err(|error| error.to_string())?;
    url.set_query(None);
    url.query_pairs_mut()
        .append_pair("dial", id)
        .append_pair("token", token);

    Ok(url.into())
}

/// Pipe bytes between a connection (a TCP socket, or stdin and stdout) and a tunnel WebSocket, as binary messages,
/// until either side closes; then the other is closed too.
pub async fn pipe<R, W>(mut reader: R, mut writer: W, socket: Socket)
where
    R: AsyncRead + Unpin,
    W: AsyncWrite + Unpin,
{
    let (mut sink, mut stream) = socket.split();

    let up = async {
        let mut buffer = vec![0u8; 64 * 1024];

        loop {
            match reader.read(&mut buffer).await {
                Ok(0) | Err(_) => break,
                Ok(read) => {
                    if sink
                        .send(Message::Binary(buffer[..read].to_vec().into()))
                        .await
                        .is_err()
                    {
                        return;
                    }
                }
            }
        }

        let _ = sink.send(Message::Close(None)).await;
    };

    let down = async {
        while let Some(Ok(message)) = stream.next().await {
            match message {
                Message::Binary(bytes) => {
                    if writer.write_all(&bytes).await.is_err() || writer.flush().await.is_err() {
                        break;
                    }
                }
                Message::Text(text) => {
                    if writer.write_all(text.as_bytes()).await.is_err() {
                        break;
                    }
                }
                Message::Close(_) => break,
                _ => {}
            }
        }

        let _ = writer.shutdown().await;
    };

    tokio::pin!(up, down);

    tokio::select! {
        _ = &mut down => {}
        // The connection ended: what the sandbox still sends arrives until it closes too.
        _ = &mut up => {
            let _ = tokio::time::timeout(Duration::from_secs(30), &mut down).await;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::api::TicketDevice;

    #[test]
    fn a_project_on_this_computer_is_reached_on_its_own_port() {
        assert_eq!(
            local_url(55012, "abc.def"),
            "ws://127.0.0.1:55012/__onedrop/tunnel?ticket=abc.def"
        );
    }

    #[test]
    fn dials_go_where_the_control_connection_went() {
        assert_eq!(
            dial_url(
                "wss://preview-12.onedrop.io/__onedrop/tunnel?ticket=xyz",
                "6f1c-22",
                "t0k+n"
            )
            .unwrap(),
            "wss://preview-12.onedrop.io/__onedrop/tunnel?dial=6f1c-22&token=t0k%2Bn"
        );
    }

    #[tokio::test]
    async fn a_hosted_ticket_uses_its_url() {
        let ticket = Ticket {
            url: Some("wss://p.example/__onedrop/tunnel?ticket=t".into()),
            ticket: "t".into(),
            hosts: vec![],
            device: None,
        };

        assert_eq!(
            url_for(&ticket, Some(4)).await.unwrap(),
            "wss://p.example/__onedrop/tunnel?ticket=t"
        );
    }

    #[tokio::test]
    async fn another_computers_project_is_refused() {
        let ticket = Ticket {
            url: None,
            ticket: "t".into(),
            hosts: vec![],
            device: Some(TicketDevice {
                id: 5,
                container: "onedrop-project-1-a".into(),
            }),
        };

        assert_eq!(
            url_for(&ticket, Some(4)).await.unwrap_err(),
            "This project runs on another computer."
        );
    }

    #[tokio::test]
    async fn bytes_pass_both_ways_and_closing_closes_the_other_side() {
        use tokio::net::TcpListener;

        // A stand-in tunnel that echoes binary messages back.
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();

        tokio::spawn(async move {
            let (stream, _) = listener.accept().await.unwrap();
            let mut socket = tokio_tungstenite::accept_async(stream).await.unwrap();

            while let Some(Ok(message)) = socket.next().await {
                if message.is_binary() {
                    socket.send(message).await.unwrap();
                } else if message.is_close() {
                    break;
                }
            }
        });

        let socket = connect(&format!("ws://127.0.0.1:{port}/")).await.unwrap();
        let (mut ours, theirs) = tokio::io::duplex(1024);
        let (reader, writer) = tokio::io::split(theirs);
        let piping = tokio::spawn(pipe(reader, writer, socket));

        ours.write_all(b"hello").await.unwrap();
        let mut echoed = [0u8; 5];
        ours.read_exact(&mut echoed).await.unwrap();
        assert_eq!(&echoed, b"hello");

        ours.shutdown().await.unwrap();
        tokio::time::timeout(Duration::from_secs(5), piping)
            .await
            .unwrap()
            .unwrap();
    }

    /// HMAC-SHA256, to sign a ticket the way the server does (DesktopTunnel::sign).
    fn hmac(key: &[u8], message: &[u8]) -> Vec<u8> {
        use sha2::{Digest, Sha256};

        let mut block = [0u8; 64];
        block[..key.len()].copy_from_slice(key);
        let inner: Vec<u8> = block.iter().map(|byte| byte ^ 0x36).collect();
        let outer: Vec<u8> = block.iter().map(|byte| byte ^ 0x5c).collect();
        let inner_hash = Sha256::new()
            .chain_update(&inner)
            .chain_update(message)
            .finalize();

        Sha256::new()
            .chain_update(&outer)
            .chain_update(inner_hash)
            .finalize()
            .to_vec()
    }

    fn free_port() -> u16 {
        std::net::TcpListener::bind("127.0.0.1:0")
            .unwrap()
            .local_addr()
            .unwrap()
            .port()
    }

    /// The sandbox's real tunnel (docker/sandbox/tunnel.mjs) and this client agree: a forward ticket the server would
    /// sign opens a WebSocket that carries bytes to the port, and a bad one is refused. Skipped without Node.
    #[tokio::test]
    async fn the_client_forwards_through_the_sandboxs_real_tunnel() {
        use base64::engine::general_purpose::URL_SAFE_NO_PAD;
        use base64::Engine;
        use tokio::net::TcpListener;

        if std::process::Command::new("node")
            .arg("--version")
            .output()
            .is_err()
        {
            return;
        }

        let home = std::env::temp_dir().join(format!("onedrop-tunnel-{}", rand::random::<u32>()));
        std::fs::create_dir_all(home.join(".onedrop")).unwrap();
        std::fs::write(home.join(".onedrop/tunnel-key"), "test-key").unwrap();
        let (tunnel_port, proxy_port) = (free_port(), free_port());
        let script = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("../../docker/sandbox/tunnel.mjs");
        let mut tunnel = tokio::process::Command::new("node")
            .arg(script)
            .env("HOME", &home)
            .env("TUNNEL_PORT", tunnel_port.to_string())
            .env("TUNNEL_PROXY_PORT", proxy_port.to_string())
            .kill_on_drop(true)
            .spawn()
            .unwrap();

        // An echo server for the forward to reach.
        let echo = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let echo_port = echo.local_addr().unwrap().port();
        tokio::spawn(async move {
            while let Ok((mut socket, _)) = echo.accept().await {
                tokio::spawn(async move {
                    let (mut reader, mut writer) = socket.split();
                    let _ = tokio::io::copy(&mut reader, &mut writer).await;
                });
            }
        });

        for _ in 0..100 {
            if TcpStream::connect(("127.0.0.1", tunnel_port)).await.is_ok() {
                break;
            }
            tokio::time::sleep(Duration::from_millis(50)).await;
        }

        let expires = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap()
            .as_secs()
            + 60;
        let body = URL_SAFE_NO_PAD.encode(format!(
            r#"{{"p":"forward","port":{echo_port},"exp":{expires},"u":1}}"#
        ));
        let ticket = format!(
            "{body}.{}",
            URL_SAFE_NO_PAD.encode(hmac(b"test-key", body.as_bytes()))
        );

        let mut socket = connect(&local_url(tunnel_port, &ticket))
            .await
            .expect("the tunnel opens");
        socket
            .send(Message::Binary(b"hello through the tunnel".to_vec().into()))
            .await
            .unwrap();

        let mut received = Vec::new();
        while received.len() < 24 {
            match tokio::time::timeout(Duration::from_secs(5), socket.next()).await {
                Ok(Some(Ok(Message::Binary(bytes)))) => received.extend_from_slice(&bytes),
                Ok(Some(Ok(_))) => continue,
                other => panic!("no echo: {other:?}"),
            }
        }
        assert_eq!(received, b"hello through the tunnel");

        let refused = connect(&local_url(tunnel_port, &format!("{body}.bad"))).await;
        assert!(refused.unwrap_err().contains("refused"));

        tunnel.kill().await.ok();
        std::fs::remove_dir_all(home).ok();
    }
}
