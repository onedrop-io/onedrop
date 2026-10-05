//! The server's desktop API (DESK-007..011), called with the keychain session's token. The token never goes in a
//! log or an error message.

use crate::session::Session;
use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use std::fmt;
use std::sync::Once;
use std::time::Duration;

/// rustls needs a crypto provider for the whole process; the updater installs the same one if it gets there first.
pub fn use_tls() {
    static ONCE: Once = Once::new();

    ONCE.call_once(|| {
        if rustls::crypto::CryptoProvider::get_default().is_none() {
            let _ = rustls::crypto::ring::default_provider().install_default();
        }
    });
}

/// What went wrong with a call: the server's status (none when it couldn't be reached) and something to show.
#[derive(Debug, Clone)]
pub struct ApiError {
    pub status: Option<u16>,
    pub message: String,
}

impl fmt::Display for ApiError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.message)
    }
}

impl From<ApiError> for String {
    fn from(error: ApiError) -> Self {
        error.message
    }
}

/// What a tunnel ticket is for.
#[derive(Debug, Clone, Copy, PartialEq)]
pub enum Purpose {
    Forward(u16),
    Ssh,
    Network,
}

impl Purpose {
    /// The body of the ticket request.
    pub fn body(self) -> Value {
        match self {
            Purpose::Forward(port) => json!({ "purpose": "forward", "port": port }),
            Purpose::Ssh => json!({ "purpose": "ssh" }),
            Purpose::Network => json!({ "purpose": "network" }),
        }
    }
}

/// A host on the user's network a project may reach through this computer (DESK-009).
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Host {
    pub host: String,
    pub port: u16,
}

/// The computer a project runs on, when it runs on one (DESK-010).
#[derive(Debug, Clone, Deserialize)]
pub struct TicketDevice {
    pub id: u64,
    pub container: String,
}

/// A ticket for the sandbox's tunnel. `url` is null for a project on a computer: only that computer connects, to
/// the container's own port.
#[derive(Debug, Clone, Deserialize)]
pub struct Ticket {
    pub url: Option<String>,
    pub ticket: String,
    #[serde(default)]
    pub hosts: Vec<Host>,
    pub device: Option<TicketDevice>,
}

/// This computer's link to the device relay (DESK-010). `url` is absent when the install has no relay.
#[derive(Debug, Clone, Deserialize)]
pub struct DeviceLink {
    pub id: Option<u64>,
    pub name: Option<String>,
    pub url: Option<String>,
    pub image: Option<String>,
}

/// A project the menu bar lists (DESK-011).
#[derive(Debug, Clone, PartialEq, Deserialize)]
pub struct ActiveProject {
    pub id: u64,
    pub name: String,
    pub url: String,
    #[serde(default)]
    pub working: bool,
    pub waiting_for: Option<String>,
}

#[derive(Deserialize)]
struct Activity {
    projects: Vec<ActiveProject>,
}

/// The device id in a Sanctum token (`<token id>|<secret>`), for when the server doesn't say.
pub fn token_id(token: &str) -> Option<u64> {
    token.split_once('|')?.0.parse().ok()
}

/// The server's message in an error answer, else a plain one for its status.
fn error_message(status: u16, body: &str) -> String {
    let message = serde_json::from_str::<Value>(body)
        .ok()
        .and_then(|value| value.get("message")?.as_str().map(str::to_owned))
        .filter(|message| !message.is_empty());

    message.unwrap_or_else(|| match status {
        401 => "The app's sign-in stopped working. Sign in again.".into(),
        403 => "You can't do that in this project.".into(),
        404 => "Not found.".into(),
        _ => format!("The server answered {status}."),
    })
}

/// The signed-in server, as the API sees it.
#[derive(Clone)]
pub struct Api {
    pub server: String,
    token: String,
    client: reqwest::Client,
}

impl Api {
    pub fn new(session: &Session) -> Self {
        use_tls();

        let client = reqwest::Client::builder()
            .user_agent(concat!("OneDrop desktop/", env!("CARGO_PKG_VERSION")))
            .timeout(Duration::from_secs(30))
            .connect_timeout(Duration::from_secs(10))
            .build()
            .expect("an HTTP client");

        Self {
            server: session.server.trim_end_matches('/').to_string(),
            token: session.token.clone(),
            client,
        }
    }

    /// Whether this is the same sign-in.
    pub fn is(&self, session: &Session) -> bool {
        self.server == session.server.trim_end_matches('/') && self.token == session.token
    }

    /// The device id the token carries.
    pub fn token_id(&self) -> Option<u64> {
        token_id(&self.token)
    }

