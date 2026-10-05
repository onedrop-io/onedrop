//! What the app does for the signed-in user beyond showing pages (DESK-007..011): started once the window's pages are
//! signed in, stopped when the app signs out. Also the events the bridge (`window.onedropDesktop`) listens to.

use crate::api::Api;
use crate::{activity, forwards, network, relay, session, sign_in};
use serde::Serialize;
use std::sync::Mutex;
use std::time::Duration;
use tauri::{AppHandle, Emitter, Manager};

/// Something the bridge reports changed (a forward, the network, Docker, the device link).
pub const CHANGED: &str = "desktop://changed";

/// A page to show: from the menu bar, the shortcut or an `onedrop://` link.
pub const NAVIGATE: &str = "desktop://navigate";

/// The signed-in session the services run with.
#[derive(Default)]
pub struct Desktop {
    api: Mutex<Option<Api>>,
    device: Mutex<Option<Device>>,
    /// A page asked for before the window's pages could listen (an `onedrop://` link that started the app).
    pending: Mutex<Option<Navigation>>,
}

#[derive(Debug, Clone, Serialize)]
pub struct Device {
    pub id: u64,
    pub name: String,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Navigation {
    pub path: String,
    /// An element to focus once the page is there, e.g. the new-project prompt.
    pub focus: Option<String>,
}

#[derive(Serialize)]
pub struct Info {
    version: String,
    device: Device,
    pending: Option<Navigation>,
}

/// The signed-in server's API.
pub fn api(app: &AppHandle) -> Result<Api, String> {
    app.state::<Desktop>()
        .api
        .lock()
        .unwrap()
        .clone()
        .ok_or_else(|| "The app isn't signed in.".to_string())
}

/// This computer's device id (its sign-in's token id).
pub fn device_id(app: &AppHandle) -> Option<u64> {
    app.state::<Desktop>()
        .device
        .lock()
        .unwrap()
        .as_ref()
        .map(|device| device.id)
}

/// This computer's name, as the network's hosts are reached "through" it.
pub fn device_name(app: &AppHandle) -> String {
    app.state::<Desktop>()
        .device
        .lock()
        .unwrap()
        .as_ref()
        .map(|device| device.name.clone())
        .unwrap_or_else(sign_in::device_name)
}

/// Tell the bridge something changed.
pub fn changed(app: &AppHandle) {
    let _ = app.emit(CHANGED, ());
}

/// Show the main window.
pub fn show(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.unminimize();
        let _ = window.show();
        let _ = window.set_focus();
    }
}

/// Show a page in the main window: now if its pages are signed in and listening, else once they are.
pub fn navigate(app: &AppHandle, path: &str, focus: Option<&str>) {
    let navigation = Navigation {
        path: path.to_string(),
        focus: focus.map(String::from),
    };

    show(app);

    let state = app.state::<Desktop>();

    if state.api.lock().unwrap().is_some() {
        let _ = app.emit(NAVIGATE, navigation);
    } else {
        *state.pending.lock().unwrap() = Some(navigation);
    }
}

/// Stop everything that runs with the session (signing out).
pub fn stop(app: &AppHandle) {
    let state = app.state::<Desktop>();
    *state.api.lock().unwrap() = None;
    *state.device.lock().unwrap() = None;

    forwards::stop_all(app);
    network::stop_all(app);
    relay::stop(app);
    activity::stop(app);
    changed(app);
}

/// The window's pages are signed in: start (or keep) the services for the keychain's session, and say who this
/// computer is. Called on every page load; only a new sign-in restarts anything.
#[tauri::command]
pub async fn desktop_start(app: AppHandle) -> Result<Info, String> {
    let session = session::load().ok_or("The app isn't signed in.")?;
    let state = app.state::<Desktop>();
    let running = state
        .api
        .lock()
        .unwrap()
        .as_ref()
        .is_some_and(|api| api.is(&session));

    if !running {
        stop(&app);

        let api = Api::new(&session);
        // Who this computer is, and its link to the relay (DESK-010). Not reachable now: the token's id, and the
        // relay keeps trying.
        let link = tokio::time::timeout(Duration::from_secs(10), api.device())
            .await
            .ok()
            .and_then(Result::ok);
        let device = Device {
            id: link
                .as_ref()
                .and_then(|link| link.id)
                .or_else(|| api.token_id())
                .unwrap_or(0),
            name: link
                .as_ref()
                .and_then(|link| link.name.clone())
                .filter(|name| !name.trim().is_empty())
                .unwrap_or_else(sign_in::device_name),
        };

        *state.device.lock().unwrap() = Some(device);
        *state.api.lock().unwrap() = Some(api.clone());

        forwards::restore(&app, &api);
        network::restore(&app, &api);
        relay::start(&app, link);
        activity::start(&app);
    }

    let device = state
        .device
        .lock()
        .unwrap()
        .clone()
        .expect("a device once started");

    let pending = state.pending.lock().unwrap().take();

    Ok(Info {
        version: app.package_info().version.to_string(),
        device,
        pending,
    })
}

/// A delay that doubles after each failure, up to a limit, for reconnecting.
pub struct Backoff {
    next: Duration,
    limit: Duration,
}

impl Backoff {
    pub fn new(limit: Duration) -> Self {
        Self {
            next: Duration::from_secs(1),
            limit,
        }
    }

    /// The delay before the next try.
    pub fn next(&mut self) -> Duration {
        let delay = self.next;
        self.next = (self.next * 2).min(self.limit);

        delay
    }

    /// A connection that worked for a while starts the delays over.
    pub fn reset(&mut self) {
        self.next = Duration::from_secs(1);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reconnecting_waits_longer_each_time_up_to_a_limit() {
        let mut backoff = Backoff::new(Duration::from_secs(5));

        assert_eq!(
            (0..5).map(|_| backoff.next().as_secs()).collect::<Vec<_>>(),
            vec![1, 2, 4, 5, 5]
        );

        backoff.reset();
        assert_eq!(backoff.next().as_secs(), 1);
    }
}
