<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open this preview from its pull request</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: Canvas; color: CanvasText; }
        main { max-width: 26rem; padding: 2rem; text-align: center; line-height: 1.5; }
        h1 { font-size: 1.1rem; margin: 0 0 .5rem; }
        p { margin: 0 0 1rem; opacity: .75; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <h1>Open this preview from its pull request</h1>
    <p>This preview{{ $pullRequest ? " of pull request #{$pullRequest}" : '' }} opens from the link in its pull request on GitHub. The link may have changed, or your visit expired: open it from the pull request again.</p>
</main>
</body>
</html>
