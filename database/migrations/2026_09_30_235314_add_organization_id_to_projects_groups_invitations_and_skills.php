<?php

use App\Models\SystemSetting;
use App\Sandbox\Branding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Put everything already on the install into one organization (ORG-001), so a self-hosted install looks the
 * same as before. A fresh install gets its organization when its first account is made.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['projects', 'groups', 'invitations', 'skills'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        $organizationId = $this->backfill();

        if ($organizationId !== null) {
            foreach (self::TABLES as $name) {
                DB::table($name)->update(['organization_id' => $organizationId]);
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Make the install's organization from its name, with every account in it (admins as owners), when there are any.
     */
    private function backfill(): ?int
    {
        $userIds = DB::table('users')->orderBy('id')->pluck('id');

        if ($userIds->isEmpty()) {
            return null;
        }

        $name = (SystemSetting::group(Branding::SETTING)['name'] ?? null) ?: config('app.name', 'OneDrop');
        $now = now();

        $organizationId = DB::table('organizations')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'organization',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $admins = DB::table('users')->where('is_admin', true)->pluck('id');
        $owners = $admins->isEmpty() ? collect([$userIds->first()]) : $admins;

        DB::table('organization_user')->insert($userIds->map(fn (int $id): array => [
            'organization_id' => $organizationId,
            'user_id' => $id,
            'role' => $owners->contains($id) ? 'owner' : 'member',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        DB::table('users')->update(['current_organization_id' => $organizationId]);

        return $organizationId;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }

        DB::table('users')->update(['current_organization_id' => null]);
        DB::table('organization_user')->delete();
        DB::table('organizations')->delete();
    }
};
