<?php

use App\Sandbox\SvgSanitizer;

test('drawing is kept and anything that could run code is removed', function () {
    $svg = (new SvgSanitizer)->clean(<<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 64 64" onload="alert(1)">
            <defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/></linearGradient></defs>
            <rect width="64" height="64" rx="14" fill="url(#g)" style="fill:red"/>
            <path d="M0 0L10 10" fill="url(https://evil.test/x)" onclick="alert(1)"/>
            <script>alert(1)</script>
            <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">hi</div></foreignObject>
            <a href="javascript:alert(1)"><circle r="5"/></a>
            <image href="https://evil.test/track.png"/>
            <use xlink:href="data:image/svg+xml;base64,AAAA"/>
            <style>@import url(https://evil.test/x.css);</style>
        </svg>
        SVG);

    expect($svg)
        ->toContain('<rect width="64" height="64" rx="14" fill="url(#g)"/>')
        ->toContain('<linearGradient id="g">')
        ->toContain('<path d="M0 0L10 10"/>')
        ->not->toContain('onload')->not->toContain('onclick')->not->toContain('script')
        ->not->toContain('foreignObject')->not->toContain('javascript')->not->toContain('evil.test')
        ->not->toContain('style')->not->toContain('<use')->not->toContain('<a ')->not->toContain('<circle');
})->group('PRJ-007');

test('things that aren\'t a usable SVG are rejected', function (string $input) {
    expect((new SvgSanitizer)->clean($input))->toBeNull();
})->with([
    'not xml' => ['a picture of a rocket'],
    'not svg' => ['<html><body/></html>'],
    'entities' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'],
    'too big' => ['<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat('<g/>', 30_000).'</svg>'],
])->group('PRJ-007');

test('a viewBox is added from the size when missing', function () {
    expect((new SvgSanitizer)->clean('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="16"/>'))
        ->toContain('viewBox="0 0 32 16"');
})->group('PRJ-007');
