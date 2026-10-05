//! The app in the menu bar (system tray on Windows and Linux) (DESK-011): the projects working or waiting for the
//! user, a new project, opening the app, the new-project shortcut's switch, and quitting, which says first what will
//! stop with it.

use crate::api::ActiveProject;
use crate::{activity, desktop, docker, forwards, network, shortcut};
use std::sync::atomic::{AtomicBool, Ordering};
use tauri::image::Image;
use tauri::menu::{CheckMenuItem, IsMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::TrayIconBuilder;
use tauri::{AppHandle, Wry};
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons, MessageDialogKind};

const TRAY: &str = "onedrop";

/// Set once the user chose to quit, so the app lets itself exit.
static QUITTING: AtomicBool = AtomicBool::new(false);

/// Whether the app is on its way out (and closing should be allowed).
pub fn quitting() -> bool {
    QUITTING.load(Ordering::SeqCst)
}

/// A project's line in the menu.
pub fn label(project: &ActiveProject) -> String {
    match &project.waiting_for {
        Some(waiting_for) => format!("{} — {}", project.name, waiting_label(waiting_for)),
        None => format!("{} — Working", project.name),
    }
}

/// Why it's waiting, as the web's sidebar says it (resources/js/lib/waiting-for.ts).
fn waiting_label(waiting_for: &str) -> &'static str {
    match waiting_for {
        "question" => "Needs your answer",
        "needs_input" => "Needs something from you",
        "blocked" => "Stuck",
        _ => "Waiting for you",
    }
}

/// A path in the app for a project's address (a full URL on the server, or a path already).
pub fn app_path(url: &str) -> String {
    match url::Url::parse(url) {
        Ok(url) => {
            let mut path = url.path().to_string();

            if let Some(query) = url.query() {
                path.push('?');
                path.push_str(query);
            }

            path
        }
        Err(_) if url.starts_with('/') => url.to_string(),
        Err(_) => "/dashboard".into(),
    }
}

fn menu(app: &AppHandle) -> tauri::Result<Menu<Wry>> {
    let mut projects = activity::projects(app);
    // Waiting for the user first.
    projects.sort_by_key(|project| project.waiting_for.is_none());

    let mut items: Vec<Box<dyn IsMenuItem<Wry>>> = vec![];

    for project in &projects {
        items.push(Box::new(MenuItem::with_id(
            app,
            format!("project:{}", project.id),
            label(project),
            true,
            None::<&str>,
        )?));
    }

    if !projects.is_empty() {
        items.push(Box::new(PredefinedMenuItem::separator(app)?));
    }

    let enabled = shortcut::enabled(app);

    items.push(Box::new(MenuItem::with_id(
        app,
        "new",
        "New project…",
        true,
        enabled.then_some(shortcut::SHORTCUT),
    )?));
    items.push(Box::new(MenuItem::with_id(
        app,
        "open",
        "Open OneDrop",
        true,
        None::<&str>,
    )?));
    items.push(Box::new(PredefinedMenuItem::separator(app)?));
    items.push(Box::new(CheckMenuItem::with_id(
        app,
        "shortcut",
        if cfg!(target_os = "macos") {
            "New-project shortcut (⌘⌥O)"
        } else {
            "New-project shortcut (Ctrl+Alt+O)"
        },
        true,
        enabled,
        None::<&str>,
    )?));
    items.push(Box::new(PredefinedMenuItem::separator(app)?));
    items.push(Box::new(MenuItem::with_id(
        app,
        "quit",
        "Quit OneDrop",
        true,
        None::<&str>,
    )?));

    let refs: Vec<&dyn IsMenuItem<Wry>> = items.iter().map(|item| item.as_ref()).collect();

    Menu::with_items(app, &refs)
}

fn tooltip(app: &AppHandle) -> String {
    let waiting = activity::waiting(&activity::projects(app));

    match waiting {
        0 => "OneDrop".into(),
        1 => "OneDrop: 1 project waiting for you".into(),
        n => format!("OneDrop: {n} projects waiting for you"),
    }
}

/// Show the menu's latest projects and switches.
pub fn refresh(app: &AppHandle) {
    let Some(tray) = app.tray_by_id(TRAY) else {
        return;
    };

    if let Ok(menu) = menu(app) {
        let _ = tray.set_menu(Some(menu));
    }

    let _ = tray.set_tooltip(Some(tooltip(app)));
}

