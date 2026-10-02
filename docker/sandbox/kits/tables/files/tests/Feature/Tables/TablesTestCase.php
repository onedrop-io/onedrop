<?php

namespace Tests\Feature\Tables;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Tables\Fixtures\CompaniesTable;
use Tests\Feature\Tables\Fixtures\Company;
use Tests\Feature\Tables\Fixtures\Contact;
use Tests\Feature\Tables\Fixtures\ContactsTable;
use Tests\Feature\Tables\Fixtures\Deal;
use Tests\Feature\Tables\Fixtures\DealsTable;
use Tests\Feature\Tables\Fixtures\NotesTable;
use Tests\TestCase;

/**
 * Deals, companies, contacts and notes tables to test the kit with, prefixed so they never clash with the app's own.
 */
abstract class TablesTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DealsTable::reset();

        Schema::create('kit_test_companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('city')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        Schema::create('kit_test_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        Schema::create('kit_test_deals', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('company_id')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->decimal('probability', 5, 4)->nullable();
            $table->string('stage')->nullable();
            $table->json('tags')->nullable();
            $table->date('close_date')->nullable();
            $table->foreignId('owner_id')->nullable();
            $table->boolean('signed')->default(false);
            $table->unsignedTinyInteger('fit')->nullable();
            $table->json('files')->nullable();
            $table->text('notes')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->integer('seats')->nullable();
            $table->string('code')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
        });

        Schema::create('kit_test_contact_deal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id');
            $table->foreignId('deal_id');
        });

        Schema::create('kit_test_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        config(['tables.tables' => [
            'deals' => DealsTable::class,
            'companies' => CompaniesTable::class,
            'contacts' => ContactsTable::class,
            'notes' => NotesTable::class,
        ]]);

        $this->user = User::factory()->create(['name' => 'Dana Dev', 'email' => 'dana@example.com']);
    }

    protected function company(array $attributes = []): Company
    {
        return Company::query()->create(['name' => 'Acme', 'city' => 'Montreal', ...$attributes]);
    }

    protected function deal(array $attributes = []): Deal
    {
        return Deal::query()->create(['name' => 'Big deal', ...$attributes]);
    }

    protected function contact(array $attributes = []): Contact
    {
        return Contact::query()->create(['name' => 'Chris', ...$attributes]);
    }

    /**
     * Send a batch of record changes as the test user.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function changes(string $table, array $changes): TestResponse
    {
        return $this->actingAs($this->user)->postJson("/tables/{$table}/records", $changes);
    }

    /**
     * Update one record's values and return the worked-out record.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function updateRecord(string $table, int $id, array $values): array
    {
        return $this->changes($table, ['updates' => [['id' => $id, 'values' => $values]]])
            ->assertOk()
            ->json('records.0');
    }

    /**
     * Add a field as the test user and return its key.
     *
     * @param  array<string, mixed>  $input
     */
    protected function addField(string $table, array $input): string
    {
        $fields = $this->actingAs($this->user)->postJson("/tables/{$table}/fields", $input)
            ->assertOk()
            ->json('table.fields');

        return collect($fields)->firstWhere('name', $input['name'])['key'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function props(string $table = 'deals'): array
    {
        return $this->actingAs($this->user)->getJson("/tables/{$table}")->assertOk()->json();
    }

    /**
     * @return array<string, mixed>
     */
    protected function recordIn(array $props, int $id): array
    {
        return collect($props['records'])->firstWhere('id', $id);
    }
}
