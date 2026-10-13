<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Report extends Model
{
    protected $fillable = [
        'sbo_id',
        'admin_id',
        'title',
        'description',
        'period_start',
        'period_end',
        'metrics',
        'status',
        'admin_id',
    ];

    protected $casts = [
        'metrics' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function sbo(): BelongsTo
    {
        return $this->belongsTo(Sbo::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
