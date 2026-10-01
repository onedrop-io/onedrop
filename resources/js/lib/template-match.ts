import type { CatalogTemplate } from '@/types';

/** Words that say nothing about which app someone wants. */
const STOPWORDS = new Set(
    (
        'the and for with that this from into our your their them they want need like make build create app apps ' +
        'application simple small basic easy just some something thing things where which what when who can could ' +
        'should would will let lets have has using use used track keep manage managing tool tools system platform ' +
        'website site page web online open source self hosted team teams company business people everyone also ' +
        'all any each every more most new way one two help please list lists'
    ).split(' '),
);

/** Matches shown under the prompt at most. */
const LIMIT = 3;

/**
 * What a typed word is worth by where it appears. A template needs this much in all, from a word in its name or from
 * two or more words, since a single tag or description word ("game", "family") is too loose a hint.
 */
const NAME = 3;
const TAG = 2;
/** A built-in template's short description is written by us, so it says what the app is as well as a name. */
const SUMMARY = NAME;
const DESCRIPTION = 1;

const stem = (word: string) => {
    const stemmed = word.replace(/(ings?|ers?|ed|es|s)$/, '');

    return stemmed.length >= 3 ? stemmed : word;
};

export const words = (text: string) =>
    [
        ...new Set(
            text
                .toLowerCase()
                .split(/[^a-z0-9]+/)
                .filter((word) => word.length >= 3 && !STOPWORDS.has(word))
                .map(stem),
        ),
    ].filter(Boolean);

/** The same word, or one a letter or two longer ("invoic" and "invoice", "hir" and "hire"). */
const same = (a: string, b: string) => {
    const [short, long] = a.length <= b.length ? [a, b] : [b, a];
    const extra = long.length - short.length;

    return (
        short === long ||
        (long.startsWith(short) &&
            (short.length >= 4 ? extra <= 2 : extra === 1))
    );
};

type Indexed = {
    template: CatalogTemplate;
    label: string[];
    tags: string[];
    summary: string[];
    description: string[];
};

/** Each template's words, worked out once per list; a built-in one's description also has the prompt it fills in. */
export const indexTemplates = (templates: CatalogTemplate[]): Indexed[] =>
    templates.map((template) => {
        const builtIn = template.registry === null;

        return {
            template,
            label: words(template.label),
            tags: builtIn ? [] : words(template.tags.join(' ')),
            summary: builtIn ? words(template.description) : [],
            description: words(
                builtIn ? template.prompt : template.description,
            ),
        };
    });

/**
 * The templates that already do what someone described (PRJ-001): each word they typed counts for the best place it
 * appears (the name or a built-in one's summary, tags, then description), and only templates scoring as much as a name match are offered.
 * The order of `index` breaks ties, so built-in templates listed first win.
 */
export function matchTemplates(
    description: string,
    index: Indexed[],
): CatalogTemplate[] {
    const typed = words(description);

    if (typed.length === 0) {
        return [];
    }

    return index
        .map((entry, position) => {
            const has = (word: string, list: string[]) =>
                list.some((other) => same(word, other));
            let score = 0;
            let named = false;
            let matched = 0;

            for (const word of typed) {
                const worth = has(word, entry.label)
                    ? NAME
                    : has(word, entry.summary)
                      ? SUMMARY
                      : has(word, entry.tags)
                        ? TAG
                        : has(word, entry.description)
                          ? DESCRIPTION
                          : 0;

                named ||= worth === NAME;
                matched += worth > 0 ? 1 : 0;
                score += worth;
            }

            return {
                template: entry.template,
                score: named || matched >= 2 ? score : 0,
                position,
            };
        })
        .filter(({ score }) => score >= NAME)
        .sort((a, b) => b.score - a.score || a.position - b.position)
        .slice(0, LIMIT)
        .map(({ template }) => template);
}
