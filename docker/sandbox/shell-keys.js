// Loaded into the Shell tab's ttyd page (see the Dockerfile). The Shell is another origin, so the workspace
// never sees its keys; this hands it the workspace shortcuts typed in the terminal instead of the shell.
// Cmd+P on a Mac, Ctrl+P elsewhere, opens "Go to file" (FILE-006). Ctrl+P on a Mac stays the shell's
// "previous command".
(() => {
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
