<?php

namespace App\Sandbox;

use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The install's name and logo (ADMIN-001). The name lands in config('app.name') (SystemConfig); the logo is kept
 * on the default disk and served by BrandingController.
 */
class Branding
{
    public const SETTING = 'branding';

    protected const DIRECTORY = 'branding';

    /**
     * Where the logo is kept: the default disk, which is object storage in production, since an instance's own disk
     * isn't shared between instances and is wiped on each deploy.
     */
    public static function disk(): string
    {
        return (string) config('filesystems.default');
    }

    /**
     * Save the name, or go back to the one in `.env` when it's blank.
     */
    public function rename(?string $name): void
    {
        SystemSetting::merge(self::SETTING, ['name' => filled($name) ? $name : null]);
        SystemConfig::saved();
    }

    /**
     * The name an admin set, or null when it's the one from `.env`.
     */
    public function customName(): ?string
    {
        return SystemSetting::group(self::SETTING)['name'] ?? null;
    }

    /**
     * Replace the logo with an uploaded image.
     */
    public function storeLogo(UploadedFile $file): void
    {
        $this->removeLogo();

        $path = $file->storeAs(self::DIRECTORY, 'logo.'.($file->extension() === 'svg' ? 'svg' : $file->guessExtension()), self::disk());

        SystemSetting::merge(self::SETTING, ['logo' => $path, 'logo_hash' => substr((string) hash_file('sha256', $file->getRealPath()), 0, 12)]);
    }

    /**
     * Go back to the droplet.
     */
    public function removeLogo(): void
    {
        $path = SystemSetting::group(self::SETTING)['logo'] ?? null;

        if ($path !== null) {
            Storage::disk(self::disk())->delete($path);
            SystemSetting::merge(self::SETTING, ['logo' => null, 'logo_hash' => null]);
        }
    }

    /**
     * Where the logo is on its disk, or null for the droplet.
     */
    public function logoPath(): ?string
    {
        $path = SystemSetting::group(self::SETTING)['logo'] ?? null;

        return $path !== null && Storage::disk(self::disk())->exists($path) ? $path : null;
    }

    /**
     * The logo's address (changing with the file, so browsers can cache it), or null for the droplet.
     */
    public function logoUrl(): ?string
    {
        $hash = SystemSetting::group(self::SETTING)['logo_hash'] ?? null;

        return $hash !== null ? route('branding.logo', ['v' => $hash], false) : null;
    }
}
