//! Sharing this computer's network with a project (DESK-009): while it's on, a control WebSocket to the sandbox's
//! tunnel says which hosts this computer will dial, and the tunnel asks for each connection to one of them. Only the
//! project's own list is ever dialled, only the hosts on it the user agreed to here, and only for projects the user
//! turned it on for here.

use crate::api::{Api, Host, Purpose};
use crate::desktop::{self, changed, Backoff};
use crate::prefs::{Prefs, SharedNetwork};
use crate::tunnel;
use futures_util::{SinkExt, StreamExt};
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tauri::async_runtime::JoinHandle;
use tauri::{AppHandle, Manager};
use tokio::net::TcpStream;
use tokio::sync::mpsc;
use tokio_tungstenite::tungstenite::Message;

/// How often the project's list of hosts is fetched again.
const REFRESH: Duration = Duration::from_secs(60);

/// The tunnel pings every 25 seconds: this long without a word, the connection is gone.
const SILENCE: Duration = Duration::from_secs(80);

struct Sharing {
    state: &'static str,
    error: Option<String>,
    task: JoinHandle<()>,
}

#[derive(Default)]
pub struct Networks(Mutex<HashMap<u64, Sharing>>);

/// The network's status for the bridge.
#[derive(Debug, Clone, Serialize)]
pub struct NetworkStatus {
    sharing: bool,
    state: &'static str,
    error: Option<String>,
}

/// A message from the tunnel.
#[derive(Debug, Deserialize)]
#[serde(tag = "type", rename_all = "lowercase")]
enum FromTunnel {
    Listening,
    Open {
        id: String,
        host: String,
        port: u16,
        token: String,
    },
    #[serde(other)]
    Other,
}

/// The hosts on the project's list this computer agreed to dial (DESK-009), and how many others wait for the user.
pub fn agreed(listed: &[Host], approved: &[Host]) -> (Vec<Host>, usize) {
    let (agreed, waiting): (Vec<Host>, Vec<Host>) = listed
        .iter()
        .cloned()
        .partition(|host| allowed(approved, &host.host, host.port));

    (agreed, waiting.len())
}

/// The hosts the user agreed to for this project on this computer.
fn approved(app: &AppHandle, server: &str, project_id: u64) -> Vec<Host> {
    app.state::<Prefs>()
        .get(app)
        .networks
        .into_iter()
        .find(|shared| shared.server == server && shared.project_id == project_id)
        .map(|shared| shared.approved)
        .unwrap_or_default()
}

/// What the status says while hosts on the list wait for the user to agree to them.
fn waiting_note(waiting: usize) -> Option<String> {
    match waiting {
        0 => None,
        1 => Some("1 new host isn't shared until you turn sharing off and on again.".into()),
        n => Some(format!("{n} new hosts aren't shared until you turn sharing off and on again.")),
    }
}

/// The `hosts` message: what this computer will dial.
pub fn hosts_message(through: &str, hosts: &[Host]) -> String {
    json!({ "type": "hosts", "through": through, "hosts": hosts }).to_string()
}

/// The `refused` message for a connection this computer won't (or can't) make.
pub fn refused_message(id: &str, reason: &str) -> String {
    json!({ "type": "refused", "id": id, "reason": reason }).to_string()
}

/// Whether a host is on the project's list.
pub fn allowed(hosts: &[Host], host: &str, port: u16) -> bool {
    hosts
        .iter()
        .any(|allowed| allowed.port == port && allowed.host.eq_ignore_ascii_case(host))
}

fn set(app: &AppHandle, project_id: u64, state: &'static str, error: Option<String>) {
    let networks = app.state::<Networks>();
    let mut map = networks.0.lock().unwrap();

    match map.get_mut(&project_id) {
        Some(sharing) if sharing.state != state || sharing.error != error => {
            sharing.state = state;
            sharing.error = error;
        }
        _ => return,
    }

    drop(map);
    changed(app);
}

/// Dial a host the tunnel asked for and pipe it through a WebSocket of its own.
async fn dial(
    control: String,
    id: String,
    host: String,
    port: u16,
    token: String,
    refuse: mpsc::UnboundedSender<String>,
) {
    let connection = tokio::time::timeout(
        Duration::from_secs(10),
        TcpStream::connect((host.as_str(), port)),
    )
    .await;
    let connection = match connection {
        Ok(Ok(connection)) => connection,
        Ok(Err(error)) => {
            let _ = refuse.send(refused_message(&id, &format!("{host}:{port}: {error}")));
            return;
        }
        Err(_) => {
            let _ = refuse.send(refused_message(
                &id,
                &format!("{host}:{port} didn't answer"),
            ));
            return;
        }
    };
    let socket = match tunnel::dial_url(&control, &id, &token) {
        Ok(url) => tunnel::connect(&url).await,
        Err(error) => Err(error),
    };

    match socket {
        Ok(socket) => {
            let _ = connection.set_nodelay(true);
            let (reader, writer) = connection.into_split();
            tunnel::pipe(reader, writer, socket).await;
        }
        Err(error) => {
            let _ = refuse.send(refused_message(&id, &error));
        }
    }
}

