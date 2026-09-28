<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open this preview from the app builder</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: Canvas; color: CanvasText; }
        main { max-width: 26rem; padding: 2rem; text-align: center; line-height: 1.5; }
        h1 { font-size: 1.1rem; margin: 0 0 .5rem; }
        p { margin: 0 0 1rem; opacity: .75; font-size: .9rem; }
        a { display: inline-block; padding: .5rem 1rem; border-radius: .5rem; background: CanvasText; color: Canvas; text-decoration: none; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <h1>Open this preview from the app builder</h1>
    @if ($reason === 'expired')
        <p>Your access to this preview has expired. Reopen it from the app builder.</p>
    @else
        <p>Open this preview from the app builder. If this keeps happening, allow cookies for this site (some privacy settings and extensions block them inside frames).</p>
    @endif
    <a href="{{ $openUrl }}">Reopen</a>
</main>
</body>
</html>
