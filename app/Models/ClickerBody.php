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
     * The generated Cherry MX keycap body. The browser and the build script create the mesh from `keycap`
     * (see resources/clicker/geometry.js); `cap` and `artwork_area` describe the default square shape.
     * All values are millimetres. `stem` holds the cross socket numbers to tune after a test print.
     *
     * @return array<string, mixed>
     */
    public static function sampleConfig(): array
    {
        return [
            'generator' => 'mx_keycap',
            'cap' => ['shape' => 'rounded', 'width' => 30, 'height' => 30, 'corner_radius' => 4, 'top_z' => 8, 'inset' => 0],
            'artwork_area' => ['cx' => 0, 'cy' => 0, 'width' => 26, 'height' => 26],
            'max_layer_height' => 3,
            'min_feature' => 0.8,
            'switch' => 'Cherry MX',
            'keycap' => [
                'size' => 30,
                'rim' => 2,
                'corner_radius' => 4,
                'height' => 8,
                'socket_depth' => 4.5,
                'stem' => ['span' => 4.2, 'arm' => 1.35, 'wall' => 1.2],
            ],
        ];
    }

    /**
     * A plain development body: 40 x 50 mm rounded cap whose top surface is 6 mm above the build plate,
     * backed by a static model file. Kept for tests. `inset` keeps artwork that far inside the cap edge;
     * `min_feature` is the smallest detail that prints cleanly; `max_layer_height` caps how tall a colour may rise.
     *
     * @return array<string, mixed>
     */
    public static function legacyConfig(): array
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
