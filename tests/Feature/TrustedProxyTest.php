<?php

use Illuminate\Http\Middleware\TrustProxies;

beforeEach(function () {
    config(['services.github.client_id' => 'github-id', 'services.github.client_secret' => 'github-secret']);
});

afterEach(function () {
    unset($_SERVER['LARAVEL_CLOUD']);
    TrustProxies::flushState();
});

function githubRedirectUri(): ?string
{
    $location = test()->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://onedrop.io/login/github')
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['redirect_uri'] ?? null;
}

test('on laravel cloud, callbacks use the https address the load balancer received', function () {
    $_SERVER['LARAVEL_CLOUD'] = '1';
    TrustProxies::flushState();
    $this->refreshApplication();
    config(['services.github.client_id' => 'github-id', 'services.github.client_secret' => 'github-secret']);

    expect(githubRedirectUri())->toBe('https://onedrop.io/login/github/callback');
})->group('AUTH-003');

test('elsewhere, only a local proxy is trusted', function () {
    expect(githubRedirectUri())->toBe('http://onedrop.io/login/github/callback');
})->group('AUTH-003');
