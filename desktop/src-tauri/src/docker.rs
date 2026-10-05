//! The computer's Docker, through the `docker` CLI (DESK-010): finding it (apps started from the Dock or Start menu
//! don't get the shell's PATH), saying whether it's ready, downloading the sandbox image with progress, and running a
//! project's sandbox container the way `DockerSandboxProvider` does on a server.

use serde::Serialize;
use std::collections::BTreeMap;
use std::ffi::OsString;
use std::path::PathBuf;
use std::process::Stdio;
use std::time::Duration;
use tokio::io::{AsyncBufReadExt, BufReader};
use tokio::process::Command;

/// Every sandbox container's label, as on a server, so they can be found and cleaned up.
pub const LABEL: &str = "onedrop.sandbox";

/// The label naming the computer's sign-in a container belongs to.
pub const DEVICE_LABEL: &str = "onedrop.device";

/// The only containers the app touches.
pub const PREFIX: &str = "onedrop-project-";

/// Seconds a stopping container gets to exit by itself before it's killed.
const STOP_SECONDS: &str = "5";

/// The image when the server hasn't said.
pub const DEFAULT_IMAGE: &str = "ghcr.io/onedrop-io/onedrop-sandbox:latest";

/// Folders the `docker` CLI is installed in by Docker Desktop, OrbStack, Colima (via Homebrew) and Docker Engine.
fn candidates() -> Vec<PathBuf> {
    let mut folders: Vec<PathBuf> = vec![];
    let home = dirs::home_dir();

    #[cfg(not(windows))]
    {
        folders.extend(
            [
                "/usr/local/bin",
                "/opt/homebrew/bin",
                "/usr/bin",
                "/snap/bin",
            ]
            .map(PathBuf::from),
        );

        if let Some(home) = &home {
            folders.push(home.join(".docker/bin"));
            folders.push(home.join(".orbstack/bin"));
            folders.push(home.join(".rd/bin"));
        }

        folders.push("/Applications/Docker.app/Contents/Resources/bin".into());
        folders.push("/Applications/OrbStack.app/Contents/MacOS/xbin".into());
    }

    #[cfg(windows)]
    {
        for root in ["ProgramFiles", "ProgramW6432"] {
            if let Some(root) = std::env::var_os(root) {
                folders.push(PathBuf::from(root).join("Docker\\Docker\\resources\\bin"));
            }
        }

        if let Some(home) = &home {
            folders.push(home.join(".docker\\bin"));
            folders.push(home.join(
                "AppData\\Local\\Programs\\Rancher Desktop\\resources\\resources\\win32\\bin",
            ));
        }
    }

    folders
}

fn executable() -> &'static str {
    if cfg!(windows) {
        "docker.exe"
    } else {
        "docker"
    }
}

/// The `docker` CLI: on PATH, else where the usual installs put it.
pub fn find() -> Option<PathBuf> {
    let on_path = std::env::var_os("PATH")
        .map(|path| std::env::split_paths(&path).collect::<Vec<_>>())
        .unwrap_or_default();

    on_path
        .into_iter()
        .chain(candidates())
        .map(|folder| folder.join(executable()))
        .find(|path| path.is_file())
}

/// PATH for docker's own helpers (credential helpers, Compose), which sit next to it.
fn search_path(docker: &std::path::Path) -> OsString {
    let mut folders: Vec<PathBuf> = docker.parent().map(PathBuf::from).into_iter().collect();

    if let Some(path) = std::env::var_os("PATH") {
        folders.extend(std::env::split_paths(&path));
    }

    folders.extend(candidates());

    std::env::join_paths(folders).unwrap_or_default()
}

/// A `docker` command, ready to run: no console window on Windows, killed if the app stops waiting for it.
pub fn command<I, S>(args: I) -> Result<Command, String>
where
    I: IntoIterator<Item = S>,
    S: AsRef<std::ffi::OsStr>,
{
    let docker = find().ok_or_else(|| "Docker isn't installed on this computer.".to_string())?;
    let mut command = Command::new(&docker);

    command
        .args(args)
        .env("PATH", search_path(&docker))
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true);

    #[cfg(windows)]
    command.creation_flags(0x0800_0000);

    Ok(command)
}

/// What a finished command printed.
#[derive(Debug, Default)]
pub struct Output {
    pub code: i32,
    pub stdout: String,
    pub stderr: String,
}

impl Output {
    pub fn ok(&self) -> bool {
        self.code == 0
    }

