<?php

declare(strict_types=1);

namespace Demo\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a consumed DemoJob leaves behind. `workerPid` is the point of the table: several
 * rows carrying the same pid, with overlapping handled windows, is the pool doing what
 * it claims — one process holding several messages at once.
 *
 * @property int         $id
 * @property string      $payload
 * @property int         $worker_pid
 * @property int         $duration_ms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JobResult extends Model
{
    protected $fillable = [
        'payload',
        'worker_pid',
        'duration_ms',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'worker_pid'  => 'integer',
        'duration_ms' => 'integer',
    ];
}
