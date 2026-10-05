//! A sandbox's ports on this computer (DESK-007): a listener on 127.0.0.1 per forward, and for each connection to
//! it a fresh ticket and a WebSocket of its own to the sandbox's tunnel. Forwards are kept on this computer and come
//! back when the app starts.

use crate::api::{Api, Purpose};
use crate::desktop::{self, changed};
use crate::prefs::{Prefs, SavedForward};
use crate::tunnel;
use serde::Serialize;
use std::sync::Mutex;
use tauri::async_runtime::JoinHandle;
use tauri::{AppHandle, Manager};
use tokio::net::{TcpListener, TcpStream};

struct Forward {
    server: String,
    project_id: u64,
    remote_port: u16,
    local_port: u16,
    state: &'static str,
    error: Option<String>,
    task: Option<JoinHandle<()>>,
}

/// A forward, as the bridge shows it.
#[derive(Debug, Clone, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ForwardView {
    project_id: u64,
    remote_port: u16,
    local_port: u16,
    state: &'static str,
    error: Option<String>,
}

impl Forward {
    fn view(&self) -> ForwardView {
        ForwardView {
            project_id: self.project_id,
            remote_port: self.remote_port,
            local_port: self.local_port,
            state: self.state,
            error: self.error.clone(),
        }
    }
}

#[derive(Default)]
pub struct Forwards(Mutex<Vec<Forward>>);

/// The local port to listen on: the one asked for, else the sandbox's own number when it's free here, else any
/// free one (0).
pub fn choose_port(remote: u16, requested: Option<u16>, free: impl Fn(u16) -> bool) -> u16 {
    match requested {
        Some(port) if port != 0 => port,
        _ if free(remote) => remote,
        _ => 0,
    }
}

fn is_free(port: u16) -> bool {
    std::net::TcpListener::bind(("127.0.0.1", port)).is_ok()
}

/// Change a forward's state and tell the bridge.
fn set(
    app: &AppHandle,
    project_id: u64,
    remote_port: u16,
    state: &'static str,
    error: Option<String>,
) {
    let forwards = app.state::<Forwards>();
    let mut list = forwards.0.lock().unwrap();

    if let Some(forward) = list
        .iter_mut()
        .find(|forward| forward.project_id == project_id && forward.remote_port == remote_port)
    {
        if forward.state == state && forward.error == error {
            return;
        }

        forward.state = state;
        forward.error = error;
    } else {
        return;
    }

    drop(list);
    changed(app);
}

/// One connection to the local port: a ticket, a WebSocket, bytes both ways.
async fn carry(app: AppHandle, api: Api, project_id: u64, remote_port: u16, connection: TcpStream) {
    let opened = async {
        let ticket = api
            .ticket(project_id, Purpose::Forward(remote_port))
            .await?;
        let url = tunnel::url_for(&ticket, desktop::device_id(&app)).await?;

        tunnel::connect(&url).await
    };

    match opened.await {
        Ok(socket) => {
            set(&app, project_id, remote_port, "listening", None);
            let _ = connection.set_nodelay(true);
            let (reader, writer) = connection.into_split();
            tunnel::pipe(reader, writer, socket).await;
        }
        Err(error) => set(&app, project_id, remote_port, "error", Some(error)),
    }
}

/// Listen and carry each connection.
async fn listen(
    app: AppHandle,
    api: Api,
    project_id: u64,
    remote_port: u16,
    listener: TcpListener,
) {
    loop {
        match listener.accept().await {
            Ok((connection, _)) => {
                tauri::async_runtime::spawn(carry(
                    app.clone(),
                    api.clone(),
                    project_id,
                    remote_port,
                    connection,
                ));
            }
            Err(error) => {
                set(
                    &app,
                    project_id,
                    remote_port,
                    "error",
                    Some(error.to_string()),
                );
                tokio::time::sleep(std::time::Duration::from_secs(1)).await;
            }
        }
    }
}

