export type EnvEntry = { name: string; value: string };

/**
 * NAME=value pairs from pasted .env text: comments, blank lines and `export` are skipped,
 * quotes removed (double-quoted values may span lines and use \n, \" and \\ escapes).
 * A name that appears twice keeps its last value, in the position it first appeared.
 * Names aren't validated here, so a bad one can be shown to the user rather than dropped.
 */
export function parseDotenv(text: string): EnvEntry[] {
    const lines = text.replace(/\r\n?/g, '\n').split('\n');
    const entries = new Map<string, string>();

    for (let i = 0; i < lines.length; i++) {
        const match = lines[i].match(
            /^\s*(?:export\s+)?([^\s=#]+)\s*=\s?(.*)$/,
        );

        if (!match) {
            continue;
        }

        const [, name, raw] = match;
        const rest = raw.trimStart();
        let value: string;

        if (rest.startsWith('"')) {
            let body = rest.slice(1);
            let closed = body.match(/^((?:[^"\\]|\\.)*)"/s);

            // Keep reading lines until the closing, unescaped quote.
            while (!closed && i + 1 < lines.length) {
                body += '\n' + lines[++i];
                closed = body.match(/^((?:[^"\\]|\\.)*)"/s);
            }

            value = (closed ? closed[1] : body).replace(
                /\\(.)/g,
                (_, char: string) => (char === 'n' ? '\n' : char),
            );
        } else if (rest.startsWith("'")) {
            const end = rest.indexOf("'", 1);
            value = end === -1 ? rest.slice(1) : rest.slice(1, end);
        } else {
            value = rest.replace(/\s+#.*$/, '').trim();
        }

        entries.set(name, value);
    }

    return [...entries].map(([name, value]) => ({ name, value }));
}
