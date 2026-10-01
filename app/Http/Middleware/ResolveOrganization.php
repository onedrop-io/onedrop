<?php

namespace App\Http\Middleware;

use App\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which organization a signed-in request is in (ORG-002): the one in the address (`/o/{slug}`), else the
 * open project's, else the one the user used last. Only members get into an organization's pages; others get a 404.
 */
class ResolveOrganization
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $request->attributes->set('organization', $this->resolve($request, $user));
        }

        return $next($request);
    }

    /**
     * The organization the request is in, if it's signed in.
     */
    public static function current(Request $request): ?Organization
    {
        $organization = $request->attributes->get('organization');

        return $organization instanceof Organization ? $organization : $request->user()?->currentOrganization();
    }

    protected function resolve(Request $request, User $user): Organization
    {
        $named = $request->route('organization');

        if ($named instanceof Organization) {
            abort_unless($user->belongsToOrganization($named), 404);

            // A group or invite from another organization isn't found here, even by someone in both.
            foreach ($request->route()->parameters() as $parameter) {
                if ($parameter instanceof Model && in_array(BelongsToOrganization::class, class_uses_recursive($parameter), true)) {
                    abort_unless($parameter->getAttribute('organization_id') === $named->id, 404);
                }
            }

            // Controllers ask for it with current(), so their other parameters keep their places.
            $request->route()->forgetParameter('organization');
            $user->switchOrganization($named);

            return $named;
        }

        $project = $request->route('project');

        if ($project instanceof Project && $user->belongsToOrganization($project->organization_id)) {
            $user->switchOrganization($project->organization);

            return $project->organization;
        }

        return $user->currentOrganization();
    }
}
