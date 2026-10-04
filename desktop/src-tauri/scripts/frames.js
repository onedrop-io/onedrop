// Runs in every frame of the app's window, e.g. the sandbox's Shell.
(() => {
    const confirm = window.confirm.bind(window);
    const open = window.open.bind(window);
    const prefix = 'Do you want to navigate to ';

    // The Shell's terminal (xterm.js) asks before opening a terminal hyperlink, such as Claude Code's sign-in link.
    // The app's webview can't show that dialog and answers no, so clicking did nothing. Open the link straight
    // away instead (the app sends it to the user's browser, see open_in_browser), and tell the terminal not to.
    window.confirm = (message) => {
        if (typeof message === 'string' && message.startsWith(prefix)) {
            const link = message
                .slice(prefix.length)
                .split('?\n')[0]
                .replace(/\?$/, '');

            open(link, '_blank');

            return false;
        }

        return confirm(message);
    };
})();
