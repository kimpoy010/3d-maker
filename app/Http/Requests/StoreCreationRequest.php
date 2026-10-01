<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:min_width=512,min_height=512,max_width=8000,max_height=8000'],
            'style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('active', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.max' => 'The photo must be 10 MB or smaller.',
            'photo.dimensions' => 'The photo must be between 512 and 8000 pixels on each side.',
            'photo.mimes' => 'Use a JPG, PNG, or WebP photo.',
            'style_id.exists' => 'Choose one of the available styles.',
        ];
    }
}