/// Start forwarding; a forward already there is kept unless it should move to another local port.
async fn start(
    app: &AppHandle,
    api: &Api,
    project_id: u64,
    remote_port: u16,
    local_port: Option<u16>,
) -> Result<ForwardView, String> {
    if remote_port == 0 {
        return Err("Choose a port to forward.".into());
    }

    {
        let forwards = app.state::<Forwards>();
        let mut list = forwards.0.lock().unwrap();

        if let Some(at) = list.iter().position(|forward| {
            forward.project_id == project_id && forward.remote_port == remote_port
        }) {
            if local_port.is_none_or(|port| port == list[at].local_port) && list[at].task.is_some()
            {
                return Ok(list[at].view());
            }

            if let Some(task) = list.remove(at).task {
                task.abort();
            }
        }
    }

    let port = choose_port(remote_port, local_port, is_free);
    let listener =
        TcpListener::bind(("127.0.0.1", port))
            .await
            .map_err(|error| match local_port {
                Some(port) => format!("Port {port} is already in use on this computer ({error})."),
                None => error.to_string(),
            })?;
    let local_port = listener
        .local_addr()
        .map_err(|error| error.to_string())?
        .port();
    let task = tauri::async_runtime::spawn(listen(
        app.clone(),
        api.clone(),
        project_id,
        remote_port,
        listener,
    ));
    let forward = Forward {
        server: api.server.clone(),
        project_id,
        remote_port,
        local_port,
        state: "listening",
        error: None,
        task: Some(task),
    };
    let view = forward.view();

    app.state::<Forwards>().0.lock().unwrap().push(forward);
    save(app);
    changed(app);

    Ok(view)
}

/// Keep the forwards of the signed-in server, replacing what was kept for it.
fn save(app: &AppHandle) {
    let Ok(api) = desktop::api(app) else {
        return;
    };
    let ours: Vec<SavedForward> = app
        .state::<Forwards>()
        .0
        .lock()
        .unwrap()
        .iter()
        .filter(|forward| forward.server == api.server)
        .map(|forward| SavedForward {
            server: forward.server.clone(),
            project_id: forward.project_id,
            remote_port: forward.remote_port,
            local_port: forward.local_port,
        })
        .collect();

    app.state::<Prefs>().update(app, |values| {
        values.forwards.retain(|saved| saved.server != api.server);
        values.forwards.extend(ours);
    });
}

/// Bring back the forwards kept for this server (after signing in, at startup), on the same local ports where they
/// can be.
pub fn restore(app: &AppHandle, api: &Api) {
    let saved = app.state::<Prefs>().get(app).forwards;

    for forward in saved
        .into_iter()
        .filter(|forward| forward.server == api.server)
    {
        let app = app.clone();
        let api = api.clone();

        tauri::async_runtime::spawn(async move {
            let local = if is_free(forward.local_port) {
                Some(forward.local_port)
            } else {
                None
            };

            if let Err(error) =
                start(&app, &api, forward.project_id, forward.remote_port, local).await
            {
                eprintln!(
                    "[onedrop] forward {}:{} not restored: {error}",
                    forward.project_id, forward.remote_port
                );
            }
        });
    }
}

/// Stop every forward, keeping them for next time (signing out).
pub fn stop_all(app: &AppHandle) {
    for forward in app.state::<Forwards>().0.lock().unwrap().drain(..) {
        if let Some(task) = forward.task {
            task.abort();
        }
    }
}

/// How many forwards are on (to say what quitting stops).
pub fn active_count(app: &AppHandle) -> usize {
    app.state::<Forwards>().0.lock().unwrap().len()
}

#[tauri::command]
pub fn forwards_list(app: AppHandle, project_id: Option<u64>) -> Vec<ForwardView> {
    app.state::<Forwards>()
        .0
        .lock()
        .unwrap()
        .iter()
        .filter(|forward| project_id.is_none_or(|id| id == forward.project_id))
        .map(Forward::view)
        .collect()
}

#[tauri::command]
pub async fn forwards_start(
    app: AppHandle,
    project_id: u64,
    remote_port: u16,
    local_port: Option<u16>,
) -> Result<ForwardView, String> {
    let api = desktop::api(&app)?;

    start(&app, &api, project_id, remote_port, local_port).await
}

#[tauri::command]
pub fn forwards_stop(app: AppHandle, project_id: u64, remote_port: u16) {
    let removed: Vec<Forward> = {
        let forwards = app.state::<Forwards>();
        let mut list = forwards.0.lock().unwrap();
        let (gone, kept) = list.drain(..).partition(|forward| {
            forward.project_id == project_id && forward.remote_port == remote_port
        });
        *list = kept;

        gone
    };

    for forward in &removed {
        if let Some(task) = &forward.task {
            task.abort();
        }
    }

    save(&app);
    changed(&app);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_local_port_is_the_same_number_when_free() {
        assert_eq!(choose_port(5432, None, |_| true), 5432);
        assert_eq!(choose_port(5432, None, |_| false), 0);
        assert_eq!(choose_port(5432, Some(15432), |_| false), 15432);
        assert_eq!(choose_port(5432, Some(0), |_| true), 5432);
    }

    #[test]
    fn a_port_in_use_isnt_free() {
        let taken = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let port = taken.local_addr().unwrap().port();

        assert!(!is_free(port));
    }
}
