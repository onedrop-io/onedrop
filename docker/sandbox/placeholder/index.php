<?php $name = htmlspecialchars(getenv('APP_PROJECT_NAME') ?: 'Your app'); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $name ?></title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: Canvas; color: CanvasText; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
        p { margin: 0; opacity: .65; line-height: 1.5; }
        .dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 50%; background: #22c55e; margin-right: .4rem; }
    </style>
</head>
<body>
<main>
    <h1><?= $name ?></h1>
    <p><span class="dot"></span>Sandbox is running. The agent will build your app here.</p>
</main>
</body>
</html>
