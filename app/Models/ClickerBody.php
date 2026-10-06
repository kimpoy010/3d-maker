<?php

namespace App\Models;

use Database\Factories\ClickerBodyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClickerBody extends Model
{
    /** @use HasFactory<ClickerBodyFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'name', 'description', 'preview_image', 'model_files', 'config', 'active'];

    protected function casts(): array
    {
        return ['model_files' => 'array', 'config' => 'array', 'active' => 'boolean'];
    }

    /** @param Builder<ClickerBody> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function clickers(): HasMany
    {
        return $this->hasMany(Clicker::class);
    }

    /**
     * A plain development body: 40 x 50 mm rounded cap whose top surface is 6 mm above the build plate.
     * All values are millimetres. `inset` keeps artwork that far inside the cap edge; `min_feature` is the
     * smallest detail that prints cleanly; `max_layer_height` caps how tall a colour may rise.
     *
     * @return array<string, mixed>
     */
    public static function sampleConfig(): array
    {
        return [
            'cap' => ['shape' => 'rounded', 'width' => 40, 'height' => 50, 'corner_radius' => 6, 'top_z' => 6, 'inset' => 0.5],
            'artwork_area' => ['cx' => 0, 'cy' => 0, 'width' => 34, 'height' => 44],
            'base_thickness' => 1.2,
            'max_layer_height' => 3,
            'min_feature' => 0.8,
            'switch' => 'None (development body)',
        ];
    }
}
