<?php

namespace App\Models;

use App\Enums\AbuseReviewStatus;
use Database\Factories\AbuseReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project's abuse check on the hosted install (PUB-003): what Jev was shown, how likely each kind of abuse
 * looked, and, when it was held, what a platform admin decided (ADMIN-006).
 *
 * @property int $id
 * @property int $project_id
 * @property AbuseReviewStatus $status
 * @property string $trigger What asked for the check: publish, share or recheck
 * @property float|null $score The likeliest kind of abuse's probability
 * @property array<string, float>|null $reasons Each kind of abuse's probability, by question key
 * @property array<string, mixed>|null $evidence What Jev was shown
 * @property Carbon|null $checked_at
 * @property Carbon|null $flagged_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['status', 'trigger', 'score', 'reasons', 'evidence', 'checked_at', 'flagged_at', 'decided_by', 'decided_at'])]
class AbuseReview extends Model
{
    /** @use HasFactory<AbuseReviewFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AbuseReviewStatus::class,
            'score' => 'float',
            'reasons' => 'array',
            'evidence' => 'array',
            'checked_at' => 'datetime',
            'flagged_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
