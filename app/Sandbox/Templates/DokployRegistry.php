<?php

namespace App\Sandbox\Templates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A registry in Dokploy's format (templates.dokploy.com, github.com/Dokploy/templates): a meta.json index, and for
 * each template blueprints/<id>/ with a docker-compose.yml, a template.toml (variables, env, mounts, domains) and a logo.
 *
 * @phpstan-import-type Template from TemplateRegistry
 */
class DokployRegistry implements TemplateRegistry
{
    /** Where the template's Dokploy settings are written in the workspace. */
    public const SETTINGS_PATH = '.onedrop/template.toml';

    /**
     * @param  list<string>  $popular  ids shown under the prompt, in order
     */
    public function __construct(protected string $key, protected string $name, protected string $url, protected array $popular = []) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function templates(): array
    {
        $templates = [];

        foreach ($this->index() as $entry) {
            $id = $entry['id'] ?? null;

            if (! is_string($id) || ! self::isId($id) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $name = $entry['name'];
            $description = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';
            $links = is_array($entry['links'] ?? null) ? $entry['links'] : [];
            $logo = is_string($entry['logo'] ?? null) && $entry['logo'] !== '' ? $entry['logo'] : null;

            $templates[] = [
                'value' => "{$this->key}/{$id}",
                'label' => $name,
                'description' => $description,
                'prompt' => $description !== '' ? "Set up {$name}: {$description}" : "Set up {$name}.",
                'registry' => $this->name,
                'logo' => $logo ? "{$this->url}/blueprints/{$id}/".rawurlencode($logo) : null,
                'tags' => array_values(array_filter($entry['tags'] ?? [], 'is_string')),
                'compose' => true,
                // "latest" says nothing; a real version tells them what they'd get.
                'version' => is_string($entry['version'] ?? null) && ! in_array($entry['version'], ['', 'latest'], true) ? $entry['version'] : null,
                'links' => array_map(
                    fn (string $kind) => is_string($links[$kind] ?? null) && str_starts_with($links[$kind], 'https://') ? $links[$kind] : null,
                    ['website' => 'website', 'github' => 'github', 'docs' => 'docs'],
                ),
            ];
        }

        return $templates;
    }

    public function popular(): array
    {
        $templates = collect($this->templates())->keyBy('value');
        $popular = [];

        foreach ($this->popular as $id) {
            if ($template = $templates->get("{$this->key}/{$id}")) {
                $popular[] = $template;
            }
        }

        return $popular;
    }

    public function files(string $id): array
    {
        if (! self::isId($id)) {
            throw new TemplateException(__('There\'s no template called :id.', ['id' => $id]));
        }

        try {
            return [
                'docker-compose.yml' => $this->fetch("blueprints/{$id}/docker-compose.yml"),
                self::SETTINGS_PATH => $this->fetch("blueprints/{$id}/template.toml"),
            ];
        } catch (Throwable) {
            throw new TemplateException(__(':registry didn\'t send the template\'s files. Try again in a moment.', ['registry' => $this->name]));
        }
    }

    public function agentContext(array $template): string
    {
        $settings = self::SETTINGS_PATH;

        return <<<TEXT
            (This project starts from {$this->name}'s template for {$template['label']}. /workspace/docker-compose.yml is its stack and /workspace/{$settings} its {$this->name} settings. Set it up to run here with Docker:
            1. Write a .env beside docker-compose.yml from the settings' [variables] and [config.env]. Replace {$this->name}'s helpers: \${domain} and the domain variables are where the app is reached (localhost and its port for now); \${password:N}, \${base64:N} and \${hash:N} are random strings of N characters (32 without N); \${uuid}, \${username}, \${email} and \${jwt:...} are a value of that kind. Generate secrets with openssl, never placeholders.
            2. Write each [[config.mounts]] entry's content to its filePath under /workspace/files, and point the compose file's ../files/ paths at ./files/.
            3. [[config.domains]] names the web app's service and port. Publish that port in docker-compose.yml if it isn't (a different host port if it's 8081, 7681 or 2222), then run /opt/onedrop/compose init --preview <host port> and wait until it answers in the preview.
            Keep the stack as the template has it unless I ask for changes. When it's running, tell me in a few lines how to sign in and what to try first.)
            TEXT;
    }

    /**
     * The index, cached for a day (five minutes after a failed fetch).
     *
     * @return list<array<string, mixed>>
     */
    protected function index(): array
    {
        $key = "template-registry:{$this->key}:".md5($this->url);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_array'));
        }

        try {
            $index = Http::timeout(10)->acceptJson()->get("{$this->url}/meta.json")->throw()->json();
        } catch (Throwable) {
            $index = null;
        }

        if (! is_array($index) || ! array_is_list($index)) {
            Cache::put($key, [], now()->addMinutes(5));

            return [];
        }

        $index = array_values(array_filter($index, 'is_array'));
        Cache::put($key, $index, now()->addDay());

        return $index;
    }

    /**
     * @throws Throwable
     */
    protected function fetch(string $path): string
    {
        return Http::timeout(15)->get("{$this->url}/{$path}")->throw()->body();
    }

    /**
     * A template id is a folder name in the registry, never a path.
     */
    protected static function isId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/i', $id) === 1 && ! str_contains($id, '..');
    }
}
