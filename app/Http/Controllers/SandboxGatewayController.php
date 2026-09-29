<?php

namespace App\Http\Controllers;

use App\Enums\SandboxStatus;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\User;
use App\Sandbox\Gateway;
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
        $userId = $target ? $gateway->userFromToken((string) $request->query('token'), $target) : null;

        if (! $userId) {
            return $this->loginRequired($target, 'expired');
        }

        // Absolute and HTTPS: behind Caddy's internal hop this request looks like plain HTTP.
        $sandbox = Sandbox::findOrFail($target['sandbox_id']);
        $destination = $gateway->url($sandbox, $target['kind']).self::safePath((string) $request->query('path', '/'));

        return redirect()->away($destination)->withCookie(new Cookie(
            Gateway::COOKIE,
            $gateway->pass($userId, $target),
            now()->addMinutes(Gateway::PASS_MINUTES),
            '/',
            null, // This address only.
            true, // Gateway addresses are always HTTPS.
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));
    }

    /**
     * Called by Caddy (forward_auth) or the Cloudflare Worker: may this browser open the preview or shell? If so, where does it live?
     */
    public function authorize(Request $request, Gateway $gateway): Response
    {
        $target = $gateway->parse((string) $gateway->requestedHost($request, $request->header('X-Forwarded-Host')));
        $sandbox = $target ? Sandbox::with('project')->find($target['sandbox_id']) : null;

        if (! $sandbox || $sandbox->status !== SandboxStatus::Running) {
            return response('Not found', 404);
        }

        $pass = $request->cookie(Gateway::COOKIE);
        $userId = $gateway->userFromPass(is_string($pass) ? $pass : null, $target);
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return $this->loginRequired($target, $pass ? 'expired' : 'no-cookie', $sandbox->project);
        }

        if ($user->cannot('view', $sandbox->project)) {
            return response('Forbidden', 403);
        }

        // The Worker forwards to the provider's address with its token; Caddy to a published host:port.
        if ($gateway->viaWorker()) {
            $upstream = $gateway->target($sandbox, $target['kind']);

            return $upstream
                ? response('', 200, array_filter([
                    'X-Zap-Upstream' => $upstream['url'],
                    'X-Zap-Upstream-Header' => $upstream['header'],
                    'X-Zap-Upstream-Token' => $upstream['token'],
                    'Cache-Control' => 'no-store',
                ]))
                : response('Not available', 404);
        }

        $upstream = $gateway->upstream($sandbox, $target['kind']);

        return $upstream
            ? response('', 200, ['X-Zap-Upstream' => $upstream])
            : response('Not available', 404);
    }

    /**
     * Called by Caddy (on_demand_tls "ask"): only issue certificates for addresses of existing sandboxes.
     */
    public function certificate(Request $request, Gateway $gateway): Response
    {
        $target = $gateway->parse((string) $request->query('domain'));

        return $target && Sandbox::whereKey($target['sandbox_id'])->exists()
            ? response('ok')
            : response('unknown', 404);
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
        ], 401)->header('X-Zap-Gateway', "login-required; reason={$reason}");
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
