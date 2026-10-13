<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ActivityLog extends Model
{
    protected $fillable = [
        'type',
        'title',
        'description',
        'status',
        'icon',
        'user_id',
        'business_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