    /// The error, for a message: stderr's last line, else its exit code.
    pub fn error(&self) -> String {
        self.stderr
            .lines()
            .rev()
            .find(|line| !line.trim().is_empty())
            .map(|line| line.trim().to_string())
            .unwrap_or_else(|| format!("docker exited with {}", self.code))
    }
}

/// Run a `docker` command to the end, with values for the environment variables it's given by name.
pub async fn run(
    args: &[String],
    env: &BTreeMap<String, String>,
    timeout: Duration,
) -> Result<Output, String> {
    let mut command = command(args)?;
    command.envs(env);

    let output = tokio::time::timeout(timeout, command.output())
        .await
        .map_err(|_| {
            format!(
                "docker {} took too long",
                args.first().map(String::as_str).unwrap_or("")
            )
        })?
        .map_err(|error| error.to_string())?;

    Ok(Output {
        code: output.status.code().unwrap_or(1),
        stdout: String::from_utf8_lossy(&output.stdout).into_owned(),
        stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
    })
}

fn strings(args: &[&str]) -> Vec<String> {
    args.iter().map(|arg| arg.to_string()).collect()
}

async fn quick(args: &[&str]) -> Result<Output, String> {
    run(&strings(args), &BTreeMap::new(), Duration::from_secs(30)).await
}

/// Only the app's own sandbox containers: names it gave them, nothing that could pass for an option.
pub fn check_name(name: &str) -> Result<(), String> {
    let rest = name.strip_prefix(PREFIX).unwrap_or("");
    let valid = !rest.is_empty()
        && name.len() <= 128
        && rest
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.'));

    if valid {
        Ok(())
    } else {
        Err(format!(
            "Only OneDrop's own containers ({PREFIX}…) can be used."
        ))
    }
}

/// An environment variable's name, as `docker run --env NAME` takes it (the value comes from the environment).
pub fn check_env_name(name: &str) -> Result<(), String> {
    let mut chars = name.chars();
    let valid = chars
        .next()
        .is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
        && chars.all(|c| c.is_ascii_alphanumeric() || c == '_');

    if valid {
        Ok(())
    } else {
        Err(format!("{name:?} isn't an environment variable's name."))
    }
}

/// The ports a sandbox listens on.
#[derive(Debug, Clone, Default, serde::Deserialize)]
pub struct Ports {
    pub app: u16,
    pub proxy: Option<u16>,
    pub shell: Option<u16>,
    pub ssh: Option<u16>,
}

/// A free port on 127.0.0.1 right now.
pub fn free_port() -> Option<u16> {
    std::net::TcpListener::bind("127.0.0.1:0")
        .ok()?
        .local_addr()
        .ok()
        .map(|address| address.port())
}

/// Where Docker publishes a container port: a free host port picked now, so the container keeps it across restarts.
fn binding(port: u16, host_port: Option<u16>) -> String {
    match host_port {
        Some(host) => format!("127.0.0.1:{host}:{port}"),
        None => format!("127.0.0.1::{port}"),
    }
}

/// `docker run`'s arguments for a sandbox, as `DockerSandboxProvider::create()` makes them: the env's values aren't
/// in them (docker reads them from its own environment, so secrets stay out of the process list).
pub fn run_args(
    name: &str,
    image: &str,
    env_names: &[&str],
    ports: &Ports,
    device: u64,
    mut host_port: impl FnMut() -> Option<u16>,
) -> Vec<String> {
    let mut args = strings(&["run", "--detach", "--name", name]);
    args.extend(strings(&["--label", &format!("{LABEL}=1")]));
    args.extend(strings(&["--label", &format!("{DEVICE_LABEL}={device}")]));
    // A real init as PID 1 passes `docker stop` on, so the container stops at once instead of being killed.
    args.push("--init".into());
    args.extend(strings(&["--publish", &binding(ports.app, host_port())]));
    args.extend(strings(&["--env", &format!("PORT={}", ports.app)]));
    // Linux Docker doesn't define host.docker.internal.
    args.extend(strings(&[
        "--add-host",
        "host.docker.internal:host-gateway",
    ]));

    for (port, name) in [
        (ports.proxy, "PROXY_PORT"),
        (ports.shell, "SHELL_PORT"),
        (ports.ssh, "SSH_PORT"),
    ] {
        if let Some(port) = port {
            args.extend(strings(&["--publish", &binding(port, host_port())]));
            args.extend(strings(&["--env", &format!("{name}={port}")]));
        }
    }

    for name in env_names {
        args.extend(strings(&["--env", name]));
    }

    args.push(image.into());

    args
}