/// What a menu item does.
fn chosen(app: &AppHandle, id: &str) {
    match id {
        "new" => shortcut::new_project(app),
        "open" => desktop::show(app),
        "shortcut" => shortcut::set_enabled(app, !shortcut::enabled(app)),
        "quit" => quit(app),
        _ => {
            if let Some(project) = id.strip_prefix("project:").and_then(|id| {
                activity::projects(app)
                    .into_iter()
                    .find(|project| project.id.to_string() == id)
            }) {
                desktop::navigate(app, &app_path(&project.url), None);
            }
        }
    }
}

/// Put the icon in the menu bar.
pub fn create(app: &AppHandle) -> tauri::Result<()> {
    // A template image on macOS (the system tints it for light and dark menu bars); the app's icon elsewhere.
    let (icon, template) = if cfg!(target_os = "macos") {
        (
            Image::from_bytes(include_bytes!("../icons/tray-template.png"))?,
            true,
        )
    } else {
        (
            Image::from_bytes(include_bytes!("../icons/32x32.png"))?,
            false,
        )
    };

    TrayIconBuilder::with_id(TRAY)
        .icon(icon)
        .icon_as_template(template)
        .tooltip("OneDrop")
        .menu(&menu(app)?)
        .show_menu_on_left_click(true)
        .on_menu_event(|app, event| chosen(app, event.id().as_ref()))
        .build(app)?;

    Ok(())
}

/// What quitting would stop, in words; none when nothing is running.
pub fn what_stops(forwards: usize, networks: usize, containers: usize) -> Option<String> {
    let plural = |n: usize, one: &str, many: &str| {
        if n == 1 {
            one.to_string()
        } else {
            format!("{n} {many}")
        }
    };
    let mut parts = vec![];

    if forwards > 0 {
        parts.push(plural(forwards, "a forwarded port", "forwarded ports"));
    }

    if networks > 0 {
        parts.push(plural(
            networks,
            "sharing your network with a project",
            "projects reaching your network",
        ));
    }

    if containers > 0 {
        parts.push(plural(
            containers,
            "a project running on this computer",
            "projects running on this computer",
        ));
    }

    match parts.len() {
        0 => None,
        1 => Some(parts.remove(0)),
        _ => {
            let last = parts.pop().unwrap();

            Some(format!("{} and {last}", parts.join(", ")))
        }
    }
}

/// Quit, after saying what stops with the app (forwards, the network, projects on this computer).
pub fn quit(app: &AppHandle) {
    let app = app.clone();

    tauri::async_runtime::spawn(async move {
        let containers = match desktop::device_id(&app) {
            Some(device) => docker::running(device).await,
            None => vec![],
        };

        if let Some(stopping) = what_stops(
            forwards::active_count(&app),
            network::active_count(&app),
            containers.len(),
        ) {
            let (answer, answered) = tokio::sync::oneshot::channel();

            app.dialog()
                .message(format!(
                    "Quitting stops {stopping} until you open OneDrop again."
                ))
                .title("Quit OneDrop?")
                .kind(MessageDialogKind::Warning)
                .buttons(MessageDialogButtons::OkCancelCustom(
                    "Quit".into(),
                    "Cancel".into(),
                ))
                .show(move |agreed| {
                    let _ = answer.send(agreed);
                });

            if !answered.await.unwrap_or(false) {
                return;
            }

            docker::stop_all(&containers).await;
        }

        QUITTING.store(true, Ordering::SeqCst);
        app.exit(0);
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn projects_say_whether_theyre_working_or_waiting() {
        let mut project = ActiveProject {
            id: 3,
            name: "Shop".into(),
            url: "https://onedrop.io/projects/3".into(),
            working: true,
            waiting_for: None,
        };

        assert_eq!(label(&project), "Shop — Working");

        project.waiting_for = Some("question".into());
        assert_eq!(label(&project), "Shop — Needs your answer");

        project.waiting_for = Some("blocked".into());
        assert_eq!(label(&project), "Shop — Stuck");
    }

    #[test]
    fn project_links_open_in_the_app() {
        assert_eq!(
            app_path("https://onedrop.io/projects/3?tab=chat"),
            "/projects/3?tab=chat"
        );
        assert_eq!(app_path("/projects/3"), "/projects/3");
        assert_eq!(app_path("nonsense"), "/dashboard");
    }

    #[test]
    fn quitting_says_what_stops() {
        assert_eq!(what_stops(0, 0, 0), None);
        assert_eq!(what_stops(1, 0, 0).unwrap(), "a forwarded port");
        assert_eq!(
            what_stops(2, 1, 3).unwrap(),
            "2 forwarded ports, sharing your network with a project and 3 projects running on this computer"
        );
    }
}
