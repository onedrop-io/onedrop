<?php

use App\Sandbox\Agents\PlainActivity;

test('the agent\'s steps read as plain words in the sidebar', function (string $step, string $plain) {
    expect(PlainActivity::describe($step))->toBe($plain);
})->with([
    'a page' => ['Creating resources/js/pages/admin/orders/show.tsx', 'Building the orders page'],
    'the home page' => ['Editing resources/js/pages/welcome.tsx', 'Building the home page'],
    'a page by id' => ['Creating resources/js/pages/products/[id].tsx', 'Building the products page'],
    'a component' => ['Creating resources/js/components/ProductCard.tsx', 'Building the product card'],
    'routes' => ['Editing routes/web.php', 'Connecting the pages'],
    'a migration' => ['Creating database/migrations/2026_10_01_create_orders_table.php', 'Setting up the database'],
    'a model' => ['Creating app/Models/OrderItem.php', 'Setting up order items'],
    'a controller' => ['Editing app/Http/Controllers/CartController.php', 'Working on the cart'],
    'styles' => ['Editing resources/css/app.css', 'Styling the app'],
    'tests' => ['Creating tests/e2e/cart.spec.ts', 'Writing tests'],
    'requirements' => ['Creating .onedrop/REQ.md', 'Noting what you asked for'],
    'settings' => ['Editing .env', 'Updating settings'],
    'the icon' => ['Creating public/favicon.svg', 'Updating the app icon'],
    'other code' => ['Editing main.js', 'Making changes'],
    'reading' => ['Reading app/Models/User.php', 'Reading the code'],
    'a failed step' => ['Creating a.txt (failed)', 'Writing code'],
    'installing' => ['Running `npm install stripe 2>&1 | tail -20`', 'Installing tools'],
    'running tests' => ['Running `/opt/onedrop/run-tests @REQ-003`', 'Testing the app'],
    'migrating' => ['Running `php artisan migrate --force`', 'Updating the database'],
    'a described step' => ['Running Install dependencies', 'Installing tools'],
    'a described check' => ['Running Check the home page loads', 'Checking the app works'],
    'an unknown command' => ['Running `./weird-script`', 'Working on the app'],
    'an unnamed tool' => ['Using mcp__acme__lookup', 'Working on the app'],
    'already plain' => ['Looking through the code', 'Looking through the code'],
])->group('PRJ-006');
