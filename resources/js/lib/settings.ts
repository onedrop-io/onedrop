/** Pages that open inside the settings modal instead of the main content area. */
const SETTINGS_PAGE_PREFIXES = [
    'settings/',
    'organizations/',
    'groups/',
    'invitations/',
    'users/',
    'admin/',
];

const RETURN_URL_KEY = 'settings.returnTo';

export function isSettingsPage(component: string): boolean {
    return SETTINGS_PAGE_PREFIXES.some((prefix) =>
        component.startsWith(prefix),
    );
}

/** Remembers the last page outside settings so closing the modal goes back to it. */
export function rememberSettingsReturnUrl(url: string): void {
    sessionStorage.setItem(RETURN_URL_KEY, url);
}

export function settingsReturnUrl(): string | null {
    return sessionStorage.getItem(RETURN_URL_KEY);
}