    async fn call<T: DeserializeOwned>(
        &self,
        method: reqwest::Method,
        path: &str,
        body: Option<Value>,
    ) -> Result<(u16, Option<T>, String), ApiError> {
        let mut request = self
            .client
            .request(method, format!("{}{path}", self.server))
            .bearer_auth(&self.token)
            .header("Accept", "application/json");

        if let Some(body) = body {
            request = request.json(&body);
        }

        let response = request.send().await.map_err(|error| ApiError {
            status: None,
            message: format!("Couldn't reach {}: {}", self.server, plain(&error)),
        })?;
        let status = response.status().as_u16();
        let text = response.text().await.unwrap_or_default();

        Ok((status, serde_json::from_str(&text).ok(), text))
    }

    /// POST (or GET) and read a JSON answer, turning error statuses into errors.
    async fn json<T: DeserializeOwned>(
        &self,
        method: reqwest::Method,
        path: &str,
        body: Option<Value>,
    ) -> Result<T, ApiError> {
        let (status, value, text) = self.call::<T>(method, path, body).await?;

        if !(200..300).contains(&status) {
            return Err(ApiError {
                status: Some(status),
                message: error_message(status, &text),
            });
        }

        value.ok_or_else(|| ApiError {
            status: Some(status),
            message: "The server's answer wasn't understood. It may be older than the app.".into(),
        })
    }

    /// A ticket for the project's tunnel.
    pub async fn ticket(&self, project: u64, purpose: Purpose) -> Result<Ticket, ApiError> {
        self.json(
            reqwest::Method::POST,
            &format!("/api/v1/desktop/projects/{project}/tunnel"),
            Some(purpose.body()),
        )
        .await
    }

    /// The relay link for this computer. A 404 still says who the computer is, with no `url`.
    pub async fn device(&self) -> Result<DeviceLink, ApiError> {
        let (status, value, text) = self
            .call::<DeviceLink>(
                reqwest::Method::POST,
                "/api/v1/desktop/device",
                Some(json!({})),
            )
            .await?;

        match (status, value) {
            (200..=299, Some(link)) => Ok(link),
            (404, Some(link)) => Ok(DeviceLink { url: None, ..link }),
            (404, None) => Ok(DeviceLink {
                id: None,
                name: None,
                url: None,
                image: None,
            }),
            _ => Err(ApiError {
                status: Some(status),
                message: error_message(status, &text),
            }),
        }
    }

    /// The projects working or waiting for the user.
    pub async fn activity(&self) -> Result<Vec<ActiveProject>, ApiError> {
        let activity: Activity = self
            .json(reqwest::Method::GET, "/api/v1/desktop/activity", None)
            .await?;

        Ok(activity.projects)
    }

    /// Add this computer's SSH key to the user's keys (DESK-008).
    pub async fn add_ssh_key(&self, public_key: &str) -> Result<(), ApiError> {
        self.json::<Value>(
            reqwest::Method::POST,
            "/api/v1/desktop/ssh-key",
            Some(json!({ "public_key": public_key })),
        )
        .await
        .map(|_| ())
    }
}

/// A request error without its URL's query (nothing secret is in it, but it keeps messages short).
fn plain(error: &reqwest::Error) -> String {
    let error = error.to_string();

    error.split(" for url").next().unwrap_or(&error).to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ticket_bodies_say_what_they_are_for() {
        assert_eq!(
            Purpose::Forward(5432).body(),
            json!({ "purpose": "forward", "port": 5432 })
        );
        assert_eq!(Purpose::Ssh.body(), json!({ "purpose": "ssh" }));
        assert_eq!(Purpose::Network.body(), json!({ "purpose": "network" }));
    }

    #[test]
    fn the_device_id_is_the_tokens_id() {
        assert_eq!(token_id("4|onedrop_abc"), Some(4));
        assert_eq!(token_id("onedrop_abc"), None);
    }

    #[test]
    fn errors_use_the_servers_message() {
        assert_eq!(
            error_message(
                409,
                r#"{"message":"This project runs on another computer."}"#
            ),
            "This project runs on another computer."
        );
        assert_eq!(error_message(500, "<html>"), "The server answered 500.");
    }

    #[test]
    fn tickets_parse_with_or_without_a_device() {
        let hosted: Ticket = serde_json::from_str(
            r#"{"url":"wss://p.example/__onedrop/tunnel?ticket=t","ticket":"t","hosts":[{"host":"db.internal","port":5432}],"device":null}"#,
        )
        .unwrap();
        let local: Ticket = serde_json::from_str(
            r#"{"url":null,"ticket":"t","device":{"id":4,"container":"onedrop-project-9-ab"}}"#,
        )
        .unwrap();

        assert_eq!(hosted.hosts[0].port, 5432);
        assert_eq!(local.device.unwrap().container, "onedrop-project-9-ab");
        assert!(local.hosts.is_empty());
    }
}
