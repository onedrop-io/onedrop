//! `onedrop://` links (DESK-011), opened in the running app: `onedrop://projects/12` is the project,
//! `onedrop://new` the new-project page. Anything else just brings the app forward.

use crate::{desktop, shortcut};
use tauri::{AppHandle, Url};

/// The app page for a link, and whether it's the new-project page; none for links the app doesn't know.
pub fn path(url: &Url) -> Option<String> {
    if url.scheme() != "onedrop" {
        return None;
    }

    // `onedrop://projects/12` has "projects" as its host; `onedrop:projects/12` has none.
    let whole = format!("{}{}", url.host_str().unwrap_or(""), url.path());
    let parts: Vec<&str> = whole.split('/').filter(|part| !part.is_empty()).collect();

    match parts.as_slice() {
        ["new"] => Some(shortcut::NEW_PROJECT.into()),
        ["projects", id, rest @ ..]
            if id.chars().all(|c| c.is_ascii_digit())
                && !id.is_empty()
                && rest.iter().all(|part| {
                    part.chars()
                        .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
                }) =>
        {
            Some(format!(
                "/projects/{id}{}",
                rest.iter()
                    .map(|part| format!("/{part}"))
                    .collect::<String>()
            ))
        }
        _ => None,
    }
}

/// Open links the system handed the app.
pub fn open(app: &AppHandle, urls: Vec<Url>) {
    for url in urls {
        match path(&url) {
            Some(path) if path == shortcut::NEW_PROJECT => shortcut::new_project(app),
            Some(path) => desktop::navigate(app, &path, None),
            None => desktop::show(app),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn to(link: &str) -> Option<String> {
        path(&Url::parse(link).unwrap())
    }

    #[test]
    fn links_open_projects_and_the_new_project_page() {
        assert_eq!(to("onedrop://projects/12").as_deref(), Some("/projects/12"));
        assert_eq!(
            to("onedrop://projects/12/").as_deref(),
            Some("/projects/12")
        );
        assert_eq!(
            to("onedrop://projects/12/board").as_deref(),
            Some("/projects/12/board")
        );
        assert_eq!(to("onedrop://new").as_deref(), Some("/dashboard"));
        assert_eq!(to("onedrop:new").as_deref(), Some("/dashboard"));
    }

    #[test]
    fn other_links_go_nowhere() {
        assert_eq!(to("onedrop://projects/abc"), None);
        assert_eq!(to("onedrop://settings/tokens"), None);
        assert_eq!(to("onedrop://projects/12/..%2F..%2Fx"), None);
        assert_eq!(to("https://projects/12"), None);
    }
}
