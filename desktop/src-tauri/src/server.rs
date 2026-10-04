//! The server the app is signed in to, so the window can tell its pages from the app's own.

use std::sync::Mutex;
use tauri::{State, Url};

#[derive(Default)]
pub struct Server(Mutex<Option<String>>);

impl Server {
    /// Whether the address is on the signed-in server.
    pub fn is(&self, url: &Url) -> bool {
        let origin = url.origin().ascii_serialization();

        self.0.lock().unwrap().as_deref() == Some(origin.as_str())
    }
}

/// The app signed in to (or started on) this server, e.g. `https://onedrop.example.com`.
#[tauri::command]
pub fn use_server(server: State<'_, Server>, origin: String) -> Result<(), String> {
    let origin = Url::parse(&origin)
        .map_err(|error| error.to_string())?
        .origin()
        .ascii_serialization();

    *server.0.lock().unwrap() = Some(origin);

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_the_signed_in_servers_addresses_are_its() {
        let server = Server::default();
        *server.0.lock().unwrap() = Some("http://localhost:8100".into());

        assert!(server
            .is(&Url::parse("http://localhost:8100/projects/1/database/download?x=1").unwrap()));
        assert!(!server.is(&Url::parse("http://127.0.0.1:41000/").unwrap()));
        assert!(!server.is(&Url::parse("https://github.com/").unwrap()));
        assert!(!Server::default().is(&Url::parse("http://localhost:8100/").unwrap()));
    }
}
