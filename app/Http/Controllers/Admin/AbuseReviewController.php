<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AbuseReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\AbuseReview;
use App\Sandbox\AbuseCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects the hosted install's abuse check held for review, and what platform admins decided (ADMIN-006).
 */
class AbuseReviewController extends Controller
{
    /** Most past decisions listed. */
    public const DECIDED_LIMIT = 30;

    /**
     * Show the held projects, then the latest decisions.
     */
    public function index(AbuseCheck $check): Response
    {
        $with = ['project.user:id,name,email', 'project.organization:id,name', 'project.share', 'decider:id,name'];

        $held = AbuseReview::with($with)->where('status', AbuseReviewStatus::Held)->latest('flagged_at')->get();
        $decided = AbuseReview::with($with)
            ->whereIn('status', [AbuseReviewStatus::Approved, AbuseReviewStatus::TakenDown])
            ->latest('decided_at')->limit(self::DECIDED_LIMIT)->get();

        return Inertia::render('admin/reviews', [
            'enabled' => $check->enabled(),
            'threshold' => AbuseCheck::THRESHOLD,
            'held' => $held->map(fn (AbuseReview $review) => $this->props($review)),
            'decided' => $decided->map(fn (AbuseReview $review) => $this->props($review)),
        ]);
    }

    /**
     * Let a held (or taken-down) project through: a held publish goes ahead and its share page comes back.
     */
    public function approve(Request $request, AbuseReview $review, AbuseCheck $check): RedirectResponse
    {
        $check->approve($review, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved.')]);

        return to_route('admin.reviews.index');
    }

    /**
     * Take the project's public app and share page down; it can't go public again until approved.
     */
    public function takeDown(Request $request, AbuseReview $review, AbuseCheck $check): RedirectResponse
    {
        $check->takeDown($review, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Taken down.')]);

        return to_route('admin.reviews.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function props(AbuseReview $review): array
    {
        $project = $review->project;

        return [
            'id' => $review->id,
            'status' => $review->status,
            'trigger' => $review->trigger,
            'score' => $review->score,
            'reasons' => collect($review->reasons ?? [])
                ->map(fn (float $probability, string $key) => ['key' => $key, 'label' => AbuseCheck::LABELS[$key] ?? $key, 'probability' => $probability])
                ->sortByDesc('probability')->values()->all(),
            'evidence' => $review->evidence,
            'flagged_at' => $review->flagged_at?->toIso8601String(),
            'decided_at' => $review->decided_at?->toIso8601String(),
            'decided_by' => $review->decider?->name,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'publish_status' => $project->publish_status,
                'publish_visibility' => $project->publish_visibility,
                'published_url' => $project->published_url,
                'share_url' => $project->share?->url(),
                'organization' => $project->organization?->name,
            ],
            'owner' => $project->user ? [
                ...$project->user->only('id', 'name', 'email'),
                'url' => route('users.show', $project->user),
            ] : null,
        ];
    }
}
