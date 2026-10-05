//! Opening a project in VS Code or Cursor over SSH (DESK-008). The app makes an SSH key for this computer, adds it to
//! the owner's keys, and keeps an entry per project in `~/.ssh/onedrop/config`, whose connections go through the app
//! itself (`ssh-proxy`). `~/.ssh/config` gets one line that includes that file, after the user agrees; nothing else
//! in it is touched.

use crate::desktop;
use std::path::{Path, PathBuf};
use tauri::AppHandle;
use tauri_plugin_dialog::{DialogExt, MessageDialogButtons, MessageDialogKind};
use tauri_plugin_opener::OpenerExt;

/// The line that makes `~/.ssh/config` read the app's entries.
pub const INCLUDE: &str = "Include ~/.ssh/onedrop/config";

/// What the app's own SSH config starts with.
const HEADER: &str = "# Written by the OneDrop desktop app: an entry per project opened in an editor.\n# Changes here are replaced when the project is opened again.\n";

fn home() -> Result<PathBuf, String> {
    dirs::home_dir().ok_or_else(|| "Couldn't find your home folder.".to_string())
}

fn ssh_folder() -> Result<PathBuf, String> {
    Ok(home()?.join(".ssh"))
}

fn our_folder() -> Result<PathBuf, String> {
    Ok(ssh_folder()?.join("onedrop"))
}

/// Whether a config already includes the app's.
pub fn has_include(config: &str) -> bool {
    config.lines().any(|line| {
        let line = line.trim();

        match (line.get(..7), line.get(7..)) {
            (Some(word), Some(rest)) => {
                word.eq_ignore_ascii_case("include")
                    && rest
                        .trim()
                        .trim_matches('"')
                        .replace('\\', "/")
                        .ends_with(".ssh/onedrop/config")
            }
            _ => false,
        }
    })
}

/// The config with the include at the very top (where it applies to every host, not inside one's block), keeping
/// the rest exactly as it was.
pub fn with_include(config: &str) -> String {
    if has_include(config) {
        return config.to_string();
    }

    let newline = if config.contains("\r\n") {
        "\r\n"
    } else {
        "\n"
    };

    if config.is_empty() {
        format!("{INCLUDE}{newline}")
    } else {
        format!("{INCLUDE}{newline}{newline}{config}")
    }
}

/// A value for ssh_config: in double quotes, with `%` doubled (ssh expands `%h` and friends in these settings).
fn quoted(value: &str) -> String {
    let escaped = if cfg!(windows) {
        value.to_string()
    } else {
        // ProxyCommand runs in the shell: what's special inside double quotes is escaped.
        value
            .replace('\\', "\\\\")
            .replace('"', "\\\"")
            .replace('$', "\\$")
            .replace('`', "\\`")
    };

    format!("\"{}\"", escaped.replace('%', "%%"))
}

/// The ProxyCommand: this app, as the SSH proxy for the project.
pub fn proxy_command(binary: &Path, server: &str, project_id: u64) -> String {
    format!(
        "{} ssh-proxy {} {project_id}",
        quoted(&binary.to_string_lossy()),
        quoted(server)
    )
}

/// One project's entry.
pub fn host_block(alias: &str, proxy: &str) -> String {
    format!(
        "Host {alias}\n    HostName {alias}\n    User sandbox\n    IdentityFile ~/.ssh/onedrop/id_ed25519\n    IdentitiesOnly yes\n    ProxyCommand {proxy}\n    StrictHostKeyChecking no\n    UserKnownHostsFile /dev/null\n    LogLevel ERROR\n"
    )
}

/// The app's config with the project's entry added or replaced; the other entries stay.
pub fn upsert_host(config: &str, alias: &str, block: &str) -> String {
    let mut blocks: Vec<String> = vec![];

    for line in config.lines() {
        let starts = line
            .trim_start()
            .get(..5)
            .is_some_and(|word| word.eq_ignore_ascii_case("host "));

        if starts || blocks.is_empty() {
            blocks.push(String::new());
        }

        let last = blocks.last_mut().unwrap();
        last.push_str(line);
        last.push('\n');
    }

    let ours = format!("Host {alias}");
    let mut hosts: Vec<String> = blocks
        .into_iter()
        .filter(|block| block.trim_start().to_lowercase().starts_with("host "))
        .filter(|block| block.lines().next().map(str::trim) != Some(ours.as_str()))
        .map(|block| block.trim_end().to_string() + "\n")
        .collect();

    hosts.push(block.to_string());
    hosts.sort();

    format!("{HEADER}\n{}", hosts.join("\n"))
}