/// Create and start a project's sandbox; its id is its name.
pub async fn create(
    name: &str,
    image: &str,
    env: &BTreeMap<String, String>,
    ports: &Ports,
    device: u64,
) -> Result<String, String> {
    check_name(name)?;

    for key in env.keys() {
        check_env_name(key)?;
    }

    let names: Vec<&str> = env.keys().map(String::as_str).collect();
    let args = run_args(name, image, &names, ports, device, free_port);
    // Pulling the image on first use can take a while.
    let output = run(&args, env, Duration::from_secs(1800)).await?;

    if !output.ok() {
        return Err(output.error());
    }

    Ok(name.to_string())
}

/// Unpause or start the container, saying whether it had to.
pub async fn wake(id: &str) -> Result<bool, String> {
    check_name(id)?;
    let state = quick(&["inspect", "--format", "{{.State.Status}}", id]).await?;

    if !state.ok() {
        return Err(state.error());
    }

    let action = match state.stdout.trim() {
        "paused" => "unpause",
        "exited" | "created" => "start",
        _ => return Ok(false),
    };
    let output = quick(&[action, id]).await?;

    if output.ok() {
        Ok(true)
    } else {
        Err(output.error())
    }
}

/// Stop the container, keeping its files.
pub async fn stop(id: &str) -> Result<(), String> {
    check_name(id)?;
    let output = quick(&["stop", "--time", STOP_SECONDS, id]).await?;

    if output.ok() {
        Ok(())
    } else {
        Err(output.error())
    }
}

/// Freeze the container's processes; one already paused or stopped is fine.
pub async fn suspend(id: &str) -> Result<(), String> {
    check_name(id)?;
    let output = quick(&["pause", id]).await?;

    if output.ok()
        || output.stderr.contains("already paused")
        || output.stderr.contains("is not running")
    {
        Ok(())
    } else {
        Err(output.error())
    }
}

/// Remove the container and its volumes; one already gone is fine.
pub async fn destroy(id: &str) -> Result<(), String> {
    check_name(id)?;
    let output = quick(&["rm", "--force", "--volumes", id]).await?;

    if output.ok() || output.stderr.contains("No such container") {
        Ok(())
    } else {
        Err(output.error())
    }
}

/// `docker exec`'s arguments: detached or not, the env by name, as a given user when there is one.
pub fn exec_args(
    id: &str,
    command: &[String],
    env_names: &[&str],
    detach: bool,
    user: Option<&str>,
) -> Vec<String> {
    let mut args = vec!["exec".to_string()];

    if detach {
        args.push("--detach".into());
    }

    if let Some(user) = user {
        args.extend(["--user".to_string(), user.to_string()]);
    }

    for name in env_names {
        args.extend(["--env".to_string(), name.to_string()]);
    }

    args.push(id.into());
    args.extend(command.iter().cloned());

    args
}

/// Run a command in the container (as its own user, `sandbox`, unless told), waking it first if it's paused.
pub async fn exec(
    id: &str,
    command: &[String],
    env: &BTreeMap<String, String>,
    detach: bool,
    user: Option<&str>,
    timeout: Duration,
) -> Result<Output, String> {
    check_name(id)?;

    if command.is_empty() {
        return Err("No command to run.".into());
    }

    for key in env.keys() {
        check_env_name(key)?;
    }

    let names: Vec<&str> = env.keys().map(String::as_str).collect();
    let args = exec_args(id, command, &names, detach, user);
    let output = run(&args, env, timeout).await?;

    if !output.ok() && output.stderr.contains("is paused") {
        wake(id).await?;

        return run(&args, env, timeout).await;
    }

    Ok(output)
}

/// Whether the container was made from an older image than the one the server names.
pub async fn outdated(id: &str, image: &str) -> Result<bool, String> {
    check_name(id)?;
    let current = quick(&["image", "inspect", "--format", "{{.Id}}", image]).await?;
    let used = quick(&["inspect", "--format", "{{.Image}}", id]).await?;

    // No image or no container: nothing to update to (or from).
    if !current.ok() || !used.ok() {
        return Ok(false);
    }

    Ok(current.stdout.trim() != used.stdout.trim())
}

/// `docker cp`, writing a tar of the folder's contents to stdout.
pub fn copy_out_command(id: &str, path: &str) -> Result<Command, String> {
    check_name(id)?;
    check_path(path)?;

    command([
        "cp".to_string(),
        format!("{id}:{}/.", path.trim_end_matches('/')),
        "-".to_string(),
    ])
}

