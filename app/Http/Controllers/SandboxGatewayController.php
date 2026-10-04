<?php

namespace App\Http\Controllers;

use App\Enums\PublishVisibility;
use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Gateway;
use App\Sandbox\SandboxProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Cookie;

class SandboxGatewayController extends Controller
{
    /**
     * From the app: send a logged-in user to a project's preview or shell with a hand-off token.
     * The workspace frames point here, so every page load renews the address's cookie.
     */
    public function open(Request $request, Project $project, string $kind, Gateway $gateway): RedirectResponse
    {
        // A project published privately to the domain opens for anyone in its organization, not just its owners (ORG-007).
        if ($kind === Gateway::APP) {
            $sandbox = $project->sandbox;

            abort_unless($sandbox && $request->user()->belongsToOrganization($project->organization_id) && $gateway->parse((string) parse_url((string) $project->published_url, PHP_URL_HOST)) !== null, 404);

            $path = (string) $request->query('path', '/');

            return redirect()->away($gateway->enterUrl($sandbox, $kind, $request->user(), self::safePath($path)));
        }

        Gate::authorize('view', $project);

        // A task's own copy of the app (TASK-003), when asked for one.
        $sandbox = $request->filled('task')
            ? $project->sandboxes()->where('task_id', (int) $request->query('task'))->first()
            : $project->sandbox;

        abort_unless($gateway->enabled() && in_array($kind, Gateway::KINDS, true) && $sandbox, 404);

        $path = (string) $request->query('path', '/');

        return redirect()->away($gateway->enterUrl($sandbox, $kind, $request->user(), self::safePath($path)));
    }

    /**
     * On a preview or shell address: trade the hand-off token for this address's own cookie.
     */
    public function enter(Request $request, Gateway $gateway): Response|RedirectResponse
    {
        $target = $gateway->parse((string) $gateway->requestedHost($request, $request->getHost()));
        $token = (string) $request->query('token');
        $userId = $target ? $gateway->userFromToken($token, $target) : null;

        if (! $userId) {
            return $this->loginRequired($target, 'expired');
        }

        // Absolute and HTTPS: behind Caddy's internal hop this request looks like plain HTTP.
        $sandbox = Sandbox::findOrFail($target['sandbox_id']);
        $destination = $gateway->url($sandbox, $target['kind']).self::safePath((string) $request->query('path', '/'));

        // In the desktop app the address is a frame on another site, where WebKit keeps only partitioned cookies,
        // and only cross-site (SameSite=None) ones are sent to a frame there.
        $partitioned = $gateway->wantsPartitionedPass($token);

        return redirect()->away($destination)->withCookie(new Cookie(
            Gateway::COOKIE,
            $gateway->pass($userId, $target),
            now()->addMinutes(Gateway::PASS_MINUTES),
            '/',
            null, // This address only.
            true, // Gateway addresses are always HTTPS.
            true,
            false,
            $partitioned ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
            $partitioned,
        ));
    }

    /**
     * Called by Caddy (forward_auth) or the Cloudflare Worker: may this browser open the preview or shell? If so, where does it live?
     */
    public function authorize(Request $request, Gateway $gateway, SandboxProvider $provider): Response|RedirectResponse
    {
        $host = (string) $gateway->requestedHost($request, $request->header('X-Forwarded-Host'));
        $target = $gateway->parse($host);
        $sandbox = $target ? Sandbox::with('project')->find($target['sandbox_id']) : null;

        if (! $sandbox || $sandbox->status !== SandboxStatus::Running) {
            return response('Not found', 404);
        }

        // The project's own address and its other domains send visitors on to its primary domain (DOM-002).
        $redirect = $target['kind'] === Gateway::APP
            ? $gateway->canonicalRedirect($sandbox->project, $host, self::safePath((string) $request->header('X-Forwarded-Uri', '/')))
            : null;

        if ($redirect) {
            return redirect()->away($redirect);
        }

        // A project published publicly to the domain: anyone may open it.
        $public = $target['kind'] === Gateway::APP && $sandbox->project->publish_visibility === PublishVisibility::Public;

        if (! $public) {
            $pass = $request->cookie(Gateway::COOKIE);
            $userId = $gateway->userFromPass(is_string($pass) ? $pass : null, $target);
            $user = $userId ? User::find($userId) : null;

            // Someone opening a private app's link: sign in to OneDrop, then come back to the page they asked for.
            if (! $user && $target['kind'] === Gateway::APP) {
                return redirect()->away(self::appUrl(route('projects.gateway.open', [
                    $sandbox->project, Gateway::APP, 'path' => self::safePath((string) $request->header('X-Forwarded-Uri', '/')),
                ], false)));
            }

            if (! $user) {
                return $this->loginRequired($target, $pass ? 'expired' : 'no-cookie', $sandbox->project);
            }

            // Previews and shells are for the project's people; a privately published app for its organization.
            $allowed = $target['kind'] === Gateway::APP
                ? $user->belongsToOrganization($sandbox->project->organization_id)
                : $user->can('view', $sandbox->project);

            if (! $allowed) {
                return response('Forbidden', 403);
            }
        }

        // Someone is using it: wake it if it was suspended for sitting idle (SBX-007).
        $sandbox->wake($provider);

        // The Worker forwards to the provider's address with its token; Caddy to a published host:port.
        if ($gateway->viaWorker()) {
            $upstream = $gateway->target($sandbox, $target['kind']);

            return $upstream
                ? response('', 200, array_filter([
                    'X-OneDrop-Upstream' => $upstream['url'],
                    'X-OneDrop-Upstream-Header' => $upstream['header'],
                    'X-OneDrop-Upstream-Token' => $upstream['token'],
                    'Cache-Control' => 'no-store',
                ]))
                : response('Not available', 404);
        }

        $upstream = $gateway->upstream($sandbox, $target['kind']);

        return $upstream
            ? response('', 200, ['X-OneDrop-Upstream' => $upstream])
            : response('Not available', 404);
    }

    /**
     * Called by Caddy (on_demand_tls "ask"): only issue certificates for addresses of existing sandboxes, and for
     * custom domains a project added (DOM-001), so names pointed at the server by anyone else get none.
     */
    public function certificate(Request $request, Gateway $gateway): Response
    {
        $host = strtolower((string) $request->query('domain'));
        $target = $gateway->parse($host);

        $known = ($target && Sandbox::whereKey($target['sandbox_id'])->exists())
            || ProjectDomain::where('hostname', $host)->where('via', 'caddy')->exists();

        return $known ? response('ok') : response('unknown', 404);
    }

    /**
     * @param  array{kind: string, sandbox_id: int}|null  $target
     */
    protected function loginRequired(?array $target, string $reason, ?Project $project = null): Response
    {
        $project ??= $target ? Sandbox::find($target['sandbox_id'])?->project : null;

        return response()->view('gateway.login-required', [
            'reason' => $reason,
            'openUrl' => $project && $target
                ? self::appUrl(route('projects.gateway.open', [$project, $target['kind']], false))
                : self::appUrl(route('login', absolute: false)),
        ], 401)->header('X-OneDrop-Gateway', "login-required; reason={$reason}");
    }

    /**
     * A link back to the app itself (these responses are served on preview and shell hosts).
     */
    protected static function appUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * Only same-site paths, so the hand-off can't be used to redirect elsewhere.
     */
    protected static function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\')
            ? $path
            : '/';
    }
}
