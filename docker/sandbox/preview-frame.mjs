// The preview is the app inside the workspace's frame. Some apps look for a frame in their own code and refuse to
// run in one: NocoDB answers "Not allowed" to everything but its shared views. Dropping X-Frame-Options can't help
// with that, so preview pages and scripts get those checks rewritten to "not in a frame", and the app runs as it
// does in its own tab. Published addresses are left alone. host-proxy.mjs uses this.

// The page's own window, and the top one: `self !== top`, `window.top !== window.self`, `window !== window.top`, and
// the same with `.location` on both sides. `window.parent !== window` isn't one: apps (and the preview's own script)
// use it to talk to the frame around them, not to refuse it.
const SELF = String.raw`(?:window\.self|window|self)`;
const TOP = String.raw`(?:window\.top|self\.top|top)`;
const OPERATOR = String.raw`\s*(!==?|===?)\s*`;
// Only whole comparisons: after something that starts an expression, before something that ends one, so `a + self
// !== top`, `top.frames` or the words in a string are never touched.
const BEFORE = String.raw`(?<=(?:^|[\n(,;{}\[?:]|&&|\|\||(?:^|[^=!<>])=|=>|\breturn)\s*)(?<![\w$.])`;
const AFTER = String.raw`(?=[ \t]*(?:\r?\n|$)|\s*(?:[),;}\]:]|\?(?!\.)|&&|\|\|))`;

const CHECK = new RegExp(
    [
        `${SELF}${OPERATOR}${TOP}`,
        `${TOP}${OPERATOR}${SELF}`,
        `${SELF}\\.location${OPERATOR}${TOP}\\.location`,
        `${TOP}\\.location${OPERATOR}${SELF}\\.location`,
    ]
        .map((check) => `${BEFORE}${check}${AFTER}`)
        .join('|'),
    'g',
);

/** The page or script with every frame check answering "not in a frame". */
export function withoutFrameChecks(text) {
    return text.replace(CHECK, (...match) =>
        match.slice(1, 5).find(Boolean).startsWith('!') ? 'false' : 'true',
    );
}

// That rewrite also hides the frame from sign-in libraries that check it to pick their cookies' SameSite (Clerk's
// `self !== top`), and the workspace is on another site than the preview (GW-002) anyway: there a Lax cookie is
// dropped, and browsers that block third-party cookies keep only partitioned ones. So the app's own cookies, from
// its server and from its scripts, are made cross-site and partitioned; the app signs in inside the frame as it
// does in a tab. This function is also put into the preview's script as it is, so it uses nothing from outside.

/** A Set-Cookie value (or a `document.cookie` assignment) as a cross-site, partitioned cookie. */
export function crossSiteCookie(cookie) {
    const [pair, ...attributes] = String(cookie).split(';');
    const kept = attributes
        .map((attribute) => attribute.trim())
        .filter(
            (attribute) =>
                attribute !== '' &&
                !/^(samesite\s*=.*|secure|partitioned)$/i.test(attribute),
        );

    return [
        pair.trim(),
        ...kept,
        'Secure',
        'SameSite=None',
        'Partitioned',
    ].join('; ');
}
