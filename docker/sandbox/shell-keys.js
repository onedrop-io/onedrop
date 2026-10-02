// Loaded into the Shell tab's ttyd page (see the Dockerfile). The Shell is another origin, so the workspace
// never sees its keys or styles; this hands it the workspace shortcuts typed in the terminal instead of the
// shell, and gives the terminal the workspace's thin, rounded dark scrollbar (the terminal is always dark).
// Cmd+P on a Mac, Ctrl+P elsewhere, opens "Go to file" (FILE-006). Ctrl+P on a Mac stays the shell's
// "previous command".
(() => {
    const style = document.createElement('style');
    style.textContent = `
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track, ::-webkit-scrollbar-corner { background: transparent; }
        ::-webkit-scrollbar-thumb { border: 2px solid transparent; border-radius: 9999px; background: rgb(255 255 255 / 0.14) padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: rgb(255 255 255 / 0.26) padding-box; }
        @supports not selector(::-webkit-scrollbar) {
            * { scrollbar-width: thin; scrollbar-color: rgb(255 255 255 / 0.14) transparent; }
        }
        html, body { color-scheme: dark; }
    `;
    document.head.append(style);

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
