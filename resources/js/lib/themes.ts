export type ThemeKind = 'light' | 'dark';

export type Theme = { id: string; name: string; kind: ThemeKind };

/**
 * The color themes users can pick (SET-002): OneDrop's own two, then ports of popular editor themes. Their colors
 * are in resources/css/themes.css, one block per id (a test checks).
 */
export const THEMES: Theme[] = [
    { id: 'onedrop-dark', name: 'OneDrop Dark', kind: 'dark' },
    { id: 'one-dark-pro', name: 'One Dark Pro', kind: 'dark' },
    { id: 'dracula', name: 'Dracula', kind: 'dark' },
    { id: 'github-dark', name: 'GitHub Dark', kind: 'dark' },
    { id: 'monokai', name: 'Monokai', kind: 'dark' },
    { id: 'nord', name: 'Nord', kind: 'dark' },
    { id: 'tokyo-night', name: 'Tokyo Night', kind: 'dark' },
    { id: 'catppuccin-mocha', name: 'Catppuccin Mocha', kind: 'dark' },
    { id: 'solarized-dark', name: 'Solarized Dark', kind: 'dark' },
    { id: 'gruvbox-dark', name: 'Gruvbox Dark', kind: 'dark' },
    { id: 'night-owl', name: 'Night Owl', kind: 'dark' },
    { id: 'rose-pine', name: 'Rosé Pine', kind: 'dark' },
    { id: 'onedrop-light', name: 'OneDrop Light', kind: 'light' },
    { id: 'github-light', name: 'GitHub Light', kind: 'light' },
    { id: 'one-light', name: 'One Light', kind: 'light' },
    { id: 'solarized-light', name: 'Solarized Light', kind: 'light' },
    { id: 'catppuccin-latte', name: 'Catppuccin Latte', kind: 'light' },
    { id: 'rose-pine-dawn', name: 'Rosé Pine Dawn', kind: 'light' },
];

export const DEFAULT_THEMES: Record<ThemeKind, string> = {
    dark: 'onedrop-dark',
    light: 'onedrop-light',
};

export function findTheme(id: string | null | undefined): Theme | undefined {
    return THEMES.find((theme) => theme.id === id);
}

/** The theme with this id if it's one of `kind`, else that kind's default. */
export function themeOfKind(
    id: string | null | undefined,
    kind: ThemeKind,
): string {
    return findTheme(id)?.kind === kind ? id! : DEFAULT_THEMES[kind];
}

const TERMINAL_COLORS = [
    'black',
    'red',
    'green',
    'yellow',
    'blue',
    'magenta',
    'cyan',
    'white',
] as const;

/** A CSS color (any syntax, color-mix included) as #rrggbb, by painting it; xterm only reads hex and rgb. */
function toHex(color: string, context: CanvasRenderingContext2D): string {
    context.clearRect(0, 0, 1, 1);
    context.fillStyle = '#000';
    context.fillStyle = color;
    context.fillRect(0, 0, 1, 1);
    const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

    return `#${[r, g, b].map((n) => n.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * The current theme's terminal palette, in xterm's shape, for the Shell (another origin, so it can't read our CSS;
 * docker/sandbox/shell-keys.js applies it).
 */
export function terminalTheme(): Record<string, string> {
    const styles = getComputedStyle(document.documentElement);
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const context = canvas.getContext('2d', { willReadFrequently: true })!;
    const read = (name: string) =>
        toHex(styles.getPropertyValue(`--terminal-${name}`).trim(), context);

    const theme: Record<string, string> = {
        background: read('background'),
        foreground: read('foreground'),
        cursor: read('cursor'),
        selectionBackground: read('selection'),
    };
    theme.cursorAccent = theme.background;

    for (const color of TERMINAL_COLORS) {
        theme[color] = read(color);
        theme[`bright${color[0].toUpperCase()}${color.slice(1)}`] = read(
            `bright-${color}`,
        );
    }

    return theme;
}
