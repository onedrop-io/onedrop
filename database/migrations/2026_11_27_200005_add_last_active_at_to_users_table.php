<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Starts everyone at their latest sign-in or database session (USR-004).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index();
        });

        $sessions = Schema::hasTable('sessions')
            ? DB::table('sessions')->whereNotNull('user_id')->groupBy('user_id')->selectRaw('user_id, max(last_activity) as last_activity')->pluck('last_activity', 'user_id')
            : collect();

        DB::table('users')->select(['id', 'last_login_at'])->orderBy('id')->each(function (object $user) use ($sessions): void {
            $times = array_filter([
                $user->last_login_at ? Carbon::parse($user->last_login_at) : null,
                isset($sessions[$user->id]) ? Carbon::createFromTimestamp((int) $sessions[$user->id]) : null,
            ]);

            if ($times !== []) {
                DB::table('users')->where('id', $user->id)->update(['last_active_at' => max($times)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_active_at']);
            $table->dropColumn('last_active_at');
        });
    }
};