/// A container's tar unpacking stdin at `path`, as root: owned by root for installed files, else handed to the
/// sandbox user afterwards (finish_copy_in()).
pub fn copy_in_command(id: &str, path: &str, root: bool) -> Result<Command, String> {
    check_name(id)?;
    check_path(path)?;

    let mut args = vec!["exec", "-i", "-u", "root", id, "tar", "-x"];

    if root {
        args.push("--no-same-owner");
    }

    args.extend(["-C", path]);
    let mut command = command(args)?;
    command.stdin(Stdio::piped());

    Ok(command)
}

/// Before files go in for the sandbox user: make the folder.
pub async fn prepare_copy_in(id: &str, path: &str) -> Result<(), String> {
    check_name(id)?;
    check_path(path)?;
    wake(id).await?;
    let output = quick(&["exec", "-u", "root", id, "mkdir", "-p", path]).await?;

    if output.ok() {
        Ok(())
    } else {
        Err(output.error())
    }
}

/// The folders to hand back to the sandbox user after copying into `path`: the user's own root it's under (mkdir -p
/// ran as root, so parents it made are root's too), else the path itself.
pub fn owned_roots(path: &str) -> Vec<String> {
    let roots: Vec<String> = ["/workspace", "/data/storage", "/home/sandbox"]
        .into_iter()
        .filter(|root| path.starts_with(root))
        .map(String::from)
        .collect();

    if roots.is_empty() {
        vec![path.to_string()]
    } else {
        roots
    }
}

/// After files went in for the sandbox user: they're the user's.
pub async fn finish_copy_in(id: &str, path: &str) -> Result<(), String> {
    let mut args = strings(&["exec", "-u", "root", id, "chown", "-R", "sandbox:sandbox"]);
    args.extend(owned_roots(path));
    let output = run(&args, &BTreeMap::new(), Duration::from_secs(300)).await?;

    if output.ok() {
        Ok(())
    } else {
        Err(output.error())
    }
}

fn check_path(path: &str) -> Result<(), String> {
    if path.starts_with('/') && !path.split('/').any(|part| part == "..") {
        Ok(())
    } else {
        Err(format!("{path:?} isn't a folder in the sandbox."))
    }
}

/// The host port in `docker port`'s answer, e.g. "127.0.0.1:55012" (first line; IPv6 bindings may follow).
pub fn parse_binding(output: &str) -> Option<u16> {
    let line = output.lines().next()?.trim();

    line.rsplit_once(':')?.1.parse().ok()
}

/// Where the container's port is published on this computer.
pub async fn published_port(id: &str, port: u16) -> Result<u16, String> {
    check_name(id)?;
    let output = quick(&["port", id, &port.to_string()]).await?;

    if !output.ok() {
        return Err(output.error());
    }

    parse_binding(&output.stdout)
        .ok_or_else(|| format!("The sandbox's port {port} isn't published."))
}

/// A variable's value in `docker inspect`'s list of a container's env (NAME=value per line).
pub fn env_value(lines: &str, name: &str) -> Option<String> {
    lines.lines().find_map(|line| {
        line.strip_prefix(name)?
            .strip_prefix('=')
            .map(str::to_owned)
    })
}

/// The container's own proxy port (where `/__onedrop/tunnel` is), as published on this computer.
pub async fn proxy_port(id: &str) -> Result<u16, String> {
    check_name(id)?;
    let env = quick(&[
        "inspect",
        "--format",
        "{{range .Config.Env}}{{println .}}{{end}}",
        id,
    ])
    .await?;

    if !env.ok() {
        return Err(env.error());
    }

    let port = env_value(&env.stdout, "PROXY_PORT")
        .and_then(|port| port.parse().ok())
        .unwrap_or(8081);

    published_port(id, port).await
}

/// The sandbox containers of this computer's sign-in that are running.
pub async fn running(device: u64) -> Vec<String> {
    let filter = format!("label={DEVICE_LABEL}={device}");
    let Ok(output) = run(
        &strings(&["ps", "--filter", &filter, "--format", "{{.Names}}"]),
        &BTreeMap::new(),
        Duration::from_secs(5),
    )
    .await
    else {
        return vec![];
    };

    output
        .stdout
        .lines()
        .map(str::trim)
        .filter(|name| check_name(name).is_ok())
        .map(String::from)
        .collect()
}

