<?php

namespace Tests\Feature\Tables;

use App\Models\TableActivity;
use App\Models\TableView;
use App\Models\User;
use App\Tables\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\Deal;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-009')]
class FormViewsTest extends TablesTestCase
{
    /**
     * The deals fields people can fill in, in order: not worked out (formula, lookup, times) or locked (code).
     */
    private const FILLABLE = [
        'name', 'company_id', 'contacts', 'value', 'probability', 'stage', 'tags', 'close_date', 'owner_id', 'signed',
        'fit', 'files', 'notes', 'website', 'email', 'phone', 'seats',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('table-attachments');
        config(['inertia.testing.ensure_pages_exist' => false]);
        $this->withoutVite();
    }

    #[Test]
    public function a_form_asks_for_every_field_people_can_fill_in_by_default(): void
    {
        $this->props();
        $view = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Request a deal', 'type' => 'form'])
            ->assertCreated()->json('view');

        $form = $view['config']['form'];
        $this->assertSame('Request a deal', $form['title']);
        $this->assertSame('', $form['description']);
        $this->assertSame(self::FILLABLE, array_column($form['fields'], 'key'));
        $this->assertSame(['name'], array_column(array_filter($form['fields'], fn (array $field) => $field['required']), 'key'));
        $this->assertSame('', $form['fields'][0]['help']);
        $this->assertSame('Send', $form['submitLabel']);
        $this->assertSame('Thanks! Your response was sent.', $form['thankYou']);
        $this->assertTrue($form['allowAnother']);
        $this->assertFalse($form['public']);

        $this->assertNull($this->props()['views'][0]['config']['form']);
    }

    #[Test]
    public function a_form_keeps_only_fields_people_can_fill_in_once_each(): void
    {
        $id = $this->formView();

        $form = $this->patchForm($id, [
            'title' => 'New deal',
            'description' => 'Tell us about it.',
            'fields' => [
                ['key' => 'name', 'required' => true],
                ['key' => 'nope'],
                ['key' => 'weighted'],
                ['key' => 'code'],
                ['key' => 'company_city'],
                ['key' => 'created_at'],
                ['key' => 'name'],
                ['key' => 'notes', 'help' => ' Anything else '],
            ],
            'submitLabel' => 'Submit',
            'thankYou' => 'Got it.',
            'allowAnother' => false,
        ])->assertOk()->json('view.config.form');

        $this->assertSame([
            'title' => 'New deal',
            'description' => 'Tell us about it.',
            'fields' => [['key' => 'name', 'required' => true, 'help' => ''], ['key' => 'notes', 'required' => false, 'help' => 'Anything else']],
            'submitLabel' => 'Submit',
            'thankYou' => 'Got it.',
            'allowAnother' => false,
            'public' => false,
        ], $form);

        $patched = $this->patchForm($id, ['title' => 'Deal request'])->json('view.config.form');
        $this->assertSame('Deal request', $patched['title']);
        $this->assertSame(['name', 'notes'], array_column($patched['fields'], 'key'));

        $this->patchForm($id, ['fields' => [['key' => 'name', 'required' => 'often']]])
            ->assertUnprocessable()->assertJsonValidationErrors('config.form.fields.0.required');
    }

    #[Test]
    public function deleting_a_field_takes_it_out_of_forms(): void
    {
        $budget = $this->addField('deals', ['name' => 'Budget', 'type' => 'number']);
        $id = $this->formView(['fields' => [['key' => 'name'], ['key' => $budget, 'required' => true]]]);

        $this->actingAs($this->user)->deleteJson("/tables/deals/fields/{$budget}")->assertOk();

        $this->assertSame(['name'], array_column($this->viewData($id)['config']['form']['fields'], 'key'));
    }

    #[Test]
    public function tables_can_start_with_a_form(): void
    {
        $builder = View::form('Request a deal')
            ->fields(['name' => true, 'email' => true, 'notes'])
            ->help('notes', 'Anything we should know')
            ->title('Request a deal')
            ->description('We reply within a day.')
            ->submitLabel('Request')
            ->thankYou('Thanks!')
            ->allowAnother(false)
            ->public();

        $this->assertSame([
            'fields' => [
                ['key' => 'name', 'required' => true],
                ['key' => 'email', 'required' => true],
                ['key' => 'notes', 'required' => false, 'help' => 'Anything we should know'],
            ],
            'title' => 'Request a deal',
            'description' => 'We reply within a day.',
            'submitLabel' => 'Request',
            'thankYou' => 'Thanks!',
            'allowAnother' => false,
            'public' => true,
        ], $builder->config()['form']);

        DealsTable::$startViews = [View::grid('All deals'), $builder];

        $form = $this->props()['views'][1];
        $token = TableView::query()->findOrFail($form['id'])->public_token;

        $this->assertSame('form', $form['type']);
        $this->assertSame(['name', 'email', 'notes'], array_column($form['config']['form']['fields'], 'key'));
        $this->assertTrue($form['config']['form']['public']);
        $this->assertSame(40, strlen($token));
        $this->assertSame("/forms/{$token}", $form['publicFormUrl']);
    }

