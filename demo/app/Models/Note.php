<?php

declare(strict_types=1);

namespace Demo\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int             $id
 * @property string          $title
 * @property string          $body
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 */
class Note extends Model
{
    protected $fillable = [
        'title',
        'body',
    ];
}
