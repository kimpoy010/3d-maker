<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreStylizationRequest extends FormRequest
{
    private const MAX_PIXELS = 40_000_000;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240', 'dimensions:min_width=512,min_height=512,max_width=8000,max_height=8000', $this->megapixelCap(...)],
            'style_id' => ['required', 'integer', Rule::exists('styles', 'id')->where('active', true)],
        ];
    }

    /** Decoding costs memory per pixel, so cap the total, not just each side. */
    private function megapixelCap(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->getRealPath()) {
            return;
        }

        $size = @getimagesize($value->getRealPath());

        if ($size !== false && $size[0] * $size[1] > self::MAX_PIXELS) {
            $fail('The photo is too large. Use an image under 40 megapixels.');
        }
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
