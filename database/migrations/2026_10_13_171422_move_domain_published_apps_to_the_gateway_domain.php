<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Projects published to the domain keep their <name>-<id> address under the gateway domain it is now
     * (onedrop.io → onedrop.dev on the hosted install, GW-002). The Worker redirects the old addresses there.
     */
    public function up(): void
    {
        $domain = strtolower((string) config('sandbox.gateway_domain'));

        if ($domain === '') {
            return;
        }

        DB::table('projects')
            ->where('publish_target', 'domain')
            ->whereNotNull('published_default_url')
            ->orderBy('id')
            ->each(function (object $project) use ($domain) {
                $label = explode('.', (string) parse_url($project->published_default_url, PHP_URL_HOST))[0];

                if (! str_ends_with($label, "-{$project->id}")) {
                    return;
                }

                $url = "https://{$label}.{$domain}";

                if ($url === $project->published_default_url) {
                    return;
                }

                DB::table('projects')->where('id', $project->id)->update([
                    'published_default_url' => $url,
                    'published_url' => $project->published_url === $project->published_default_url ? $url : $project->published_url,
                ]);
            });
    }
};
