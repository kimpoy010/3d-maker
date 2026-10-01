<?php

namespace App\Http\Resources;

use App\Models\Stylization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Stylization */
class StylizationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = fn (string $type) => route(
            'stylizations.files',
            ['stylization' => $this->id, 'type' => $type],
            absolute: false,
        );

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'error' => $this->error,
            'cost_credits' => $this->cost_credits,
            'created_at' => $this->created_at->toIso8601String(),
            'creation_id' => $this->creation_id,
            'style' => [
                'id' => $this->style->id,
                'name' => $this->style->name,
                'subject' => $this->style->subject->value,
                'look' => $this->style->look,
                'credit_cost' => $this->style->credit_cost,
            ],
            'urls' => [
                'original' => $this->source_image_path ? $file('original') : null,
                'result' => $this->result_image_path ? $file('result') : null,
            ],
        ];
    }
}