/// Stop the containers (quitting the app, DESK-011).
pub async fn stop_all(names: &[String]) {
    if names.is_empty() {
        return;
    }

    let mut args = strings(&["stop", "--time", STOP_SECONDS]);
    args.extend(names.iter().cloned());
    let _ = run(&args, &BTreeMap::new(), Duration::from_secs(20)).await;
}

/// Docker on this computer, for the bridge.
#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DockerStatus {
    pub state: &'static str,
    pub detail: Option<String>,
    pub progress: Option<f64>,
    pub has_image: bool,
}

/// What `docker version` says the engine is: "Docker Desktop 4.44.0", else "Docker Engine 28.3.2".
pub fn describe(version: &str) -> String {
    let (platform, engine) = version
        .trim()
        .split_once('|')
        .unwrap_or((version.trim(), ""));
    let platform = platform.split(" (").next().unwrap_or("").trim();

    if !platform.is_empty() && platform.to_lowercase().contains("desktop") {
        platform.to_string()
    } else if !engine.trim().is_empty() {
        format!("Docker Engine {}", engine.trim())
    } else {
        "Docker".into()
    }
}

/// Whether Docker is there and running, and whether the image is.
pub async fn status(image: &str) -> DockerStatus {
    if find().is_none() {
        return DockerStatus {
            state: "missing",
            detail: Some("Docker isn't installed. Install Docker Desktop, OrbStack or Colima, then come back.".into()),
            progress: None,
            has_image: false,
        };
    }

    let version = run(
        &strings(&[
            "version",
            "--format",
            "{{.Server.Platform.Name}}|{{.Server.Version}}",
        ]),
        &BTreeMap::new(),
        Duration::from_secs(10),
    )
    .await;

    match version {
        Ok(output) if output.ok() => {
            let has_image = quick(&["image", "inspect", "--format", "{{.Id}}", image])
                .await
                .map(|output| output.ok())
                .unwrap_or(false);

            DockerStatus {
                state: "ready",
                detail: Some(describe(&output.stdout)),
                progress: None,
                has_image,
            }
        }
        _ => DockerStatus {
            state: "stopped",
            detail: Some(
                "Docker isn't running. Start Docker Desktop (or OrbStack, Colima), then try again."
                    .into(),
            ),
            progress: None,
            has_image: false,
        },
    }
}

/// How far `docker pull` is, from the lines it prints without a terminal ("<layer>: Pull complete", …).
#[derive(Debug, Default)]
pub struct PullProgress {
    layers: BTreeMap<String, f64>,
}

impl PullProgress {
    /// Take one line of output.
    pub fn feed(&mut self, line: &str) {
        let Some((layer, status)) = line.trim().split_once(": ") else {
            return;
        };

        if layer.len() < 12 || !layer.chars().all(|c| c.is_ascii_hexdigit()) {
            return;
        }

        let done = match status
            .split_whitespace()
            .take(2)
            .collect::<Vec<_>>()
            .join(" ")
            .as_str()
        {
            "Pulling fs" | "Waiting" => 0.0,
            "Downloading" => 0.1,
            "Verifying Checksum" | "Download complete" => 0.6,
            "Extracting" => 0.7,
            "Pull complete" | "Already exists" => 1.0,
            _ => return,
        };
        let layer = self.layers.entry(layer.to_string()).or_insert(0.0);

        *layer = layer.max(done);
    }

    /// 0..1 across the layers seen so far.
    pub fn fraction(&self) -> f64 {
        if self.layers.is_empty() {
            return 0.0;
        }

        self.layers.values().sum::<f64>() / self.layers.len() as f64
    }
}

