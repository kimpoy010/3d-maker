<?php

namespace App\Models;

use App\Enums\Subject;
use Database\Factories\StyleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Style extends Model
{
    /** @use HasFactory<StyleFactory> */
    use HasFactory;

    protected $fillable = ['subject', 'name', 'look', 'prompt', 'provider_params', 'credit_cost', 'active', 'preview_image'];

    protected function casts(): array
    {
        return [
            'subject' => Subject::class,
            'provider_params' => 'array',
            'active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
