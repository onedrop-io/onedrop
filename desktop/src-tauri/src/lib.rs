mod download;
mod server;
mod session;
mod sign_in;

use std::sync::atomic::{AtomicU64, Ordering};
use tauri::webview::{NewWindowFeatures, NewWindowResponse};
use tauri::{AppHandle, Manager, Url, WebviewUrl, WebviewWindowBuilder};
use tauri_plugin_opener::OpenerExt;

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
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_process::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .manage(server::Server::default())
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
        ])
        .run(tauri::generate_context!())
        .expect("error while running OneDrop");
}