/// An alias the config can hold, e.g. `onedrop-12`.
pub fn check_alias(alias: &str) -> Result<(), String> {
    let valid = !alias.is_empty()
        && alias.len() <= 64
        && alias
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.'));

    if valid {
        Ok(())
    } else {
        Err(format!("{alias:?} can't be an SSH host name."))
    }
}

/// The editor's link to open a folder over SSH.
pub fn editor_url(editor: &str, alias: &str) -> Result<String, String> {
    let scheme = match editor {
        "vscode" => "vscode",
        "cursor" => "cursor",
        _ => return Err(format!("{editor:?} isn't an editor the app opens.")),
    };

    Ok(format!(
        "{scheme}://vscode-remote/ssh-remote+{alias}/workspace"
    ))
}

/// Write a file only its owner can read.
fn write_private(path: &Path, contents: &str) -> Result<(), String> {
    std::fs::write(path, contents)
        .map_err(|error| format!("Couldn't write {}: {error}", path.display()))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o600))
            .map_err(|error| error.to_string())?;
    }

    Ok(())
}

fn make_folder(path: &Path) -> Result<(), String> {
    if path.is_dir() {
        return Ok(());
    }

    std::fs::create_dir_all(path)
        .map_err(|error| format!("Couldn't create {}: {error}", path.display()))?;

    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        let _ = std::fs::set_permissions(path, std::fs::Permissions::from_mode(0o700));
    }

    Ok(())
}

/// This computer's key for OneDrop (made the first time), as its public line.
fn ensure_key() -> Result<String, String> {
    let folder = our_folder()?;
    let private = folder.join("id_ed25519");
    let public = folder.join("id_ed25519.pub");

    if let Ok(line) = std::fs::read_to_string(&public) {
        if private.is_file() && !line.trim().is_empty() {
            return Ok(line.trim().to_string());
        }
    }

    make_folder(&folder)?;

    let mut key = ssh_key::PrivateKey::random(&mut rand::rngs::OsRng, ssh_key::Algorithm::Ed25519)
        .map_err(|error| error.to_string())?;
    key.set_comment(format!("OneDrop on {}", crate::sign_in::device_name()));

    let encoded = key
        .to_openssh(ssh_key::LineEnding::LF)
        .map_err(|error| error.to_string())?;
    let line = key
        .public_key()
        .to_openssh()
        .map_err(|error| error.to_string())?;

    write_private(&private, &encoded)?;
    std::fs::write(&public, format!("{line}\n")).map_err(|error| error.to_string())?;

    Ok(line)
}

/// Ask the user, once the include is needed.
async fn agree(app: &AppHandle) -> bool {
    let (answer, answered) = tokio::sync::oneshot::channel();

    app.dialog()
        .message(format!(
            "To open projects in your editor, OneDrop adds this line to the top of ~/.ssh/config:\n\n{INCLUDE}\n\nIts project entries stay in a file of its own. The rest of your SSH config isn't changed."
        ))
        .title("Add OneDrop to your SSH config?")
        .kind(MessageDialogKind::Info)
        .buttons(MessageDialogButtons::OkCancelCustom("Add it".into(), "Cancel".into()))
        .show(move |agreed| {
            let _ = answer.send(agreed);
        });

    answered.await.unwrap_or(false)
}

#[tauri::command]
pub fn editor_configured() -> bool {
    ssh_folder()
        .ok()
        .and_then(|folder| std::fs::read_to_string(folder.join("config")).ok())
        .is_some_and(|config| has_include(&config))
}