    #[Test]
    public function form_links_are_shown_to_people_who_may_share_them(): void
    {
        $id = $this->formView(['public' => true]);
        $token = TableView::query()->findOrFail($id)->public_token;

        $view = $this->viewData($id);
        $this->assertSame("/tables/deals/forms/{$id}", $view['formUrl']);
        $this->assertSame("/forms/{$token}", $view['publicFormUrl']);
        $this->assertArrayNotHasKey('formUrl', $this->props()['views'][0]);

        DealsTable::$denied = ['manageViews'];
        $view = $this->viewData($id);
        $this->assertSame("/tables/deals/forms/{$id}", $view['formUrl']);
        $this->assertNull($view['publicFormUrl']);

        $personal = $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Mine', 'type' => 'form', 'personal' => true, 'config' => ['form' => ['public' => true]]])
            ->assertCreated()->json('view');
        $this->assertStringStartsWith('/forms/', $personal['publicFormUrl']);

        DealsTable::$denied = [];
        $this->patchForm($id, ['public' => false]);
        $this->assertNull($this->viewData($id)['publicFormUrl']);
    }

    #[Test]
    public function a_public_link_is_made_turned_off_and_replaced(): void
    {
        $id = $this->formView();
        $this->assertNull(TableView::query()->findOrFail($id)->public_token);
        $this->assertNull($this->viewData($id)['publicFormUrl']);

        DealsTable::$denied = ['manageViews'];
        $this->patchForm($id, ['public' => true])->assertForbidden();
        $this->actingAs($this->user)->postJson("/tables/deals/views/{$id}/form-link")->assertForbidden();
        DealsTable::$denied = [];

        $this->patchForm($id, ['public' => true])->assertOk();
        $token = TableView::query()->findOrFail($id)->public_token;
        $this->assertSame(40, strlen($token));
        $this->guest()->get("/forms/{$token}")->assertOk();

        $this->patchForm($id, ['public' => false])->assertOk();
        $this->assertSame($token, TableView::query()->findOrFail($id)->public_token);
        $this->guest()->get("/forms/{$token}")->assertNotFound();
        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'Hi']])->assertNotFound();
        $this->guest()->postJson("/forms/{$token}/attachments", ['file' => UploadedFile::fake()->image('a.png')])->assertNotFound();

        $this->patchForm($id, ['public' => true])->assertOk();
        $this->assertSame($token, TableView::query()->findOrFail($id)->public_token);

        $view = $this->actingAs($this->user)->postJson("/tables/deals/views/{$id}/form-link")->assertOk()->json('view');
        $newToken = TableView::query()->findOrFail($id)->public_token;
        $this->assertNotSame($token, $newToken);
        $this->assertSame("/forms/{$newToken}", $view['publicFormUrl']);
        $this->guest()->get("/forms/{$token}")->assertNotFound();
        $this->guest()->get("/forms/{$newToken}")->assertOk();
        $this->guest()->get('/forms/'.str_repeat('x', 40))->assertNotFound();

        $grid = $this->props()['views'][0]['id'];
        $this->actingAs($this->user)->postJson("/tables/deals/views/{$grid}/form-link")->assertNotFound();
    }

    #[Test]
    public function only_people_who_may_add_records_can_make_a_form_public(): void
    {
        $id = $this->formView();
        DealsTable::$denied = ['create'];

        $this->patchForm($id, ['public' => true])->assertForbidden();
        $this->assertNull(TableView::query()->findOrFail($id)->public_token);
    }

    #[Test]
    public function the_form_page_has_the_forms_fields(): void
    {
        $this->company(['name' => 'Acme']);
        $id = $this->formView(['title' => 'New deal', 'fields' => [
            ['key' => 'name', 'required' => true, 'help' => 'What to call it'],
            ['key' => 'stage'],
            ['key' => 'company_id'],
            ['key' => 'owner_id'],
        ]]);

        $this->actingAs($this->user)->get("/tables/deals/forms/{$id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tables/form')
                ->where('form.tableName', 'Deals')
                ->where('form.title', 'New deal')
                ->where('form.description', '')
                ->where('form.fields.0.key', 'name')
                ->where('form.fields.0.type', 'text')
                ->where('form.fields.0.required', true)
                ->where('form.fields.0.help', 'What to call it')
                ->where('form.fields.0.readOnly', false)
                ->where('form.fields.1.options.choices.0.name', 'Lead')
                ->where('form.fields.1.required', false)
                ->where('form.fields.2.key', 'company_id')
                ->where('form.fields.3.key', 'owner_id')
                ->has('form.fields', 4)
                ->where('form.submitLabel', 'Send')
                ->where('form.thankYou', 'Thanks! Your response was sent.')
                ->where('form.allowAnother', true)
                ->where('form.submitUrl', "/tables/deals/forms/{$id}")
                ->where('form.uploadUrl', '/tables/deals/attachments')
                ->where('form.users.0.name', 'Dana Dev')
                ->where('form.linked.companies.records.0.title', 'Acme')
                ->where('form.public', false));

        $grid = $this->props()['views'][0]['id'];
        $this->actingAs($this->user)->get("/tables/deals/forms/{$grid}")->assertNotFound();

        DealsTable::$denied = ['create'];
        $this->actingAs($this->user)->get("/tables/deals/forms/{$id}")->assertForbidden();
        $this->actingAs($this->user)->postJson("/tables/deals/forms/{$id}", ['values' => ['name' => 'X']])->assertForbidden();
    }

    #[Test]
    public function sending_a_form_adds_a_record_with_only_the_forms_fields(): void
    {
        $id = $this->formView(['fields' => [['key' => 'name', 'required' => true], ['key' => 'value'], ['key' => 'stage'], ['key' => 'tags']]]);

        $this->actingAs($this->user)->postJson("/tables/deals/forms/{$id}", ['values' => [
            'name' => 'Big deal',
            'value' => '$1,200',
            'stage' => 'Won',
            'seats' => 12,
            'code' => 'X',
            'nope' => 'ignored',
        ]])->assertOk()->assertExactJson(['ok' => true]);

        $deal = Deal::query()->sole();
        $this->assertSame('Big deal', $deal->name);
        $this->assertEquals(1200, $deal->value);
        $this->assertSame('Won', $deal->stage);
        $this->assertNull($deal->seats);
        $this->assertNull($deal->code);
        $this->assertSame($this->user->id, $deal->owner_id);
        $this->assertSame(['Big deal'], DealsTable::$saved);

        $items = $this->actingAs($this->user)->getJson("/tables/deals/records/{$deal->id}/activity")->json('items');
        $this->assertSame('created', $items[0]['kind']);
        $this->assertSame('Request a deal', $items[0]['via']);
        $this->assertSame($this->user->id, $items[0]['user']['id']);
    }

    #[Test]
    public function fields_left_unanswered_get_the_tables_defaults(): void
    {
        $id = $this->formView(['fields' => [['key' => 'name', 'required' => true], ['key' => 'stage']]]);

        $this->actingAs($this->user)->postJson("/tables/deals/forms/{$id}", ['values' => ['name' => 'Quiet deal']])->assertOk();

        $this->assertSame('Lead', Deal::query()->sole()->stage);
    }

    #[Test]
    public function required_fields_and_values_that_dont_fit_are_refused(): void
    {
        $id = $this->formView(['fields' => [
            ['key' => 'name', 'required' => true],
            ['key' => 'value'],
            ['key' => 'stage'],
            ['key' => 'signed', 'required' => true],
        ]]);

        $this->actingAs($this->user)->postJson("/tables/deals/forms/{$id}", ['values' => ['name' => '  ', 'value' => 'lots', 'stage' => 'Maybe', 'signed' => false]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name', ['Name is required.'])
            ->assertJsonPath('errors.signed', ['Signed is required.'])
            ->assertJsonValidationErrors(['value', 'stage']);

        $this->actingAs($this->user)->postJson("/tables/deals/forms/{$id}", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'signed']);

        $this->assertSame(0, Deal::query()->count());
        $this->assertSame(['Lead', 'Qualified', 'Won', 'Lost'], array_column(collect($this->props()['fields'])->firstWhere('key', 'stage')['options']['choices'], 'name'));
    }

    #[Test]
    public function a_public_form_leaves_out_people_and_links(): void
    {
        $this->company();
        $id = $this->formView(['public' => true, 'fields' => [['key' => 'name', 'required' => true], ['key' => 'company_id'], ['key' => 'contacts'], ['key' => 'owner_id'], ['key' => 'notes']]]);
        $token = TableView::query()->findOrFail($id)->public_token;

        $this->guest()->get("/forms/{$token}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tables/form')
                ->where('form.fields.0.key', 'name')
                ->where('form.fields.1.key', 'notes')
                ->has('form.fields', 2)
                ->where('form.users', [])
                ->where('form.linked', [])
                ->where('form.public', true)
                ->where('form.submitUrl', "/forms/{$token}")
                ->where('form.uploadUrl', "/forms/{$token}/attachments"));

        $company = $this->company(['name' => 'Other']);
        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'From the web', 'notes' => 'Hello', 'company_id' => [$company->id], 'owner_id' => $this->user->id]])
            ->assertOk()->assertExactJson(['ok' => true]);

        $deal = Deal::query()->sole();
        $this->assertSame('From the web', $deal->name);
        $this->assertSame('Hello', $deal->notes);
        $this->assertNull($deal->company_id);
        $this->assertNull($deal->owner_id);

        $activity = TableActivity::query()->sole();
        $this->assertNull($activity->user_id);
        $this->assertSame('Request a deal', $activity->via);
    }

    #[Test]
    public function a_public_form_filled_in_by_a_bot_adds_nothing(): void
    {
        $token = $this->publicToken();

        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'Spam'], 'website' => 'https://spam.example'])
            ->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(0, Deal::query()->count());
    }

    #[Test]
    public function a_public_form_is_limited_to_a_few_sends_a_minute(): void
    {
        config(['tables.form_submissions_per_minute' => 2]);
        $token = $this->publicToken();

        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'One']])->assertOk();
        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'Two']])->assertOk();
        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'Three']])->assertTooManyRequests();

        $this->assertSame(2, Deal::query()->count());
    }

    #[Test]
    public function files_are_attached_through_a_public_form(): void
    {
        $token = $this->publicToken([['key' => 'name', 'required' => true], ['key' => 'files']]);

        $attachment = $this->guest()->post("/forms/{$token}/attachments", ['file' => UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('attachment');

        $this->assertStringStartsWith('deals/form-uploads/', $attachment['key']);
        Storage::disk('table-attachments')->assertExists($attachment['key']);

        Storage::disk('table-attachments')->put('deals/secret.pdf', 'secret');
        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'Peek', 'files' => [['key' => 'deals/secret.pdf', 'name' => 'secret.pdf']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('files');

        $this->guest()->postJson("/forms/{$token}", ['values' => ['name' => 'With a brief', 'files' => [$attachment]]])->assertOk();
        $this->assertSame($attachment['key'], Deal::query()->sole()->files[0]['key']);

        config(['tables.form_max_upload_kb' => 5]);
        $this->guest()->post("/forms/{$token}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    #[Test]
    public function a_public_form_without_attachment_fields_takes_no_files(): void
    {
        $token = $this->publicToken([['key' => 'name']]);

        $this->guest()->post("/forms/{$token}/attachments", ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    #[Test]
    public function a_personal_public_form_stops_when_its_owner_may_no_longer_add_records(): void
    {
        $owner = User::factory()->create();
        $id = $this->actingAs($owner)->postJson('/tables/deals/views', ['name' => 'Mine', 'type' => 'form', 'personal' => true, 'config' => ['form' => ['public' => true]]])
            ->assertCreated()->json('view.id');
        $token = TableView::query()->findOrFail($id)->public_token;

        $this->guest()->get("/forms/{$token}")->assertOk();

        DealsTable::$denied = ['create'];
        $this->guest()->get("/forms/{$token}")->assertNotFound();
    }

    /**
     * Add a shared form view "Request a deal" with the form config (after the table's starting views), and return its id.
     *
     * @param  array<string, mixed>  $form
     */
    private function formView(array $form = []): int
    {
        $this->props();

        return $this->actingAs($this->user)->postJson('/tables/deals/views', ['name' => 'Request a deal', 'type' => 'form', 'config' => ['form' => $form]])
            ->assertCreated()->json('view.id');
    }

    /**
     * A public form's token.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function publicToken(array $fields = [['key' => 'name', 'required' => true]]): string
    {
        return TableView::query()->findOrFail($this->formView(['public' => true, 'fields' => $fields]))->public_token;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function patchForm(int $id, array $form): TestResponse
    {
        return $this->actingAs($this->user)->patchJson("/tables/deals/views/{$id}", ['config' => ['form' => $form]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(int $id): array
    {
        return collect($this->props()['views'])->firstWhere('id', $id);
    }

    /**
     * Sign out, for requests through a public link.
     */
    private function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
