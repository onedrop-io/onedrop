<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * Define the model's default state: a small PNG, stored on the attachments disk.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'name' => fake()->word().'.png',
            'mime_type' => 'image/png',
            'size' => 4,
            'path' => function (): string {
                $path = 'attachments/'.Str::random(40);
                Storage::disk(Attachment::DISK)->put($path, "\x89PNG");

                return $path;
            },
        ];
    }

    /**
     * A plain-text file.
     */
    public function text(string $contents = 'hello'): static
    {
        return $this->state(fn () => [
            'name' => fake()->word().'.txt',
            'mime_type' => 'text/plain',
            'size' => strlen($contents),
            'path' => function () use ($contents): string {
                $path = 'attachments/'.Str::random(40);
                Storage::disk(Attachment::DISK)->put($path, $contents);

                return $path;
            },
        ]);
    }
}
