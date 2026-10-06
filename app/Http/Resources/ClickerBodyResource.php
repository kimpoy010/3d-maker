<?php

namespace App\Http\Resources;

use App\Models\ClickerBody;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @mixin ClickerBody */
class ClickerBodyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'preview' => $this->preview_image ? '/'.$this->preview_image : null,
            // The model files stay on the server; the browser only needs the numbers that drive the preview.
            'config' => Arr::only($this->config, ['cap', 'artwork_area', 'max_layer_height', 'min_feature', 'switch']),
        ];
    }
}
