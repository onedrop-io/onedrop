/**
 * Papertrail-style log search: words must all appear (in any order), `"quoted phrase"` matches exactly,
 * `-word` (or `NOT word`) excludes, `OR` gives alternatives, and parentheses group. Matching ignores case.
 * Half-typed queries (an open quote or parenthesis) still work, so results follow along while typing.
 */
export type LogSearch = {
    /** Whether a line passes the search. */
    matches: (line: string) => boolean;
    /** The words and phrases to mark in the lines shown (lowercase, not the excluded ones). */
    terms: string[];
};

type Token =
    | { type: 'term'; value: string }
    | { type: 'or' | 'not' | 'open' | 'close' };

type Node =
    | { type: 'term'; value: string }
    | { type: 'not'; node: Node }
    | { type: 'and' | 'or'; nodes: Node[] };

/** The search for a query, or null when the query has nothing to search for. */
export function parseLogSearch(query: string): LogSearch | null {
    const tokens = tokenize(query);
    let position = 0;

    const parseOr = (): Node | null => {
        const nodes: Node[] = [];

        do {
            const node = parseAnd();

            if (node) {
                nodes.push(node);
            }
        } while (tokens[position]?.type === 'or' && ++position);

        return nodes.length > 1 ? { type: 'or', nodes } : (nodes[0] ?? null);
    };

    const parseAnd = (): Node | null => {
        const nodes: Node[] = [];

        while (
            position < tokens.length &&
            tokens[position].type !== 'or' &&
            tokens[position].type !== 'close'
        ) {
            const node = parseUnary();

            if (node) {
                nodes.push(node);
            }
        }

        return nodes.length > 1 ? { type: 'and', nodes } : (nodes[0] ?? null);
    };

    const parseUnary = (): Node | null => {
        const token = tokens[position++];

        if (token.type === 'not') {
            const node =
                position < tokens.length &&
                tokens[position].type !== 'or' &&
                tokens[position].type !== 'close'
                    ? parseUnary()
                    : null;

            return node ? { type: 'not', node } : null;
        }

        if (token.type === 'open') {
            const node = parseOr();

            if (tokens[position]?.type === 'close') {
                position++;
            }

            return node;
        }

        return token.type === 'term' ? token : null;
    };

    let root: Node | null = null;

    while (position < tokens.length) {
        const node = parseOr();

        // A stray `)` ends parseOr early; skip it and keep going.
        if (tokens[position]?.type === 'close') {
            position++;
        }

        if (node) {
            root = root ? { type: 'and', nodes: [root, node] } : node;
        }
    }

    if (!root) {
        return null;
    }

    const tree = root;

    return {
        matches: (line) => evaluate(tree, line.toLowerCase()),
        terms: [...new Set(positiveTerms(tree, false))],
    };
}

function tokenize(query: string): Token[] {
    const tokens: Token[] = [];
    const pattern = /"([^"]*)"?|\(|\)|-(?=\S)|[^\s()"]+/g;

    for (const match of query.matchAll(pattern)) {
        const text = match[0];

        if (match[1] !== undefined) {
            if (match[1].trim()) {
                tokens.push({ type: 'term', value: match[1].toLowerCase() });
            }
        } else if (text === '(') {
            tokens.push({ type: 'open' });
        } else if (text === ')') {
            tokens.push({ type: 'close' });
        } else if (
            (text === '-' && /\S/.test(query[match.index + 1] ?? '')) ||
            text === 'NOT'
        ) {
            tokens.push({ type: 'not' });
        } else if (text === 'OR') {
            tokens.push({ type: 'or' });
        } else if (text !== 'AND') {
            tokens.push({ type: 'term', value: text.toLowerCase() });
        }
    }

    return tokens;
}

function evaluate(node: Node, line: string): boolean {
    switch (node.type) {
        case 'term':
            return line.includes(node.value);
        case 'not':
            return !evaluate(node.node, line);
        case 'and':
            return node.nodes.every((child) => evaluate(child, line));
        case 'or':
            return node.nodes.some((child) => evaluate(child, line));
    }
}

function positiveTerms(node: Node, negated: boolean): string[] {
    switch (node.type) {
        case 'term':
            return negated ? [] : [node.value];
        case 'not':
            return positiveTerms(node.node, !negated);
        default:
            return node.nodes.flatMap((child) => positiveTerms(child, negated));
    }
}
