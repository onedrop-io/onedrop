<?php

namespace App\Tables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files uploaded to attachment fields, kept on config('tables.disk') as "{table}/{uuid}.{extension}".
 */
final class Attachments
{
    /**
     * Extensions sent as downloads, never shown inline, since browsers would run their scripts.
     *
     * @var list<string>
     */
    public const DOWNLOAD_ONLY = ['svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'xsl', 'xslt', 'js', 'mjs'];

    public static function disk(): FilesystemAdapter
    {
        return Storage::disk(config('tables.disk', 'table-attachments'));
    }

    /**
     * Store an upload for the table.
     *
     * @return array{key: string, name: string, size: int, type: string, url: string}
     */
    public static function upload(Table $table, UploadedFile $file): array
    {
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $name = Str::uuid().'.'.$extension;

        self::disk()->putFileAs($table->key(), $file, $name);

        return self::present($table, [
            'key' => $table->key().'/'.$name,
            'name' => Str::limit($file->getClientOriginalName() ?: $name, 250, ''),
            'size' => (int) $file->getSize(),
            'type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
        ]);
    }

    /**
     * An attachment as the browser gets it, with its url.
     *
     * @param  array<string, mixed>  $attachment
     * @return array{key: string, name: string, size: int, type: string, url: string}
     */
    public static function present(Table $table, array $attachment): array
    {
        $key = (string) $attachment['key'];
        [$tableKey, $path] = array_pad(explode('/', $key, 2), 2, '');

        return [
            'key' => $key,
            'name' => (string) ($attachment['name'] ?? basename($key)),
            'size' => (int) ($attachment['size'] ?? 0),
            'type' => (string) ($attachment['type'] ?? 'application/octet-stream'),
            'url' => route('tables.files', ['table' => $tableKey, 'path' => $path], absolute: false),
        ];
    }

    /**
     * An attachment as it's stored (without its url).
     *
     * @param  array<string, mixed>  $attachment
     * @return array{key: string, name: string, size: int, type: string}
     */
    public static function stored(array $attachment): array
    {
        return [
            'key' => (string) $attachment['key'],
            'name' => (string) $attachment['name'],
            'size' => (int) $attachment['size'],
            'type' => (string) $attachment['type'],
        ];
    }

    /**
     * Whether the key is a file uploaded for this table.
     */
    public static function belongsTo(Table $table, string $key): bool
    {
        return str_starts_with($key, $table->key().'/')
            && ! str_contains($key, '..')
            && self::disk()->exists($key);
    }

    /**
     * Send the file: inline, except types browsers would run, which are downloads.
     */
    public static function response(Table $table, string $path): StreamedResponse
    {
        $key = $table->key().'/'.$path;

        abort_if(str_contains($path, '..') || ! self::disk()->exists($key), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = (string) (self::disk()->mimeType($key) ?: 'application/octet-stream');
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox"];

        if (in_array($extension, self::DOWNLOAD_ONLY, true) || preg_match('/(svg|html|xml|javascript)/i', $mime)) {
            return self::disk()->download($key, basename($path), [...$headers, 'Content-Type' => 'application/octet-stream']);
        }

        return self::disk()->response($key, basename($path), [...$headers, 'Content-Type' => $mime]);
    }

    /**
     * Delete the files in a record's attachment fields.
     */
    public static function deleteFor(Table $table, Model $record): void
    {
        $keys = [];

        foreach ($table->allFields() as $field) {
            if ($field->type === 'attachment') {
                foreach (Values::list($table->stored($record, $field)) as $attachment) {
                    if (is_array($attachment) && isset($attachment['key']) && str_starts_with((string) $attachment['key'], $table->key().'/')) {
                        $keys[] = (string) $attachment['key'];
                    }
                }
            }
        }

        if ($keys !== []) {
            self::disk()->delete($keys);
        }
    }
}
