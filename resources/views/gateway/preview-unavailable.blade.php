<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($reason === 'starting')
        <meta http-equiv="refresh" content="10">
    @endif
    <title>{{ $reason === 'starting' ? 'The preview is starting' : 'No preview here' }}</title>
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
    @php($name = $pullRequest ? "pull request #{$pullRequest}" : 'this pull request')
    @if ($reason === 'starting')
        <h1>The preview is starting</h1>
        <p>OneDrop is making a copy of the app with {{ $name }}. This can take a few minutes; this page reloads by itself when it's ready.</p>
    @elseif ($reason === 'failed')
        <h1>The preview couldn't be made</h1>
        <p>OneDrop couldn't make the copy of the app for {{ $name }}. Its task in OneDrop says why.</p>
    @elseif ($reason === 'closed')
        <h1>This preview was removed</h1>
        <p>{{ ucfirst($name) }} was {{ $state }}, so its preview was taken down.</p>
    @else
        <h1>No preview here</h1>
        <p>There's no preview for {{ $name }} right now. Open it from the link on the pull request.</p>
    @endif
</main>
</body>
</html>
