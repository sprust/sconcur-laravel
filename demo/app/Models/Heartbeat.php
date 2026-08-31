<?php

declare(strict_types=1);

namespace Demo\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per task of the periodic pool, updated on every tick. A growing `ticks` is
 * how the page shows that the third runtime is alive without asking the pool anything.
 *
 * @property int         $id
 * @property string      $name
 * @property int         $ticks
 * @property int         $worker_pid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Heartbeat extends Model
{
    protected $fillable = [
        'name',
        'ticks',
        'worker_pid',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'ticks'      => 'integer',
        'worker_pid' => 'integer',
    ];
}
