<?php

namespace App\Models;

use App\Enums\StylizationStatus;
use Database\Factories\StylizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stylization extends Model
{
    /** @use HasFactory<StylizationFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'style_id', 'source_image_path', 'result_image_path',
        'status', 'error', 'cost_credits', 'creation_id',
    ];

    protected function casts(): array
    {
        return ['status' => StylizationStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(Style::class);
    }

    public function creation(): BelongsTo
    {
        return $this->belongsTo(Creation::class);
    }
}
