//! The projects working or waiting for the user (DESK-011), asked of the server every 30 seconds and whenever a
//! notification fires: listed in the menu bar, and counted on the Dock icon (macOS) or taskbar (Windows).

use crate::api::ActiveProject;
use crate::{desktop, tray};
use std::sync::Mutex;
use std::time::Duration;
use tauri::async_runtime::JoinHandle;
use tauri::{AppHandle, Manager};
use tokio::sync::Notify;

const EVERY: Duration = Duration::from_secs(30);

#[derive(Default)]
pub struct Activity {
    projects: Mutex<Vec<ActiveProject>>,
    task: Mutex<Option<JoinHandle<()>>>,
    now: Notify,
}

/// The projects as last heard.
pub fn projects(app: &AppHandle) -> Vec<ActiveProject> {
    app.state::<Activity>().projects.lock().unwrap().clone()
}

/// How many projects are waiting for the user.
pub fn waiting(projects: &[ActiveProject]) -> usize {
    projects
        .iter()
        .filter(|project| project.waiting_for.is_some())
        .count()
}

fn show(app: &AppHandle, projects: Vec<ActiveProject>) {
    let state = app.state::<Activity>();
    let mut current = state.projects.lock().unwrap();

    if *current == projects {
        return;
    }

    *current = projects.clone();
    drop(current);

    tray::refresh(app);
    badge(app, waiting(&projects));
}

/// The number on the app's icon; none at zero.
fn badge(app: &AppHandle, count: usize) {
    let Some(window) = app.get_webview_window("main") else {
        return;
    };

    #[cfg(target_os = "windows")]
    {
        let icon = badge_pixels(count).map(|pixels| tauri::image::Image::new_owned(pixels, 16, 16));
        let _ = window.set_overlay_icon(icon);
    }

    #[cfg(not(target_os = "windows"))]
    {
        let _ = window.set_badge_count(if count == 0 { None } else { Some(count as i64) });
    }
}

/// Digits (and "+" for more than 9), 3 by 5 pixels, a row per byte's low bits.
const GLYPHS: [[u8; 5]; 11] = [
    [7, 5, 5, 5, 7],
    [2, 6, 2, 2, 7],
    [7, 1, 7, 4, 7],
    [7, 1, 7, 1, 7],
    [5, 5, 7, 1, 1],
    [7, 4, 7, 1, 7],
    [7, 4, 7, 5, 7],
    [7, 1, 1, 1, 1],
    [7, 5, 7, 5, 7],
    [7, 5, 7, 1, 7],
    [0, 2, 7, 2, 0],
];

/// The taskbar's overlay on Windows: a red dot with the count in white, 16 by 16 RGBA. None at zero.
#[cfg_attr(not(target_os = "windows"), allow(dead_code))]
pub fn badge_pixels(count: usize) -> Option<Vec<u8>> {
    if count == 0 {
        return None;
    }

    let glyph = GLYPHS[count.min(10)];
    let mut pixels = vec![0u8; 16 * 16 * 4];

    for y in 0..16 {
        for x in 0..16 {
            let (dx, dy) = (x as f32 - 7.5, y as f32 - 7.5);

            if dx * dx + dy * dy > 64.0 {
                continue;
            }

            // The glyph, doubled, from (5, 3).
            let (gx, gy) = (x as i32 - 5, y as i32 - 3);
            let lit = (0..6).contains(&gx)
                && (0..10).contains(&gy)
                && glyph[(gy / 2) as usize] & (4 >> (gx / 2)) != 0;
            let at = (y * 16 + x) * 4;

            pixels[at..at + 4].copy_from_slice(if lit {
                &[255, 255, 255, 255]
            } else {
                &[220, 38, 38, 255]
            });
        }
    }

    Some(pixels)
}

/// Ask the server, now.
async fn poll(app: &AppHandle) {
    let Ok(api) = desktop::api(app) else {
        return;
    };

    if let Ok(projects) = api.activity().await {
        show(app, projects);
    }
}

/// Start asking (signing in).
pub fn start(app: &AppHandle) {
    let handle = app.clone();
    let task = tauri::async_runtime::spawn(async move {
        loop {
            poll(&handle).await;

            let state = handle.state::<Activity>();
            let _ = tokio::time::timeout(EVERY, state.now.notified()).await;
        }
    });

    if let Some(old) = app.state::<Activity>().task.lock().unwrap().replace(task) {
        old.abort();
    }
}

/// Stop asking, and clear the menu and badge (signing out).
pub fn stop(app: &AppHandle) {
    if let Some(task) = app.state::<Activity>().task.lock().unwrap().take() {
        task.abort();
    }

    show(app, vec![]);
}

/// A notification fired: something may be waiting now.
#[tauri::command]
pub fn activity_refresh(app: AppHandle) {
    app.state::<Activity>().now.notify_one();
}

#[cfg(test)]
mod tests {
    use super::*;

    fn project(waiting_for: Option<&str>) -> ActiveProject {
        ActiveProject {
            id: 1,
            name: "Shop".into(),
            url: "/projects/1".into(),
            working: waiting_for.is_none(),
            waiting_for: waiting_for.map(String::from),
        }
    }

    #[test]
    fn only_projects_waiting_for_the_user_are_counted() {
        assert_eq!(
            waiting(&[
                project(None),
                project(Some("your review")),
                project(Some("an answer"))
            ]),
            2
        );
        assert_eq!(waiting(&[]), 0);
    }

    #[test]
    fn the_windows_badge_is_a_red_dot_with_the_count() {
        assert_eq!(badge_pixels(0), None);

        let one = badge_pixels(1).unwrap();
        let pixel = |x: usize, y: usize| &one[(y * 16 + x) * 4..(y * 16 + x) * 4 + 4];

        assert_eq!(pixel(0, 0), &[0, 0, 0, 0]);
        assert_eq!(pixel(3, 8), &[220, 38, 38, 255]);
        // The middle column of "1" is lit.
        assert_eq!(pixel(7, 8), &[255, 255, 255, 255]);
        assert_ne!(badge_pixels(12), badge_pixels(9));
    }
}
