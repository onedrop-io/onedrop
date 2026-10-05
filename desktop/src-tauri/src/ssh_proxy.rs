//! `OneDrop ssh-proxy <server> <project>`: the app as SSH's ProxyCommand (DESK-008). It signs in with the keychain's
//! session, gets an SSH ticket and pipes stdin and stdout through the sandbox's tunnel, so `ssh onedrop-<project>` and
//! editors work even with the app's window closed. No window opens; errors go to stderr.

use crate::api::{Api, Purpose};
use crate::{session, tunnel};
use url::Url;

/// The origin of a server address, so `https://onedrop.io/` and `https://onedrop.io` are the same server.
pub fn same_server(a: &str, b: &str) -> bool {
    match (Url::parse(a), Url::parse(b)) {
        (Ok(a), Ok(b)) => a.origin() == b.origin(),
        _ => false,
    }
}

async fn proxy(server: &str, project: &str) -> Result<(), String> {
    let project: u64 = project
        .parse()
        .map_err(|_| format!("{project:?} isn't a project id."))?;
    let session = session::load()
        .ok_or("The OneDrop app isn't signed in on this computer. Open it and sign in.")?;

    if !same_server(&session.server, server) {
        return Err(format!(
            "The OneDrop app is signed in to {}, not {server}. Sign in to {server} in the app.",
            session.server
        ));
    }

    let api = Api::new(&session);
    let ticket = api.ticket(project, Purpose::Ssh).await?;
    let url = tunnel::url_for(&ticket, api.token_id()).await?;
    let socket = tunnel::connect(&url).await?;

    tunnel::pipe(tokio::io::stdin(), tokio::io::stdout(), socket).await;

    Ok(())
}

/// Run as the proxy; `args` are what follows `ssh-proxy`. Returns the process's exit code.
pub fn main(args: &[String]) -> i32 {
    let [server, project] = args else {
        eprintln!("usage: OneDrop ssh-proxy <server> <project id>");

        return 2;
    };

    match tauri::async_runtime::block_on(proxy(server, project)) {
        Ok(()) => 0,
        Err(error) => {
            eprintln!("onedrop: {error}");

            1
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_server_must_be_the_signed_in_one() {
        assert!(same_server("https://onedrop.io", "https://onedrop.io/"));
        assert!(same_server(
            "http://localhost:8000",
            "http://localhost:8000"
        ));
        assert!(!same_server(
            "http://localhost:8000",
            "http://localhost:8001"
        ));
        assert!(!same_server("https://onedrop.io", "https://evil.example"));
    }

    #[test]
    fn bad_arguments_are_refused() {
        assert_eq!(main(&["https://onedrop.io".into()]), 2);
    }
}
