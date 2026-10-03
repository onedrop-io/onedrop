//! The server the app is signed in to and its API token, kept in the system's keychain
//! (Keychain on macOS, Credential Manager on Windows, Secret Service on Linux), never on disk.

use keyring::Entry;
use serde::{Deserialize, Serialize};

const SERVICE: &str = "io.onedrop.desktop";
const ACCOUNT: &str = "session";

#[derive(Serialize, Deserialize)]
pub struct Session {
    /// The OneDrop install's address, e.g. `https://onedrop.example.com`.
    pub server: String,
    /// The API token the server gave the app when the user signed in (DESK-001).
    pub token: String,
}

fn entry() -> Result<Entry, String> {
    Entry::new(SERVICE, ACCOUNT).map_err(|error| error.to_string())
}

/// The saved session, or none when the app isn't signed in (or the keychain can't be read).
#[tauri::command]
pub fn session_load() -> Option<Session> {
    let secret = entry().ok()?.get_password().ok()?;

    serde_json::from_str(&secret).ok()
}

#[tauri::command]
pub fn session_save(session: Session) -> Result<(), String> {
    let secret = serde_json::to_string(&session).map_err(|error| error.to_string())?;

    entry()?
        .set_password(&secret)
        .map_err(|error| error.to_string())
}

#[tauri::command]
pub fn session_clear() -> Result<(), String> {
    match entry()?.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(error) => Err(error.to_string()),
    }
}
