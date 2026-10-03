<?php

namespace App\Http\Resources;

use App\Models\Creation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Creation */
class CreationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = fn (string $type, array $query = []) => route(
            'creations.files',
            ['creation' => $this->id, 'type' => $type] + $query,
            absolute: false,
        );

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'error' => $this->error,
            'progress' => $this->progress,
            'cost_credits' => $this->cost_credits,
            'created_at' => $this->created_at->toIso8601String(),
            'style' => [
                'name' => $this->style->name,
                'subject' => $this->style->subject->value,
                'look' => $this->style->look,
            ],
            'urls' => [
                'source' => $file('source'),
                'model' => $this->model_path ? $file('model') : null,
                'thumbnail' => $this->thumbnail_path ? $file('thumbnail') : null,
                'download' => $this->model_path ? $file('model', ['download' => 1]) : null,
                'download_stl' => $this->print_model_path ? $file('print') : null,
            ],
        ];
    }
}
