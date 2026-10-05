//! What the app keeps on this computer between runs (DESK-007, DESK-009, DESK-011): the user's forwards, the projects
//! it shares its network with, and whether the new-project shortcut is on. A small JSON file in the app's data folder;
//! nothing secret goes in it.

use crate::api::Host;
use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use std::sync::Mutex;
use tauri::{AppHandle, Manager};

/// A forward to restore at startup.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct SavedForward {
    pub server: String,
    pub project_id: u64,
    pub remote_port: u16,
    pub local_port: u16,
}

/// A project this computer shares its network with, and the hosts the user agreed to here: the project's list when
/// they turned sharing on (or saved the list in this app). Hosts added to the list since, by anyone, aren't dialled
/// until they agree again.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct SharedNetwork {
    pub server: String,
    pub project_id: u64,
    #[serde(default)]
    pub approved: Vec<Host>,
}

fn yes() -> bool {
    true
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Values {
    #[serde(default)]
    pub forwards: Vec<SavedForward>,
    #[serde(default)]
    pub networks: Vec<SharedNetwork>,
    #[serde(default = "yes")]
    pub shortcut: bool,
}

impl Default for Values {
    fn default() -> Self {
        Self {
            forwards: vec![],
            networks: vec![],
            shortcut: true,
        }
    }
}

/// The settings, loaded once and written on every change.
#[derive(Default)]
pub struct Prefs {
    values: Mutex<Option<Values>>,
}

fn path(app: &AppHandle) -> Option<PathBuf> {
    Some(app.path().app_data_dir().ok()?.join("desktop.json"))
}

impl Prefs {
    /// Read the settings.
    pub fn get(&self, app: &AppHandle) -> Values {
        let mut values = self.values.lock().unwrap();

        values
            .get_or_insert_with(|| {
                path(app)
                    .and_then(|path| std::fs::read(path).ok())
                    .and_then(|bytes| serde_json::from_slice(&bytes).ok())
                    .unwrap_or_default()
            })
            .clone()
    }

    /// Change the settings and save them (a new file moved over the old, so a crash never leaves half of one).
    pub fn update(&self, app: &AppHandle, change: impl FnOnce(&mut Values)) {
        let mut next = self.get(app);
        change(&mut next);
        *self.values.lock().unwrap() = Some(next.clone());

        let Some(path) = path(app) else {
            return;
        };

        if let Some(folder) = path.parent() {
            let _ = std::fs::create_dir_all(folder);
        }

        let temporary = path.with_extension("json.tmp");

        if let Ok(bytes) = serde_json::to_vec_pretty(&next) {
            if std::fs::write(&temporary, bytes).is_ok() {
                let _ = std::fs::rename(&temporary, &path);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_shortcut_is_on_unless_turned_off() {
        let values: Values = serde_json::from_str("{}").unwrap();

        assert!(values.shortcut);
        assert!(values.forwards.is_empty());
    }
}
