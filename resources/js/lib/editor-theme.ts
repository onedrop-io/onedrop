import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { tags } from '@lezer/highlight';

const highlightStyle = HighlightStyle.define([
    {
        tag: [tags.keyword, tags.operator, tags.bool, tags.atom],
        color: 'var(--syntax-keyword)',
    },
    { tag: [tags.string, tags.inserted], color: 'var(--syntax-string)' },
    {
        tag: [tags.special(tags.string), tags.deleted, tags.invalid],
        color: 'var(--syntax-deleted)',
    },
    { tag: [tags.number, tags.literal], color: 'var(--syntax-number)' },
    {
        tag: [tags.className, tags.typeName, tags.attributeName],
        color: 'var(--syntax-type)',
    },
    {
        tag: [tags.comment, tags.meta],
        color: 'var(--syntax-comment)',
        fontStyle: 'italic',
    },
    {
        tag: [
            tags.definition(tags.variableName),
            tags.function(tags.variableName),
            tags.macroName,
        ],
        color: 'var(--syntax-function)',
    },
    { tag: tags.propertyName, color: 'var(--syntax-property)' },
    {
        tag: [
            tags.special(tags.variableName),
            tags.tagName,
            tags.namespace,
            tags.labelName,
        ],
        color: 'var(--syntax-tag)',
    },
    { tag: [tags.heading, tags.strong], fontWeight: '600' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: [tags.link, tags.url], textDecoration: 'underline' },
]);

/**
 * CodeMirror in the current color theme (SET-002): syntax, cursor and selection from the theme's variables
 * (themes.css), so it follows a theme change without being rebuilt. `dark` only picks CodeMirror's base styles.
 */
const themes = new Map<boolean, Extension>();

export function editorTheme(dark: boolean): Extension {
    if (!themes.has(dark)) {
        themes.set(dark, build(dark));
    }

    return themes.get(dark)!;
}

function build(dark: boolean): Extension {
    return [
        EditorView.theme(
            {
                '&': { color: 'var(--foreground)' },
                '.cm-content': { caretColor: 'var(--terminal-cursor)' },
                '.cm-cursor, .cm-dropCursor': {
                    borderLeftColor: 'var(--terminal-cursor)',
                },
                '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection':
                    { backgroundColor: 'var(--terminal-selection)' },
                '.cm-activeLine': { backgroundColor: 'var(--raised)' },
                '.cm-activeLineGutter': { backgroundColor: 'var(--raised)' },
                '.cm-gutters': {
                    color: 'var(--muted-foreground)',
                    border: 'none',
                },
                '.cm-tooltip': {
                    backgroundColor: 'var(--popover)',
                    color: 'var(--popover-foreground)',
                    border: '1px solid var(--edge-floating)',
                },
                '.cm-tooltip-autocomplete > ul > li[aria-selected]': {
                    backgroundColor: 'var(--accent)',
                    color: 'var(--accent-foreground)',
                },
                '.cm-panels': {
                    backgroundColor: 'var(--toolbar)',
                    color: 'var(--foreground)',
                },
                '.cm-searchMatch': {
                    backgroundColor:
                        'color-mix(in oklab, var(--syntax-type) 30%, transparent)',
                },
                '.cm-matchingBracket, .cm-nonmatchingBracket': {
                    backgroundColor: 'var(--terminal-selection)',
                },
            },
            { dark },
        ),
        syntaxHighlighting(highlightStyle),
    ];
}
