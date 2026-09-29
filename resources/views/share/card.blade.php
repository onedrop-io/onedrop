{{-- A share page's 1200×630 preview card (SHARE-001), rendered by headless Chromium in the project's sandbox next to app.png. --}}
<!doctype html>
<html>
    <head>
        <meta charset="utf-8" />
        <link href="https://fonts.bunny.net/css?family=instrument-sans:500,600|schibsted-grotesk:700,800" rel="stylesheet" />
        <style>
            * { margin: 0; box-sizing: border-box; }
            body {
                width: 1200px;
                height: 630px;
                overflow: hidden;
                background: #0a0807;
                color: #f5efea;
                font-family: 'Instrument Sans', 'Liberation Sans', sans-serif;
            }
            .card { position: relative; width: 100%; height: 100%; }
            .grid {
                position: absolute;
                inset: 0;
                background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1.2px, transparent 1.2px);
                background-size: 28px 28px;
                mask-image: radial-gradient(ellipse at 20% 30%, black 10%, transparent 70%);
            }
            .glow {
                position: absolute;
                right: -120px;
                top: 40px;
                width: 760px;
                height: 620px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(255, 154, 92, 0.32), rgba(255, 154, 92, 0.06) 45%, transparent 70%);
            }
            .text {
                position: absolute;
                left: 72px;
                top: 64px;
                bottom: 60px;
                width: 520px;
                display: flex;
                flex-direction: column;
            }
            .brand {
                display: flex;
                align-items: center;
                gap: 10px;
                font-family: 'Schibsted Grotesk', sans-serif;
                font-weight: 800;
                font-size: 28px;
                letter-spacing: -0.02em;
            }
            .brand svg { width: 30px; height: 30px; color: #ff9a5c; }
            .label {
                margin-top: auto;
                font-size: 17px;
                font-weight: 600;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: #ff9a5c;
            }
            blockquote {
                margin-top: 14px;
                font-family: 'Schibsted Grotesk', sans-serif;
                font-weight: 700;
                font-size: {{ $promptSize }}px;
                line-height: 1.15;
                letter-spacing: -0.02em;
                display: -webkit-box;
                -webkit-line-clamp: {{ $promptLines }};
                -webkit-box-orient: vertical;
                overflow: hidden;
                background: linear-gradient(to bottom, #fff, #fff 60%, #ffd9c2);
                -webkit-background-clip: text;
                color: transparent;
            }
            .name {
                margin-top: 28px;
                font-size: 24px;
                font-weight: 500;
                color: #b3a69c;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .name strong { color: #f5efea; font-weight: 600; }
            .shot {
                position: absolute;
                left: 640px;
                top: 92px;
                width: 680px;
                border-radius: 14px;
                overflow: hidden;
                background: #1a1512;
                border: 1px solid rgba(255, 255, 255, 0.12);
                box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6), 0 0 90px rgba(255, 154, 92, 0.25);
                transform: perspective(1400px) rotateY(-9deg) rotateX(2deg);
                transform-origin: left center;
            }
            .bar { display: flex; gap: 7px; align-items: center; height: 34px; padding: 0 14px; background: #221b17; }
            .bar i { width: 11px; height: 11px; border-radius: 50%; background: rgba(255, 255, 255, 0.18); }
            .shot img { display: block; width: 100%; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="grid"></div>
            <div class="glow"></div>
            <div class="shot">
                <div class="bar"><i></i><i></i><i></i></div>
                <img src="app.png" alt="" />
            </div>
            <div class="text">
                <div class="brand">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2.5c-.4.5-7 8.3-7 13a7 7 0 0 0 14 0c0-4.7-6.6-12.5-7-13Z" fill="currentColor" />
                        <path d="M9.2 15.6a3 3 0 0 0 2.6 2.9" stroke="white" stroke-width="1.6" stroke-linecap="round" fill="none" opacity="0.85" />
                    </svg>
                    OneDrop
                </div>
                <div class="label">The prompt</div>
                <blockquote>“{{ $prompt }}”</blockquote>
                <div class="name"><strong>{{ $name }}</strong>{{ $author ? " by {$author}" : '' }}</div>
            </div>
        </div>
    </body>
</html>
