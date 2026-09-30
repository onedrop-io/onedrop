<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One sample of the server's resources (ADMIN-003), recorded every minute by `php artisan server:sample`.
 * Counters (CPU time, bytes read/written/sent/received) are totals since boot; the charts use the differences.
 *
 * @property int $id
 * @property Carbon $recorded_at
 * @property int|null $cpu_busy
 * @property int|null $cpu_total
 * @property int|null $memory_used
 * @property int|null $memory_total
 * @property int|null $disk_used
 * @property int|null $disk_total
 * @property int|null $disk_read
 * @property int|null $disk_written
 * @property int|null $network_in
 * @property int|null $network_out
 */
#[Fillable(['recorded_at', 'cpu_busy', 'cpu_total', 'memory_used', 'memory_total', 'disk_used', 'disk_total', 'disk_read', 'disk_written', 'network_in', 'network_out'])]
class ServerMetric extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'cpu_busy' => 'integer',
            'cpu_total' => 'integer',
            'memory_used' => 'integer',
            'memory_total' => 'integer',
            'disk_used' => 'integer',
            'disk_total' => 'integer',
            'disk_read' => 'integer',
            'disk_written' => 'integer',
            'network_in' => 'integer',
            'network_out' => 'integer',
        ];
    }
}