#[tauri::command]
pub async fn editor_open(
    app: AppHandle,
    project_id: u64,
    alias: String,
    editor: String,
) -> Result<(), String> {
    check_alias(&alias)?;
    let url = editor_url(&editor, &alias)?;
    let api = desktop::api(&app)?;

    // The include first: nothing is written if the user says no.
    let ssh = ssh_folder()?;
    let main_config = ssh.join("config");
    let existing = std::fs::read_to_string(&main_config).unwrap_or_default();

    if !has_include(&existing) {
        if !agree(&app).await {
            return Err("cancelled".into());
        }

        make_folder(&ssh)?;
        // Re-read: the user may have changed it while the dialog was open.
        let existing = std::fs::read_to_string(&main_config).unwrap_or_default();
        write_private(&main_config, &with_include(&existing))?;
    }

    let public_key = ensure_key()?;
    // The server keeps one key per computer and answers the same for a key it already has.
    api.add_ssh_key(&public_key).await?;

    let binary = std::env::current_exe().map_err(|error| error.to_string())?;
    let ours = our_folder()?.join("config");
    let config = std::fs::read_to_string(&ours).unwrap_or_default();
    let block = host_block(&alias, &proxy_command(&binary, &api.server, project_id));

    write_private(&ours, &upsert_host(&config, &alias, &block))?;

    app.opener()
        .open_url(url, None::<&str>)
        .map_err(|error| format!("Couldn't open the editor: {error}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_include_goes_on_top_and_keeps_the_rest() {
        let existing = "Host github.com\n    User git\n";

        assert_eq!(
            with_include(existing),
            "Include ~/.ssh/onedrop/config\n\nHost github.com\n    User git\n"
        );
        assert_eq!(with_include(""), "Include ~/.ssh/onedrop/config\n");
        assert_eq!(
            with_include("Host a\r\n  User b\r\n"),
            "Include ~/.ssh/onedrop/config\r\n\r\nHost a\r\n  User b\r\n"
        );
    }

    #[test]
    fn an_include_already_there_is_kept_as_is() {
        let existing = "# mine\ninclude  \"~/.ssh/onedrop/config\"\nHost x\n";

        assert!(has_include(existing));
        assert_eq!(with_include(existing), existing);
        assert!(!has_include("Include ~/.ssh/other/config\n"));
    }

    #[test]
    fn a_projects_entry_goes_through_the_app() {
        let proxy = proxy_command(
            Path::new("/Applications/OneDrop.app/Contents/MacOS/OneDrop"),
            "https://onedrop.io",
            12,
        );
        let block = host_block("onedrop-12", &proxy);

        if !cfg!(windows) {
            assert_eq!(
                proxy,
                r#""/Applications/OneDrop.app/Contents/MacOS/OneDrop" ssh-proxy "https://onedrop.io" 12"#
            );
        }

        assert!(block.starts_with("Host onedrop-12\n    HostName onedrop-12\n    User sandbox\n"));
        assert!(block.contains("    IdentitiesOnly yes\n"));
        assert!(block.contains(
            "    StrictHostKeyChecking no\n    UserKnownHostsFile /dev/null\n    LogLevel ERROR\n"
        ));
    }

    #[test]
    fn paths_are_quoted_for_ssh() {
        assert_eq!(
            quoted("C:\\Program Files\\100%"),
            if cfg!(windows) {
                "\"C:\\Program Files\\100%%\""
            } else {
                "\"C:\\\\Program Files\\\\100%%\""
            }
        );

        if !cfg!(windows) {
            assert_eq!(quoted("/a/$HOME/`x`"), "\"/a/\\$HOME/\\`x\\`\"");
        }
    }

    #[test]
    fn entries_are_added_or_replaced() {
        let one = upsert_host("", "onedrop-1", &host_block("onedrop-1", "p1"));
        let two = upsert_host(&one, "onedrop-2", &host_block("onedrop-2", "p2"));
        let again = upsert_host(&two, "onedrop-1", &host_block("onedrop-1", "p1-new"));

        assert!(one.starts_with(HEADER));
        assert_eq!(two.matches("Host onedrop-").count(), 2);
        assert_eq!(again.matches("Host onedrop-").count(), 2);
        assert!(again.contains("ProxyCommand p1-new"));
        assert!(!again.contains("ProxyCommand p1\n"));
        assert!(again.contains("ProxyCommand p2"));
        assert_eq!(
            upsert_host(&again, "onedrop-2", &host_block("onedrop-2", "p2")),
            again
        );
    }

    #[test]
    fn aliases_and_editors_are_checked() {
        assert!(check_alias("onedrop-12").is_ok());
        assert!(check_alias("x y").is_err());
        assert!(check_alias("a\nProxyCommand evil").is_err());
        assert_eq!(
            editor_url("cursor", "onedrop-12").unwrap(),
            "cursor://vscode-remote/ssh-remote+onedrop-12/workspace"
        );
        assert!(editor_url("vim", "onedrop-12").is_err());
    }
}
