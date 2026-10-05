mod activity;
mod api;
mod desktop;
mod docker;
mod download;
mod editor;
mod forwards;
mod links;
mod network;
mod prefs;
mod relay;
mod server;
mod session;
mod shortcut;
mod sign_in;
mod ssh_proxy;
mod tray;
mod tunnel;

use std::sync::atomic::{AtomicU64, Ordering};
use tauri::webview::{NewWindowFeatures, NewWindowResponse};
use tauri::{AppHandle, Manager, RunEvent, Url, WebviewUrl, WebviewWindowBuilder, WindowEvent};
use tauri_plugin_deep_link::DeepLinkExt;
use tauri_plugin_opener::OpenerExt;

/// `OneDrop ssh-proxy <server> <project>` (DESK-008): SSH's ProxyCommand, run without the app's window.
pub fn ssh_proxy(args: &[String]) -> i32 {
    ssh_proxy::main(args)
}

/// Numbers the hidden windows that catch links opened by pages in the app's frames.
static POPUPS: AtomicU64 = AtomicU64::new(0);

/// A page in the app (say, the Shell's terminal in a frame) asked for a new window. Links open in the user's
/// browser. A page that opens a blank window and then sets its address (as the terminal does with Claude's
/// sign-in link) gets a hidden window, whose first real address goes to the browser before it closes.
fn open_in_browser(
    app: &AppHandle,
    url: Url,
    features: NewWindowFeatures,
) -> NewWindowResponse<tauri::Wry> {
    eprintln!("[onedrop] new window: {url}");

    if matches!(url.scheme(), "http" | "https" | "mailto") {
        let _ = app.opener().open_url(url.as_str(), None::<&str>);

        return NewWindowResponse::Deny;
    }

    let label = format!("popup-{}", POPUPS.fetch_add(1, Ordering::SeqCst));
    let closer = app.clone();
    let closing = label.clone();
    let popup = WebviewWindowBuilder::new(
        app,
        &label,
        WebviewUrl::External("about:blank".parse().unwrap()),
    )
    .window_features(features)
    .visible(false)
    .on_navigation(move |url| {
        eprintln!("[onedrop] popup navigates to: {url}");

        if url.scheme() == "about" {
            return true;
        }

        let _ = closer.opener().open_url(url.as_str(), None::<&str>);
        let closer = closer.clone();
        let label = closing.clone();

        tauri::async_runtime::spawn(async move {
            if let Some(window) = closer.get_webview_window(&label) {
                let _ = window.close();
            }
        });

        false
    })
    .build();

    match popup {
        Ok(window) => NewWindowResponse::Create { window },
        Err(_) => NewWindowResponse::Deny,
    }
}

/// Numbers the windows previews and shells open in.
static WINDOWS: AtomicU64 = AtomicU64::new(0);

/// A preview or shell in a window of its own (DESK-002), at a sign-in link the server just made for it: there the
/// address is the window's own site, so it keeps its cookie.
#[tauri::command]
async fn open_window(app: AppHandle, url: String, title: String) -> Result<(), String> {
    let url: Url = url.parse().map_err(|error| format!("{error}"))?;

    if !matches!(url.scheme(), "http" | "https") {
        return Err("only web addresses open in a window".into());
    }

    let label = format!("window-{}", WINDOWS.fetch_add(1, Ordering::SeqCst));
    let handle = app.clone();

    WebviewWindowBuilder::new(&app, &label, WebviewUrl::External(url))
        .title(title)
        .inner_size(1280.0, 860.0)
        .initialization_script_for_all_frames(include_str!("../scripts/frames.js"))
        .on_new_window(move |url, features| open_in_browser(&handle, url, features))
        .build()
        .map_err(|error| format!("{error}"))?;

    Ok(())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        // First, so a second launch (an `onedrop://` link on Windows and Linux) goes to this one instead.
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            desktop::show(app);
        }))
        .plugin(tauri_plugin_deep_link::init())
        .plugin(tauri_plugin_dialog::init())
        .plugin(shortcut::plugin())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .manage(server::Server::default())
        .manage(prefs::Prefs::default())
        .manage(desktop::Desktop::default())
        .manage(forwards::Forwards::default())
        .manage(network::Networks::default())
        .manage(relay::Relay::default())
        .manage(activity::Activity::default())
        // Closing the window keeps the app running in the menu bar (DESK-011); quitting is in the icon's menu.
        .on_window_event(|window, event| {
            if let WindowEvent::CloseRequested { api, .. } = event {
                if window.label() == "main" && !tray::quitting() {
                    api.prevent_close();
                    let _ = window.hide();
                }
            }
        })
        .setup(|app| {
            // The main window, from tauri.conf.json, built here so links that ask for a new window open in the
            // user's browser: the webview would drop them.
            let config = app
                .config()
                .app
                .windows
                .first()
                .cloned()
                .expect("a main window in tauri.conf.json");
            let handle = app.handle().clone();

            let leaving = app.handle().clone();

            WebviewWindowBuilder::from_config(app.handle(), &config)?
                .initialization_script_for_all_frames(include_str!("../scripts/frames.js"))
                // The server's own pages and files (a download link the page goes to) open in the browser instead
                // of replacing the app; previews, the Shell and other sites in frames load as usual.
                .on_navigation(move |url| {
                    let to_server = leaving.state::<server::Server>().is(url);

                    if to_server {
                        let _ = leaving.opener().open_url(url.as_str(), None::<&str>);
                    }

                    !to_server
                })
                .on_new_window(move |url, features| open_in_browser(&handle, url, features))
                .build()?;

            tray::create(app.handle())?;
            shortcut::apply(app.handle());

            // `onedrop://` links (DESK-011): the one the app was started with, and any while it runs.
            #[cfg(any(target_os = "linux", all(debug_assertions, windows)))]
            let _ = app.deep_link().register_all();

            let opener = app.handle().clone();
            app.deep_link()
                .on_open_url(move |event| links::open(&opener, event.urls()));

            if let Ok(Some(urls)) = app.deep_link().get_current() {
                links::open(app.handle(), urls);
            }

            Ok(())
        })
        .invoke_handler(tauri::generate_handler![
            session::session_load,
            session::session_save,
            session::session_clear,
            sign_in::sign_in,
            sign_in::cancel_sign_in,
            download::save_download,
            server::use_server,
            open_window,
            desktop::desktop_start,
            forwards::forwards_list,
            forwards::forwards_start,
            forwards::forwards_stop,
            network::network_status,
            network::network_set_sharing,
            editor::editor_configured,
            editor::editor_open,
            relay::docker_status,
            relay::docker_prepare,
            relay::devices_status,
            activity::activity_refresh,
        ])
        .build(tauri::generate_context!())
        .expect("error while building OneDrop")
        .run(|app, event| match event {
            // Quitting another way than the menu bar's (⌘Q, the Dock) says what stops first, too.
            RunEvent::ExitRequested {
                api, code: None, ..
            } if !tray::quitting() => {
                api.prevent_exit();
                tray::quit(app);
            }
            // Clicking the Dock icon with the window closed brings it back.
            #[cfg(target_os = "macos")]
            RunEvent::Reopen {
                has_visible_windows: false,
                ..
            } => desktop::show(app),
            _ => {}
        });
}
