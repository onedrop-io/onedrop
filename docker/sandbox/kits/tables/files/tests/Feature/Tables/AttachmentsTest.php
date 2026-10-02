<?php

namespace Tests\Feature\Tables;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Tables\Fixtures\DealsTable;

#[Group('TABLE-002')]
class AttachmentsTest extends TablesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('table-attachments');
    }

    #[Test]
    public function files_are_uploaded_attached_and_served(): void
    {
        $attachment = $this->actingAs($this->user)->post('/tables/deals/attachments', ['file' => UploadedFile::fake()->image('Logo.png')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('attachment');

        $this->assertStringStartsWith('deals/', $attachment['key']);
        $this->assertStringEndsWith('.png', $attachment['key']);
        $this->assertSame('Logo.png', $attachment['name']);
        $this->assertSame('image/png', $attachment['type']);
        $this->assertSame(route('tables.files', ['table' => 'deals', 'path' => substr($attachment['key'], 6)], absolute: false), $attachment['url']);
        Storage::disk('table-attachments')->assertExists($attachment['key']);

        $deal = $this->deal();
        $record = $this->updateRecord('deals', $deal->id, ['files' => [$attachment]]);
        $this->assertSame([$attachment], $record['values']['files']);
        $this->assertSame([array_diff_key($attachment, ['url' => 1])], $deal->fresh()->files);

        $this->actingAs($this->user)->get($attachment['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[Test]
    public function files_are_only_served_to_people_who_may_view_the_table(): void
    {
        $url = $this->actingAs($this->user)->post('/tables/deals/attachments', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->json('attachment.url');

        DealsTable::$denied = ['view'];
        $this->actingAs($this->user)->get($url)->assertForbidden();

        auth()->logout();
        $this->get($url)->assertRedirect();
    }

    #[Test]
    public function svg_files_are_sent_as_downloads(): void
    {
        $svg = UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $url = $this->actingAs($this->user)->post('/tables/deals/attachments', ['file' => $svg], ['Accept' => 'application/json'])->json('attachment.url');

        $response = $this->actingAs($this->user)->get($url)->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function uploads_are_limited_in_size(): void
    {
        config(['tables.max_upload_kb' => 10]);

        $this->actingAs($this->user)->post('/tables/deals/attachments', ['file' => UploadedFile::fake()->create('big.pdf', 20)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function only_files_uploaded_for_the_table_can_be_attached(): void
    {
        $companyFile = $this->actingAs($this->user)->post('/tables/companies/attachments', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->json('attachment');
        $deal = $this->deal();

        $this->changes('deals', ['updates' => [['id' => $deal->id, 'values' => ['files' => [$companyFile]]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ["{$deal->id}.files" => ['Files has a file that wasn\'t uploaded here.']]);
    }

    #[Test]
    public function deleting_a_record_deletes_its_files(): void
    {
        $key = $this->addField('deals', ['name' => 'Docs', 'type' => 'attachment']);
        $first = $this->actingAs($this->user)->post('/tables/deals/attachments', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->json('attachment');
        $second = $this->actingAs($this->user)->post('/tables/deals/attachments', ['file' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->json('attachment');
        $deal = $this->deal();
        $this->updateRecord('deals', $deal->id, ['files' => [$first], $key => [$second]]);

        $this->changes('deals', ['deletes' => [$deal->id]])->assertOk();

        Storage::disk('table-attachments')->assertMissing($first['key']);
        Storage::disk('table-attachments')->assertMissing($second['key']);
    }

    #[Test]
    public function the_attachments_disk_is_defined_when_the_app_has_none(): void
    {
        $this->assertSame('local', config('filesystems.disks.table-attachments.driver'));
        $this->assertStringEndsWith('/table-attachments', config('filesystems.disks.table-attachments.root'));
        $this->assertNotNull(User::query()->first());
    }
}
