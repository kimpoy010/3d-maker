<?php

namespace App\Models;

use App\Enums\CreationStatus;
use Database\Factories\CreationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Creation extends Model
{
    /** @use HasFactory<CreationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'style_id', 'source_image_path', 'status', 'provider_job_id',
        'model_path', 'print_model_path', 'downloads_unlocked_at', 'thumbnail_path', 'error', 'cost_credits', 'progress',
    ];

    protected function casts(): array
    {
        return ['status' => CreationStatus::class, 'downloads_unlocked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }
}
