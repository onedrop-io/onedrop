// Loaded into the Shell tab's ttyd page (see the Dockerfile). The Shell is another origin, so the workspace
// never sees its keys or styles; this hands it the workspace shortcuts typed in the terminal instead of the
// shell, gives the terminal the workspace's thin, rounded scrollbar, and takes the workspace's color theme: the
// workspace posts the theme's terminal palette (SET-002) when the page loads and whenever the theme changes.
// Cmd+P on a Mac, Ctrl+P elsewhere, opens "Go to file" (FILE-006). Ctrl+P on a Mac stays the shell's
// "previous command".
(() => {
    const style = document.createElement('style');
    style.textContent = `
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track, ::-webkit-scrollbar-corner { background: transparent; }
        :root { --thumb: rgb(255 255 255 / 0.14); --thumb-hover: rgb(255 255 255 / 0.26); color-scheme: dark; }
        :root.light { --thumb: rgb(0 0 0 / 0.18); --thumb-hover: rgb(0 0 0 / 0.32); color-scheme: light; }
        ::-webkit-scrollbar-thumb { border: 2px solid transparent; border-radius: 9999px; background: var(--thumb) padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: var(--thumb-hover) padding-box; }
        @supports not selector(::-webkit-scrollbar) {
            * { scrollbar-width: thin; scrollbar-color: var(--thumb) transparent; }
        }
    `;
    document.head.append(style);

    // ttyd makes its xterm a moment after the page loads and exposes it as window.term; until then the theme waits.
    let theme = null;
    let waiting = null;

    const applyTheme = () => {
        if (!window.term) {
            waiting ??= setInterval(applyTheme, 100);

            return;
        }

        clearInterval(waiting);
        waiting = null;
        window.term.options.theme = theme.colors;
        document.documentElement.classList.toggle('light', theme.kind === 'light');
        document.body.style.background = theme.colors.background;
    };

    window.addEventListener('message', (event) => {
        if (
            event.source === window.parent &&
            event.data?.onedrop === 'theme' &&
            event.data.colors
        ) {
            theme = event.data;
            applyTheme();
        }
    });

    const mac = /Mac|iPhone|iPad/.test(navigator.platform);

    window.addEventListener(
        'keydown',
        (event) => {
            const modifier = mac
                ? event.metaKey && !event.ctrlKey
                : event.ctrlKey && !event.metaKey;

            if (
                modifier &&
                !event.shiftKey &&
                !event.altKey &&
                event.key.toLowerCase() === 'p'
            ) {
                event.preventDefault();
                event.stopImmediatePropagation();
                window.parent.postMessage({ onedrop: 'quick-open' }, '*');
            }
        },
        true,
    );
})();
