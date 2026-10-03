//! Signing in through the browser (DESK-001), as RFC 8252 describes for native apps: the app listens on a
//! loopback port, opens the server's approval page in the user's browser, and waits for the browser to come
//! back with a one-time code. PKCE means only this app, holding the verifier, can trade the code for a token.

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use rand::distributions::Alphanumeric;
use rand::Rng;
use serde::Serialize;
use sha2::{Digest, Sha256};
use std::io::{BufRead, BufReader, Write};
use std::net::{TcpListener, TcpStream};
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};
use tauri::AppHandle;
use tauri_plugin_opener::OpenerExt;
use url::Url;

/// How long the browser has to come back before the sign-in gives up.
const TIMEOUT: Duration = Duration::from_secs(600);

/// Bumped by every sign-in, so starting again (or cancelling) ends the one still waiting.
static ATTEMPT: AtomicU64 = AtomicU64::new(0);

#[derive(Serialize)]
pub struct Grant {
    pub code: String,
    pub code_verifier: String,
    pub redirect_uri: String,
}

fn random(length: usize) -> String {
    rand::thread_rng()
        .sample_iter(&Alphanumeric)
        .take(length)
        .map(char::from)
        .collect()
}

/// What this computer is called, e.g. "Jeff's MacBook Pro": the server names the app's token after it.
fn device_name() -> String {
    let name = whoami::devicename();

    if name.trim().is_empty() {
        "OneDrop desktop app".into()
    } else {
        name
    }
}

/// Open the server's sign-in page in the browser and wait for its one-time code.
#[tauri::command]
pub async fn sign_in(app: AppHandle, server: String) -> Result<Grant, String> {
    let attempt = ATTEMPT.fetch_add(1, Ordering::SeqCst) + 1;
    let listener = TcpListener::bind("127.0.0.1:0").map_err(|error| error.to_string())?;
    let port = listener
        .local_addr()
        .map_err(|error| error.to_string())?
        .port();
    let redirect_uri = format!("http://127.0.0.1:{port}/callback");
    let verifier = random(64);
    let challenge = URL_SAFE_NO_PAD.encode(Sha256::digest(verifier.as_bytes()));
    let state = random(32);

    let mut page = Url::parse(&server)
        .and_then(|base| base.join("/desktop/authorize"))
        .map_err(|_| "That isn't a web address.".to_string())?;
    page.query_pairs_mut()
        .append_pair("redirect_uri", &redirect_uri)
        .append_pair("state", &state)
        .append_pair("code_challenge", &challenge)
        .append_pair("code_challenge_method", "S256")
        .append_pair("device", &device_name());

    app.opener()
        .open_url(page.as_str(), None::<&str>)
        .map_err(|error| error.to_string())?;

    let code = tauri::async_runtime::spawn_blocking(move || {
        wait_for_code(listener, &ATTEMPT, attempt, &state)
    })
    .await
    .map_err(|error| error.to_string())??;

    Ok(Grant {
        code,
        code_verifier: verifier,
        redirect_uri,
    })
}

/// Stop waiting for the browser.
#[tauri::command]
pub fn cancel_sign_in() {
    ATTEMPT.fetch_add(1, Ordering::SeqCst);
}

/// Wait for the browser's callback until it comes, `attempts` moves past `attempt` (cancelled), or TIMEOUT.
fn wait_for_code(
    listener: TcpListener,
    attempts: &AtomicU64,
    attempt: u64,
    state: &str,
) -> Result<String, String> {
    listener
        .set_nonblocking(true)
        .map_err(|error| error.to_string())?;
    let deadline = Instant::now() + TIMEOUT;

    loop {
        if attempts.load(Ordering::SeqCst) != attempt {
            return Err("cancelled".into());
        }

        if Instant::now() > deadline {
            return Err("The browser didn't finish signing in. Try again.".into());
        }

        match listener.accept() {
            Ok((stream, _)) => {
                if let Some(result) = answer(stream, state) {
                    return result;
                }
            }
            Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                std::thread::sleep(Duration::from_millis(100));
            }
            Err(error) => return Err(error.to_string()),
        }
    }
}

