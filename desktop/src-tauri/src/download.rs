//! Files the web app downloads (a database export, a file from the Files panel). The app's window can't follow a
//! download link, so the page fetches the file with the token and hands it here to save.

use std::path::{Path, PathBuf};
use tauri::{AppHandle, Manager};
use tauri_plugin_opener::OpenerExt;

/// A name that's safe as a file name: no folders, nothing hidden.
fn safe_name(name: &str) -> String {
    let base = Path::new(name)
        .file_name()
        .and_then(|name| name.to_str())
        .unwrap_or("download")
        .trim_start_matches('.');

    if base.is_empty() {
        "download".into()
    } else {
        base.into()
    }
}

/// `name`, or `name (2)`, `name (3)`… when the folder already has one.
fn free_path(folder: &Path, name: &str) -> PathBuf {
    let path = folder.join(name);

    if !path.exists() {
        return path;
    }

    let stem = Path::new(name)
        .file_stem()
        .and_then(|stem| stem.to_str())
        .unwrap_or(name);
    let extension = Path::new(name).extension().and_then(|ext| ext.to_str());

    (2..)
        .map(|n| match extension {
            Some(ext) => folder.join(format!("{stem} ({n}).{ext}")),
            None => folder.join(format!("{stem} ({n})")),
        })
        .find(|candidate| !candidate.exists())
        .expect("a free name")
}

/// Save the file in the Downloads folder and show it there.
#[tauri::command]
pub fn save_download(app: AppHandle, name: String, bytes: Vec<u8>) -> Result<(), String> {
    let folder = app
        .path()
        .download_dir()
        .map_err(|error| error.to_string())?;
    let path = free_path(&folder, &safe_name(&name));

    std::fs::write(&path, bytes).map_err(|error| error.to_string())?;
    let _ = app.opener().reveal_item_in_dir(&path);

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn names_stay_in_the_folder() {
        assert_eq!(safe_name("../../etc/passwd"), "passwd");
        assert_eq!(safe_name(".env"), "env");
        assert_eq!(safe_name(""), "download");
        assert_eq!(safe_name("export.csv"), "export.csv");
    }

    #[test]
    fn a_name_already_taken_gets_a_number() {
        let folder =
            std::env::temp_dir().join(format!("onedrop-download-test-{}", std::process::id()));
        std::fs::create_dir_all(&folder).unwrap();
        std::fs::write(folder.join("rows.csv"), "x").unwrap();

        assert_eq!(free_path(&folder, "rows.csv"), folder.join("rows (2).csv"));
        assert_eq!(free_path(&folder, "other.csv"), folder.join("other.csv"));

        std::fs::remove_dir_all(&folder).unwrap();
    }
}