/// Download the image, reporting progress as it goes.
pub async fn pull(image: &str, mut progress: impl FnMut(f64)) -> Result<(), String> {
    let mut child = command(["pull", image])?
        .spawn()
        .map_err(|error| error.to_string())?;
    let stdout = child.stdout.take().expect("piped stdout");
    let stderr = child.stderr.take().expect("piped stderr");
    let errors = tokio::spawn(async move {
        let mut text = String::new();
        let mut lines = BufReader::new(stderr).lines();

        while let Ok(Some(line)) = lines.next_line().await {
            text.push_str(&line);
            text.push('\n');
        }

        text
    });
    let mut lines = BufReader::new(stdout).lines();
    let mut seen = PullProgress::default();

    while let Ok(Some(line)) = lines.next_line().await {
        seen.feed(&line);
        progress(seen.fraction());
    }

    let status = child.wait().await.map_err(|error| error.to_string())?;
    let stderr = errors.await.unwrap_or_default();

    if status.success() {
        Ok(())
    } else {
        Err(Output {
            code: status.code().unwrap_or(1),
            stdout: String::new(),
            stderr,
        }
        .error())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_onedrops_containers_are_used() {
        assert!(check_name("onedrop-project-9-ab12cd").is_ok());
        assert!(check_name("onedrop-project-").is_err());
        assert!(check_name("postgres").is_err());
        assert!(check_name("onedrop-project-9 --privileged").is_err());
        assert!(check_name("onedrop-project-9/../x").is_err());
    }

    #[test]
    fn env_names_are_names() {
        assert!(check_env_name("ONEDROP_TOKEN").is_ok());
        assert!(check_env_name("_X1").is_ok());
        assert!(check_env_name("A=B").is_err());
        assert!(check_env_name("1A").is_err());
        assert!(check_env_name("").is_err());
    }

    #[test]
    fn a_sandbox_runs_like_on_a_server() {
        let ports = Ports {
            app: 8000,
            proxy: Some(8081),
            shell: Some(7681),
            ssh: None,
        };
        let mut next = 50000;
        let args = run_args(
            "onedrop-project-9-ab",
            "img:1",
            &["SECRET"],
            &ports,
            4,
            || {
                next += 1;
                Some(next)
            },
        );
        let line = args.join(" ");

        assert!(line.starts_with("run --detach --name onedrop-project-9-ab --label onedrop.sandbox=1 --label onedrop.device=4 --init"));
        assert!(line.contains("--publish 127.0.0.1:50001:8000 --env PORT=8000"));
        assert!(line.contains("--add-host host.docker.internal:host-gateway"));
        assert!(line.contains("--publish 127.0.0.1:50002:8081 --env PROXY_PORT=8081"));
        assert!(line.contains("--publish 127.0.0.1:50003:7681 --env SHELL_PORT=7681"));
        assert!(!line.contains("SSH_PORT"));
        // Only the name: the value comes from docker's environment.
        assert!(line.ends_with("--env SECRET img:1"));
    }

    #[test]
    fn exec_passes_env_by_name() {
        let args = exec_args(
            "onedrop-project-1-a",
            &["bash".into(), "-lc".into(), "ls".into()],
            &["TOKEN"],
            true,
            None,
        );

        assert_eq!(
            args.join(" "),
            "exec --detach --env TOKEN onedrop-project-1-a bash -lc ls"
        );
    }

    #[test]
    fn copies_hand_back_the_users_folders() {
        assert_eq!(owned_roots("/workspace/app"), vec!["/workspace"]);
        assert_eq!(owned_roots("/opt/onedrop"), vec!["/opt/onedrop"]);
        assert!(check_path("/workspace/../etc").is_err());
        assert!(check_path("workspace").is_err());
    }

    #[test]
    fn published_ports_are_read() {
        assert_eq!(parse_binding("127.0.0.1:55012\n[::1]:55012\n"), Some(55012));
        assert_eq!(parse_binding(""), None);
        assert_eq!(
            env_value("PATH=/usr/bin\nPROXY_PORT=8081\n", "PROXY_PORT").as_deref(),
            Some("8081")
        );
        assert_eq!(env_value("PROXY_PORTS=1\n", "PROXY_PORT"), None);
    }

    #[test]
    fn the_engine_is_named() {
        assert_eq!(
            describe("Docker Desktop 4.44.0 (199162)|28.3.2\n"),
            "Docker Desktop 4.44.0"
        );
        assert_eq!(
            describe("Docker Engine - Community|28.3.2"),
            "Docker Engine 28.3.2"
        );
        assert_eq!(describe("|27.0.1"), "Docker Engine 27.0.1");
    }

    #[test]
    fn pull_progress_counts_layers() {
        let mut progress = PullProgress::default();

        progress.feed("latest: Pulling from onedrop-io/onedrop-sandbox");
        progress.feed("a1b2c3d4e5f6: Pulling fs layer");
        progress.feed("b1b2c3d4e5f6: Already exists");
        assert_eq!(progress.fraction(), 0.5);

        progress.feed("a1b2c3d4e5f6: Download complete");
        progress.feed("a1b2c3d4e5f6: Waiting");
        assert_eq!(progress.fraction(), 0.8);

        progress.feed("a1b2c3d4e5f6: Pull complete");
        progress.feed("Digest: sha256:0123");
        progress
            .feed("Status: Downloaded newer image for ghcr.io/onedrop-io/onedrop-sandbox:latest");
        assert_eq!(progress.fraction(), 1.0);
    }
}