/// One control connection, until it closes or fails.
async fn control(app: &AppHandle, api: &Api, project_id: u64) -> Result<(), String> {
    let ticket = api.ticket(project_id, Purpose::Network).await?;
    let url = tunnel::url_for(&ticket, desktop::device_id(app)).await?;
    let socket = tunnel::connect(&url).await?;
    let through = desktop::device_name(app);
    let (agreed_hosts, waiting) = agreed(&ticket.hosts, &approved(app, &api.server, project_id));
    let hosts = Arc::new(Mutex::new(agreed_hosts.clone()));
    let note = Arc::new(Mutex::new(waiting_note(waiting)));
    let (mut sink, mut stream) = socket.split();
    let (outgoing, mut queued) = mpsc::unbounded_channel::<String>();

    sink.send(Message::Text(hosts_message(&through, &agreed_hosts).into()))
        .await
        .map_err(|error| error.to_string())?;

    let mut refresh = tokio::time::interval_at(tokio::time::Instant::now() + REFRESH, REFRESH);
    let mut heard = Instant::now();
    let mut check = tokio::time::interval(Duration::from_secs(10));

    loop {
        tokio::select! {
            message = stream.next() => {
                heard = Instant::now();

                match message {
                    Some(Ok(Message::Text(text))) => match serde_json::from_str::<FromTunnel>(&text) {
                        Ok(FromTunnel::Listening) => set(app, project_id, "connected", note.lock().unwrap().clone()),
                        Ok(FromTunnel::Open { id, host, port, token }) => {
                            if allowed(&hosts.lock().unwrap(), &host, port) {
                                tauri::async_runtime::spawn(dial(url.clone(), id, host, port, token, outgoing.clone()));
                            } else {
                                let _ = outgoing.send(refused_message(&id, "That host isn't on the project's list."));
                            }
                        }
                        _ => {}
                    },
                    Some(Ok(Message::Close(_))) | None => return Ok(()),
                    Some(Err(error)) => return Err(error.to_string()),
                    Some(Ok(_)) => {}
                }
            }
            Some(text) = queued.recv() => {
                sink.send(Message::Text(text.into())).await.map_err(|error| error.to_string())?;
            }
            _ = refresh.tick() => {
                // The project's list may have changed in the web app.
                if let Ok(fresh) = api.ticket(project_id, Purpose::Network).await {
                    let (agreed_hosts, waiting) = agreed(&fresh.hosts, &approved(app, &api.server, project_id));
                    *hosts.lock().unwrap() = agreed_hosts.clone();
                    *note.lock().unwrap() = waiting_note(waiting);
                    sink.send(Message::Text(hosts_message(&through, &agreed_hosts).into()))
                        .await
                        .map_err(|error| error.to_string())?;
                }
            }
            _ = check.tick() => {
                if heard.elapsed() > SILENCE {
                    return Err("The sandbox stopped answering.".into());
                }
            }
        }
    }
}

/// Keep the control connection open while sharing is on: reconnect with a fresh ticket, waiting longer each time.
async fn keep_sharing(app: AppHandle, api: Api, project_id: u64) {
    let mut backoff = Backoff::new(Duration::from_secs(60));

    loop {
        let started = Instant::now();
        let result = control(&app, &api, project_id).await;

        if started.elapsed() > Duration::from_secs(60) {
            backoff.reset();
        }

        match result {
            Ok(()) => set(&app, project_id, "connecting", None),
            Err(error) => set(&app, project_id, "error", Some(error)),
        }

        tokio::time::sleep(backoff.next()).await;
    }
}

fn share(app: &AppHandle, api: &Api, project_id: u64) {
    let networks = app.state::<Networks>();
    let mut map = networks.0.lock().unwrap();

    if map.contains_key(&project_id) {
        return;
    }

    let task = tauri::async_runtime::spawn(keep_sharing(app.clone(), api.clone(), project_id));

    map.insert(
        project_id,
        Sharing {
            state: "connecting",
            error: None,
            task,
        },
    );
}

