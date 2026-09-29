<?php

use App\Enums\ProjectStatus;
use App\Models\AgentConnection;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->seed(DatabaseSeeder::class);
    $this->user = User::where('email', 'dev@example.com')->sole();
    AgentConnection::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

/** Stands in for the browser's Notification API: permission is granted when asked, and each notification is written into the page. */
const FAKE_NOTIFICATIONS = <<<'JS'
    () => {
        window.Notification = class {
            static permission = 'default';
            static async requestPermission() { return (this.permission = 'granted'); }
            constructor(title, options) {
                const note = document.createElement('p');
                note.dataset.test = 'fake-notification';
                note.textContent = `${title}: ${options.body}`;
                document.body.append(note);
            }
        };
    }
JS;

test('the dev user turns on desktop notifications and is notified when a project is ready', function () {
    $project = Project::factory()->for($this->user)->create([
        'name' => 'Todo App',
        'read_at' => now(),
        'status' => ProjectStatus::Working,
    ]);

    $page = visit('/dashboard');
    $page->script(FAKE_NOTIFICATIONS);

    $page->click('@sidebar-menu-button')
        ->click('@settings-link')
        ->click('[data-test="settings-modal"] a:has-text("Notifications")')
        ->assertPathIs('/settings/notifications')
        ->assertSeeIn('@notifications-status', 'Off.')
        ->click('@notifications-toggle')
        ->assertSeeIn('@notifications-status', 'On.')
        ->assertSeeIn('@fake-notification', 'Notifications are on')
        ->keys('@settings-modal', 'Escape')
        ->assertPathIs('/dashboard');

    $project->update(['status' => ProjectStatus::Idle]);

    $page->assertSeeIn('[data-test="fake-notification"]:last-of-type', 'Todo App: Ready for your review')
        ->assertNoJavaScriptErrors();
})->group('NOTIF-001');

test('notifications stay off when the browser blocks them', function () {
    $page = visit('/settings/notifications');
    $page->script("() => { window.Notification = class { static permission = 'denied'; }; window.dispatchEvent(new Event('focus')); }");

    $page->assertSeeIn('@notifications-status', 'blocked for this site')
        ->assertAttribute('@notifications-toggle', 'disabled', '')
        ->assertNoJavaScriptErrors();
})->group('NOTIF-001');

test('the chat offers notifications while the agent works', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    $page = visit("/projects/{$project->id}");
    $page->script(FAKE_NOTIFICATIONS);
    $page->script("() => window.dispatchEvent(new Event('focus'))");

    $page->assertVisible('@agent-working')
        ->assertSeeIn('@notifications-prompt', 'Get a desktop notification when it’s ready?')
        ->click('@notifications-prompt-enable')
        ->assertMissing('@notifications-prompt')
        ->assertNoJavaScriptErrors();

    expect($page->script("() => localStorage.getItem('desktop_notifications')"))->toBe('true');
})->group('NOTIF-001');

test('"Not now" hides the chat prompt for good', function () {
    $project = Project::factory()->for($this->user)->create(['status' => ProjectStatus::Working]);

    $page = visit("/projects/{$project->id}");
    $page->script(FAKE_NOTIFICATIONS);
    $page->script("() => window.dispatchEvent(new Event('focus'))");

    $page->click('@notifications-prompt-dismiss')
        ->assertMissing('@notifications-prompt');

    $page->navigate("/projects/{$project->id}");
    $page->script(FAKE_NOTIFICATIONS);
    $page->script("() => window.dispatchEvent(new Event('focus'))");

    $page->assertVisible('@agent-working')
        ->assertMissing('@notifications-prompt')
        ->assertScript("localStorage.getItem('desktop_notifications_prompt_dismissed')", 'true')
        ->assertNoJavaScriptErrors();
})->group('NOTIF-001');
