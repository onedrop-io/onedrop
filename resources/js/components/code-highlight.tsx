import { LanguageDescription } from '@codemirror/language';
import type { Language } from '@codemirror/language';
import { languages } from '@codemirror/language-data';
import { classHighlighter, highlightCode } from '@lezer/highlight';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';

/**
 * Code with syntax highlighting for the given language name (e.g. "php", "tsx").
 * Shows plain text until the language loads, or if it's unknown. Tokens get
 * `tok-*` classes, colored in app.css. Diffs use the same colors (patch-view.tsx).
 */
export default function CodeHighlight({
    code,
    language,
}: {
    code: string;
    language: string;
}) {
    const loaded = useLanguage(language, 'name');

    return loaded ? highlight(code, loaded) : code;
}

/**
 * The language for a name ("php", "tsx") or a file's name ("app/Models/User.php"), once it has loaded; null
 * until then, or if it's unknown.
 */
export function useLanguage(
    nameOrPath: string,
    by: 'name' | 'filename',
): Language | null {
    const [loaded, setLoaded] = useState<{
        key: string;
        language: Language;
    } | null>(null);

    useEffect(() => {
        let cancelled = false;
        const description =
            by === 'name'
                ? LanguageDescription.matchLanguageName(
                      languages,
                      nameOrPath,
                      true,
                  )
                : LanguageDescription.matchFilename(
                      languages,
                      nameOrPath.split('/').pop() ?? nameOrPath,
                  );

        description
            ?.load()
            .then((support) => {
                if (!cancelled) {
                    setLoaded({ key: nameOrPath, language: support.language });
                }
            })
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [nameOrPath, by]);

    return loaded?.key === nameOrPath ? loaded.language : null;
}

/** Code as highlighted text: spans with `tok-*` classes. */
export function highlight(code: string, language: Language): ReactNode[] {
    const nodes: ReactNode[] = [];
    // PHP's grammar treats text before `<?php` as HTML; snippets usually leave it out.
    const prefix =
        language.name === 'php' && !code.trimStart().startsWith('<')
            ? '<?php '
            : '';
    const source = prefix + code;

    highlightCode(
        source,
        language.parser.parse(source),
        classHighlighter,
        (text, classes) =>
            nodes.push(
                classes ? (
                    <span key={nodes.length} className={classes}>
                        {text}
                    </span>
                ) : (
                    text
                ),
            ),
        () => nodes.push('\n'),
        prefix.length,
    );

    return nodes;
}
