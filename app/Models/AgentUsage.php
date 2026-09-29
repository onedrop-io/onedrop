<?php

namespace App\Models;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use Database\Factories\AgentUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tokens one model used in an agent run (or, for OpenCode, one step of it), and their estimated
 * API cost, charged to the project owner whose AI connection ran it (USAGE-001).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $project_id
 * @property int|null $task_id
 * @property AgentHarness $harness
 * @property AgentProvider|null $provider
 * @property string $model
 * @property string|null $session_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property float $cost
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'project_id', 'task_id', 'harness', 'provider', 'model', 'session_id', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost'])]
class AgentUsage extends Model
{
    /** @use HasFactory<AgentUsageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'harness' => AgentHarness::class,
            'provider' => AgentProvider::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost' => 'float',
        ];
    }

    /**
     * The user whose AI connection paid for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project the run was in, or null once it's deleted.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
