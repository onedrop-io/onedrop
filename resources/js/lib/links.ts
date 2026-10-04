export const REPOSITORY_URL = 'https://github.com/onedrop-io/onedrop';

export const DOCUMENTATION_URL = 'https://docs.onedrop.io/introduction';

export const INSTALL_COMMAND = 'curl -fsSL https://onedrop.io/install | sh';

export const INSTALL_SERVER_COMMAND = `${INSTALL_COMMAND} -s -- --domain auto`;

export const INSTALL_DOMAIN_COMMAND = `${INSTALL_COMMAND} -s -- --domain onedrop.example.com`;

export const INSTALL_DOCS_URL = 'https://docs.onedrop.io/install';

export const DEMO_VIDEO_URL =
    'https://pub-c655146bc458440aa8c0969e063c9a4c.r2.dev/onedrop.mp4';

export const SKILLS_DOCS_URL = 'https://docs.onedrop.io/guides/agent-skills';

/** Where the desktop workflow keeps the newest installers, under names that don't change (DESK-004). */
const DESKTOP_DOWNLOADS_URL = `${REPOSITORY_URL}/releases/download/desktop-latest`;

export const DESKTOP_DOWNLOADS = {
    macAppleSilicon: `${DESKTOP_DOWNLOADS_URL}/OneDrop-mac-apple-silicon.dmg`,
    macIntel: `${DESKTOP_DOWNLOADS_URL}/OneDrop-mac-intel.dmg`,
    windows: `${DESKTOP_DOWNLOADS_URL}/OneDrop-windows-setup.exe`,
    linuxAppImage: `${DESKTOP_DOWNLOADS_URL}/OneDrop-linux.AppImage`,
    linuxDeb: `${DESKTOP_DOWNLOADS_URL}/OneDrop-linux.deb`,
};

export const DESKTOP_DOCS_URL = 'https://docs.onedrop.io/guides/desktop-app';
