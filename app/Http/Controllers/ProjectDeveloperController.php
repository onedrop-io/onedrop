<?php

namespace App\Http\Controllers;

use App\Enums\PublishStatus;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Sandbox\Gateway;
use App\Sandbox\SandboxException;
use App\Sandbox\SandboxInspector;
use App\Sandbox\WorkspaceSsh;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tools → Developer: networking, resources and SSH details for the project's sandbox.
 */
class ProjectDeveloperController extends Controller
{
    /**
     * The preview and published URLs (with QR codes where a phone can open them) and the ports open in the sandbox.
     */
    public function networking(Project $project, SandboxInspector $inspector, Gateway $gateway): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($project, $inspector, $gateway) {
            $preview = $sandbox->preview_url ? ($gateway->enabled() ? route('projects.gateway.open', [$project, 'preview']) : $sandbox->preview_url) : null;
            $local = $preview !== null && $this->isLocal($preview);
            $published = $project->publish_status === PublishStatus::Live ? $project->published_url : null;

            return [
                'preview' => $preview ? ['url' => $preview, 'local' => $local, 'qr' => $local ? null : $this->qrCode($preview)] : null,
                'published' => $published ? ['url' => $published, 'visibility' => $project->publish_visibility, 'qr' => $this->qrCode($published)] : null,
                'app_port' => config('sandbox.port'),
                'ports' => $inspector->ports($sandbox),
            ];
        });
    }

    /**
     * Current CPU and memory use against the sandbox's limits.
     */
    public function usage(Project $project, SandboxInspector $inspector): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['usage' => $inspector->usage($sandbox)]);
    }

    /**
     * Disk use of the workspace, its dependencies and App Storage.
     */
    public function storage(Project $project, SandboxInspector $inspector): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, fn (Sandbox $sandbox) => ['storage' => $inspector->storage($sandbox)]);
    }

    /**
     * How to SSH into the sandbox, after making sure it has the owner's current keys.
     */
    public function ssh(Request $request, Project $project, WorkspaceSsh $ssh, Gateway $gateway): JsonResponse
    {
        Gate::authorize('view', $project);

        return $this->fromSandbox($project, function (Sandbox $sandbox) use ($request, $project, $ssh, $gateway) {
            $address = WorkspaceSsh::address($sandbox->ssh_address);
            $owner = $project->user;

            $unavailable = match (true) {
                $gateway->enabled() => __("SSH isn't available on this server yet. Use the Shell tab to run commands in the sandbox."),
                $address === null => __('This sandbox was created before SSH was added. Recreate it to connect over SSH.'),
                ! $ssh->sync($sandbox) => __("This sandbox's image doesn't have SSH yet. Rebuild the image and recreate the sandbox to connect over SSH."),
                default => null,
            };

            return [
                'unavailable' => $unavailable,
                'host_alias' => WorkspaceSsh::hostAlias($project),
                'host' => $address['host'] ?? null,
                'port' => $address['port'] ?? null,
                'user' => WorkspaceSsh::USER,
                'path' => '/workspace',
                'owner_keys' => $owner->sshKeys()->count(),
                'is_owner' => $owner->is($request->user()),
                'owner_name' => $owner->name,
            ];
        });
    }

    /**
     * Whether a URL points at the machine running the app builder (so another device can't open it).
     */
    protected function isLocal(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost');
    }

    /**
     * A QR code for the URL, as an inline SVG.
     */
    protected function qrCode(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(160, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(10, 10, 10))),
            new SvgImageBackEnd,
        )))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * @param  callable(Sandbox): array<string, mixed>  $call
     */
    protected function fromSandbox(Project $project, callable $call): JsonResponse
    {
        $sandbox = $project->sandbox;

        if ($sandbox?->status !== SandboxStatus::Running || $sandbox->external_id === null) {
            return response()->json(['message' => __("The project's sandbox isn't running.")], 409);
        }

        try {
            return response()->json($call($sandbox));
        } catch (SandboxException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
