# Checking the app's SEO

Follow this when the user asks you to check (and maybe fix) their app's SEO. The app builder's
Tools → Growth panel shows the result you write to `/workspace/.onedrop/seo.json`, so stick to the contract below.

## How to check

1. Make sure the app runs (`curl -sf "localhost:$PORT" > /dev/null`), then fetch its public pages the way a
   search engine would: `curl -s "localhost:$PORT/"`, and each page linked from the home page and navigation.
   Read the source too, for pages the server renders only in the browser.
2. Only check pages anyone can open. Skip pages behind sign-in.
3. Go through the checks below. Base each one on what you actually saw, not on what the code seems to do.

| Check                      | Passes when                                                                                                                                                     |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Page titles                | Every page has a unique, descriptive `<title>` (roughly 10–60 characters).                                                                                      |
| Meta descriptions          | Every page has a `<meta name="description">` (roughly 50–160 characters).                                                                                       |
| Headings                   | Each page has exactly one `<h1>`, and headings go in order.                                                                                                     |
| Link previews              | Open Graph tags (`og:title`, `og:description`, `og:image`) and `twitter:card` are set.                                                                          |
| Language and viewport      | `<html lang="…">` and `<meta name="viewport">` are set.                                                                                                         |
| Image alt text             | Every meaningful `<img>` has an `alt`.                                                                                                                          |
| robots.txt                 | `/robots.txt` exists and doesn't block the whole site.                                                                                                          |
| Sitemap                    | `/sitemap.xml` exists, lists the public pages, and robots.txt points to it.                                                                                     |
| Canonical URLs             | Pages have `<link rel="canonical">`.                                                                                                                            |
| Content without JavaScript | The main content is in the HTML the server sends (not only rendered in the browser). Count this as `warn`, not `fail`, for apps that are mostly behind sign-in. |
| Favicon                    | The site has a favicon.                                                                                                                                         |
| Links                      | Internal links work (no 404s) and use descriptive link text.                                                                                                    |

## Scan only, or scan and fix

- If the user asked only for a scan, don't change the app. Write the report, then tell them in one or two
  sentences what the biggest improvements would be.
- If they asked you to fix issues too, fix everything that fails or needs work, in the app's own stack
  (e.g. Inertia's `<Head>`, Next.js `metadata`, a Blade layout). Use the published address for canonical and
  sitemap URLs when you know it; otherwise use relative URLs. Then check again and write the report as it is
  after your fixes.

## Write /workspace/.onedrop/seo.json

Write this file every time you check:

```json
{
    "version": 1,
    "scanned_at": "2026-09-27T14:05:00Z",
    "score": 72,
    "summary": "Titles and headings are good. Add descriptions, link previews and a sitemap.",
    "checks": [
        {
            "title": "Page titles",
            "status": "pass",
            "detail": "All 4 pages have unique titles."
        },
        {
            "title": "Meta descriptions",
            "status": "fail",
            "detail": "None of the 4 pages has a description."
        },
        {
            "title": "Sitemap",
            "status": "warn",
            "detail": "sitemap.xml is missing the /pricing page."
        }
    ]
}
```

- `scanned_at`: now, in UTC (`date -u +%Y-%m-%dT%H:%M:%SZ`).
- `status`: `pass`, `warn` (needs work), or `fail`. One entry per check in the table above, in that order.
- `score`: 0–100. Start from the share of checks that pass, counting `warn` as half, and round.
- `detail`: one plain-language sentence a non-developer understands, naming the pages involved.
