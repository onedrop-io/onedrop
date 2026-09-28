<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: Canvas; color: CanvasText; }
        main { max-width: 26rem; padding: 2rem; text-align: center; line-height: 1.5; }
        h1 { font-size: 1.1rem; margin: 0 0 .5rem; }
        p { margin: 0 0 1rem; opacity: .75; font-size: .9rem; }
    </style>
</head>
<body>
<main data-test="onedrop-refused">
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
</main>
</body>
</html>