/// Answer one request to the loopback address. None for requests that aren't the sign-in's callback
/// (a favicon, say), so the app keeps waiting.
fn answer(mut stream: TcpStream, state: &str) -> Option<Result<String, String>> {
    stream.set_nonblocking(false).ok()?;
    stream.set_read_timeout(Some(Duration::from_secs(5))).ok()?;

    let mut request_line = String::new();
    BufReader::new(&stream).read_line(&mut request_line).ok()?;

    let target = request_line.split_whitespace().nth(1)?;
    let url = Url::parse(&format!("http://127.0.0.1{target}")).ok()?;

    if url.path() != "/callback" {
        let _ = stream
            .write_all(b"HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        return None;
    }

    let query = |name: &str| {
        url.query_pairs()
            .find(|(key, _)| key == name)
            .map(|(_, value)| value.into_owned())
    };

    let result = if query("state").as_deref() != Some(state) {
        Err("The sign-in didn't match this app. Try again.".to_string())
    } else if let Some(code) = query("code") {
        Ok(code)
    } else {
        Err("cancelled".to_string())
    };

    let (title, message) = match &result {
        Ok(_) => (
            "You're signed in",
            "Go back to the OneDrop app. You can close this tab.",
        ),
        Err(error) if error == "cancelled" => ("Sign-in cancelled", "You can close this tab."),
        Err(_) => (
            "Sign-in didn't work",
            "Go back to the OneDrop app and try again.",
        ),
    };
    let body = format!(
        "<!doctype html><meta charset=utf-8><title>{title}</title><style>:root{{color-scheme:light dark}}body{{margin:0;min-height:100vh;display:grid;place-items:center;font-family:ui-sans-serif,system-ui,sans-serif}}main{{text-align:center}}h1{{font-size:1.1rem;margin:0 0 .5rem}}p{{margin:0;opacity:.7;font-size:.9rem}}</style><main><h1>{title}</h1><p>{message}</p></main>"
    );
    let _ = write!(
        stream,
        "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {}\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n{body}",
        body.len()
    );

    Some(result)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Read;

    /// Start waiting on a fresh loopback port; returns the port and the wait's outcome.
    fn waiting(
        attempts: &'static AtomicU64,
        state: &'static str,
    ) -> (u16, std::thread::JoinHandle<Result<String, String>>) {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        let attempt = attempts.load(Ordering::SeqCst);

        (
            port,
            std::thread::spawn(move || wait_for_code(listener, attempts, attempt, state)),
        )
    }

    /// What the browser sees for a request to the loopback address.
    fn visit(port: u16, path: &str) -> String {
        let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
        write!(stream, "GET {path} HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n").unwrap();
        let mut response = String::new();
        stream.read_to_string(&mut response).unwrap();

        response
    }

    #[test]
    fn the_callback_hands_over_the_code() {
        static ATTEMPTS: AtomicU64 = AtomicU64::new(0);
        let (port, wait) = waiting(&ATTEMPTS, "s1");

        assert!(visit(port, "/favicon.ico").starts_with("HTTP/1.1 404"));
        let page = visit(port, "/callback?code=abc&state=s1");

        assert!(page.contains("You're signed in"));
        assert_eq!(wait.join().unwrap(), Ok("abc".to_string()));
    }

    #[test]
    fn a_callback_for_another_sign_in_is_refused() {
        static ATTEMPTS: AtomicU64 = AtomicU64::new(0);
        let (port, wait) = waiting(&ATTEMPTS, "s1");

        assert!(visit(port, "/callback?code=abc&state=other").contains("didn't work"));
        assert!(wait.join().unwrap().is_err());
    }

    #[test]
    fn cancelling_in_the_browser_ends_the_wait() {
        static ATTEMPTS: AtomicU64 = AtomicU64::new(0);
        let (port, wait) = waiting(&ATTEMPTS, "s1");

        assert!(visit(port, "/callback?error=access_denied&state=s1").contains("Sign-in cancelled"));
        assert_eq!(wait.join().unwrap(), Err("cancelled".to_string()));
    }

    #[test]
    fn cancelling_in_the_app_ends_the_wait() {
        static ATTEMPTS: AtomicU64 = AtomicU64::new(0);
        let (_port, wait) = waiting(&ATTEMPTS, "s1");

        ATTEMPTS.fetch_add(1, Ordering::SeqCst);

        assert_eq!(wait.join().unwrap(), Err("cancelled".to_string()));
    }
}
