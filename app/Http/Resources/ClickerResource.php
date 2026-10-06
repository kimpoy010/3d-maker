<?php

namespace App\Http\Resources;

use App\Enums\ClickerStatus;
use App\Models\Clicker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Clicker */
class ClickerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = fn (string $type) => route('clickers.files', ['clicker' => $this->id, 'type' => $type], absolute: false);
        $downloadable = $this->isUnlocked() && $this->status === ClickerStatus::Ready;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'error' => $this->build_error,
            'settings' => $this->settings,
            'body_id' => $this->clicker_body_id,
            'unlocked' => $this->isUnlocked(),
            'editable' => ! $this->isUnlocked() && $this->status !== ClickerStatus::Building,
            'created_at' => $this->created_at->toIso8601String(),
            'urls' => [
                'artwork' => $this->artwork_path ? $file('artwork') : null,
                'thumbnail' => $this->thumbnail_path ? $file('thumbnail') : null,
                // The links only appear once the downloads are paid for and built; the file route enforces it too.
                'threemf' => $downloadable && $this->threemf_path ? $file('3mf') : null,
                'stl_zip' => $downloadable && $this->stl_zip_path ? $file('stl') : null,
            ],
        ];
    }
}