fn status(app: &AppHandle, project_id: u64) -> NetworkStatus {
    match app.state::<Networks>().0.lock().unwrap().get(&project_id) {
        Some(sharing) => NetworkStatus {
            sharing: true,
            state: sharing.state,
            error: sharing.error.clone(),
        },
        None => NetworkStatus {
            sharing: false,
            state: "off",
            error: None,
        },
    }
}

/// Share again with the projects kept for this server.
pub fn restore(app: &AppHandle, api: &Api) {
    for shared in app.state::<Prefs>().get(app).networks {
        if shared.server == api.server {
            share(app, api, shared.project_id);
        }
    }
}

/// Stop sharing with every project, keeping the choice for next time (signing out).
pub fn stop_all(app: &AppHandle) {
    for (_, sharing) in app.state::<Networks>().0.lock().unwrap().drain() {
        sharing.task.abort();
    }
}

/// How many projects this computer shares its network with now.
pub fn active_count(app: &AppHandle) -> usize {
    app.state::<Networks>().0.lock().unwrap().len()
}

#[tauri::command]
pub fn network_status(app: AppHandle, project_id: u64) -> NetworkStatus {
    status(&app, project_id)
}

/// Turn sharing on (agreeing to the project's list as it is now, and starting again with it) or off.
#[tauri::command]
pub async fn network_set_sharing(
    app: AppHandle,
    project_id: u64,
    sharing: bool,
) -> Result<NetworkStatus, String> {
    let api = desktop::api(&app)?;
    let approved = if sharing {
        api.ticket(project_id, Purpose::Network)
            .await
            .map_err(|error| error.to_string())?
            .hosts
    } else {
        vec![]
    };

    app.state::<Prefs>().update(&app, |values| {
        values
            .networks
            .retain(|shared| !(shared.server == api.server && shared.project_id == project_id));

        if sharing {
            values.networks.push(SharedNetwork {
                server: api.server.clone(),
                project_id,
                approved,
            });
        }
    });

    if let Some(stopped) = app
        .state::<Networks>()
        .0
        .lock()
        .unwrap()
        .remove(&project_id)
    {
        stopped.task.abort();
    }

    if sharing {
        share(&app, &api, project_id);
    }

    changed(&app);

    Ok(status(&app, project_id))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn host(host: &str, port: u16) -> Host {
        Host {
            host: host.into(),
            port,
        }
    }

    #[test]
    fn hosts_added_since_the_user_agreed_wait_for_them() {
        let listed = vec![host("db.internal", 5432), host("router.lan", 80)];
        let approved = vec![host("DB.internal", 5432), host("gone.internal", 22)];

        assert_eq!(agreed(&listed, &approved), (vec![host("db.internal", 5432)], 1));
        assert_eq!(agreed(&listed, &[]), (vec![], 2));
        assert_eq!(waiting_note(0), None);
        assert_eq!(
            waiting_note(2).as_deref(),
            Some("2 new hosts aren't shared until you turn sharing off and on again.")
        );
    }

    #[test]
    fn only_listed_hosts_are_dialled() {
        let hosts = vec![host("db.internal", 5432), host("10.0.0.7", 443)];

        assert!(allowed(&hosts, "db.internal", 5432));
        assert!(allowed(&hosts, "DB.Internal", 5432));
        assert!(!allowed(&hosts, "db.internal", 22));
        assert!(!allowed(&hosts, "169.254.169.254", 80));
    }

    #[test]
    fn messages_follow_the_contract() {
        let hosts: serde_json::Value = serde_json::from_str(&hosts_message(
            "Jeff's MacBook",
            &[host("db.internal", 5432)],
        ))
        .unwrap();

        assert_eq!(
            hosts,
            json!({"type":"hosts","through":"Jeff's MacBook","hosts":[{"host":"db.internal","port":5432}]})
        );
        assert_eq!(
            serde_json::from_str::<serde_json::Value>(&refused_message("x", "no")).unwrap(),
            json!({"type":"refused","id":"x","reason":"no"})
        );

        let open: FromTunnel = serde_json::from_str(
            r#"{"type":"open","id":"u","host":"db.internal","port":5432,"token":"t"}"#,
        )
        .unwrap();
        assert!(matches!(open, FromTunnel::Open { port: 5432, .. }));
        assert!(matches!(
            serde_json::from_str::<FromTunnel>(
                r#"{"type":"listening","hosts":[],"proxy":"http://127.0.0.1:7690"}"#
            )
            .unwrap(),
            FromTunnel::Listening
        ));
    }
}
