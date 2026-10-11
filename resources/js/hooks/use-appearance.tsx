import { useSyncExternalStore } from 'react';
import type { ThemeKind } from '@/lib/themes';
import { DEFAULT_THEMES, findTheme, themeOfKind } from '@/lib/themes';

export type ResolvedAppearance = ThemeKind;
export type Appearance = ResolvedAppearance | 'system';

/** The mode (light, dark, system) and the theme picked for each kind; system switches between the two (SET-002). */
type AppearanceState = {
    readonly appearance: Appearance;
    readonly themes: Readonly<Record<ThemeKind, string>>;
};

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    /** The light and dark theme picked: which one shows follows the mode. */
    readonly themes: Readonly<Record<ThemeKind, string>>;
    /** The theme showing now. */
    readonly resolvedTheme: string;
    readonly updateAppearance: (mode: Appearance) => void;
    /** Picks a theme for its kind, and switches to that kind unless the mode is system. */
    readonly updateTheme: (id: string) => void;
};

const listeners = new Set<() => void>();
let state: AppearanceState = { appearance: 'dark', themes: DEFAULT_THEMES };
const serverState: AppearanceState = state;

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

/** Saved in localStorage for the app, and a cookie so the server renders the page in it (no flash). */
const store = (name: string, value: string): void => {
    localStorage.setItem(name, value);
    setCookie(name, value);
};

const getStoredState = (): AppearanceState => ({
    appearance: (localStorage.getItem('appearance') as Appearance) || 'dark',
    themes: {
        light: themeOfKind(localStorage.getItem('theme-light'), 'light'),
        dark: themeOfKind(localStorage.getItem('theme-dark'), 'dark'),
    },
});

const resolveKind = (appearance: Appearance): ThemeKind =>
    appearance === 'dark' || (appearance === 'system' && prefersDark())
        ? 'dark'
        : 'light';

const applyTheme = (): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const kind = resolveKind(state.appearance);

    document.documentElement.classList.toggle('dark', kind === 'dark');
    document.documentElement.style.colorScheme = kind;
    document.documentElement.dataset.theme = state.themes[kind];
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const setState = (next: AppearanceState): void => {
    state = next;
    applyTheme();
    notify();
};

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = (): void => {
    applyTheme();
    notify();
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem('appearance')) {
        store('appearance', 'dark');
    }

    state = getStoredState();
    applyTheme();

    // Set up system theme change listener
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const { appearance, themes } = useSyncExternalStore(
        subscribe,
        () => state,
        () => serverState,
    );

    const resolvedAppearance = resolveKind(appearance);

    const updateAppearance = (mode: Appearance): void => {
        store('appearance', mode);
        setState({ ...state, appearance: mode });
    };

    const updateTheme = (id: string): void => {
        const theme = findTheme(id);

        if (!theme) {
            return;
        }

        store(`theme-${theme.kind}`, theme.id);

        const mode = state.appearance === 'system' ? 'system' : theme.kind;

        if (mode !== state.appearance) {
            store('appearance', mode);
        }

        setState({
            appearance: mode,
            themes: { ...state.themes, [theme.kind]: theme.id },
        });
    };

    return {
        appearance,
        resolvedAppearance,
        themes,
        resolvedTheme: themes[resolvedAppearance],
        updateAppearance,
        updateTheme,
    } as const;
}
