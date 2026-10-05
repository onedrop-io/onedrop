// No console window behind the app on Windows release builds.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    let args: Vec<String> = std::env::args().collect();

    // SSH's ProxyCommand (DESK-008): no window, just the connection.
    if args.get(1).map(String::as_str) == Some("ssh-proxy") {
        std::process::exit(onedrop_desktop_lib::ssh_proxy(&args[2..]));
    }

    onedrop_desktop_lib::run()
}
