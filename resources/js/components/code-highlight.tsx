import { LanguageDescription } from '@codemirror/language';
import type { Language } from '@codemirror/language';
import { languages } from '@codemirror/language-data';
import { classHighlighter, highlightCode } from '@lezer/highlight';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';

/**
 * Code with syntax highlighting for the given language name (e.g. "php", "tsx").
 * Shows plain text until the language loads, or if it's unknown. Tokens get
 * `tok-*` classes, colored in app.css.
 */
export default function CodeHighlight({
    code,
    language,
}: {
    code: string;
    language: string;
}) {
    const [loaded, setLoaded] = useState<{
        name: string;
        language: Language;
    } | null>(null);

    useEffect(() => {
        let cancelled = false;

        LanguageDescription.matchLanguageName(languages, language, true)
            ?.load()
            .then((support) => {
                if (!cancelled) {
                    setLoaded({ name: language, language: support.language });
                }
            })
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [language]);

    if (loaded?.name !== language) {
        return code;
    }

    return highlight(code, loaded.language);
}

function highlight(code: string, language: Language): ReactNode[] {
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
