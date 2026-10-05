//! The new-project shortcut (DESK-011): ⌘⌥O on a Mac, Ctrl+Alt+O elsewhere, from any app. It opens the app on the
//! new-project page with the prompt focused; the menu bar's switch turns it off, and the choice is kept.

use crate::prefs::Prefs;
use crate::{desktop, tray};
use tauri::plugin::TauriPlugin;
use tauri::{AppHandle, Manager, Wry};
use tauri_plugin_global_shortcut::{GlobalShortcutExt, ShortcutState};

pub const SHORTCUT: &str = "CommandOrControl+Alt+O";

/// The new-project page, and the prompt to focus on it.
pub const NEW_PROJECT: &str = "/dashboard";
pub const PROMPT: &str = "#composer-prompt";

/// Show the app on the new-project page.
pub fn new_project(app: &AppHandle) {
    desktop::navigate(app, NEW_PROJECT, Some(PROMPT));
}

pub fn plugin() -> TauriPlugin<Wry> {
    tauri_plugin_global_shortcut::Builder::new()
        .with_handler(|app, _shortcut, event| {
            if event.state == ShortcutState::Pressed {
                new_project(app);
            }
        })
        .build()
}

pub fn enabled(app: &AppHandle) -> bool {
    app.state::<Prefs>().get(app).shortcut
}

/// Register the shortcut (or not), as the user chose. Another app may already have it: then it's off.
pub fn apply(app: &AppHandle) {
    let shortcuts = app.global_shortcut();
    let registered = shortcuts.is_registered(SHORTCUT);

    if enabled(app) && !registered {
        if let Err(error) = shortcuts.register(SHORTCUT) {
            eprintln!("[onedrop] the new-project shortcut isn't available: {error}");
        }
    } else if !enabled(app) && registered {
        let _ = shortcuts.unregister(SHORTCUT);
    }
}

/// Turn the shortcut on or off, and keep the choice.
pub fn set_enabled(app: &AppHandle, on: bool) {
    app.state::<Prefs>()
        .update(app, |values| values.shortcut = on);
    apply(app);
    tray::refresh(app);
}
