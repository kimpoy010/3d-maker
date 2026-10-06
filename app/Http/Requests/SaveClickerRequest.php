<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SaveClickerRequest extends FormRequest
{
    /** Ownership is checked by the controller through the policy. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->route('clicker') === null;
        $hex = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'name' => ['required', 'string', 'max:60'],
            'clicker_body_id' => ['required', 'integer', Rule::exists('clicker_bodies', 'id')->where('active', true)],
            'settings' => ['required', 'array'],
            'settings.colors' => ['required', 'array', 'min:1', 'max:'.config('clicker.max_colors')],
            'settings.colors.*.hex' => $hex,
            'settings.colors.*.height_mm' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'settings.colors.*.enabled' => ['required', 'boolean'],
            'settings.scale' => ['required', 'numeric', 'min:0.1', 'max:1'],
            'settings.offset_x' => ['required', 'numeric', 'between:-50,50'],
            'settings.offset_y' => ['required', 'numeric', 'between:-50,50'],
            'settings.outline_mm' => ['required', 'numeric', 'min:0', 'max:2'],
            'settings.outline_hex' => $hex,
            'artwork' => [$creating ? 'required' : 'nullable', 'file', 'mimes:png', 'max:'.config('clicker.upload.max_kb'), $this->megapixelCap(...)],
            'thumbnail' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:1024'],
        ];
    }

    /** Decoding costs memory per pixel, so cap the total, not just each side. */
    private function megapixelCap(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->getRealPath()) {
            return;
        }

        $size = @getimagesize($value->getRealPath());

        if ($size !== false && (int) config('clicker.upload.max_pixels') < $size[0] * $size[1]) {
            $fail('The artwork is too large. Use an image under 40 megapixels.');
        }
    }

    /**
     * The validated settings with real types (multipart forms deliver everything as strings).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = $this->validated('settings');

        return [
            'colors' => array_values(array_map(fn (array $color) => [
                'hex' => strtoupper($color['hex']),
                'height_mm' => (float) $color['height_mm'],
                'enabled' => filter_var($color['enabled'], FILTER_VALIDATE_BOOLEAN),
            ], $settings['colors'])),
            'scale' => (float) $settings['scale'],
            'offset_x' => (float) $settings['offset_x'],
            'offset_y' => (float) $settings['offset_y'],
            'outline_mm' => (float) $settings['outline_mm'],
            'outline_hex' => strtoupper($settings['outline_hex']),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'artwork.mimes' => 'The artwork must be a PNG image.',
            'artwork.max' => 'The artwork must be 10 MB or smaller.',
            'artwork.required' => 'Add some artwork first.',
            'clicker_body_id.exists' => 'Choose one of the available clicker bodies.',
            'settings.colors.max' => 'A design can use at most '.config('clicker.max_colors').' colours.',
            'settings.colors.*.hex.regex' => 'Each colour must look like #1D4ED8.',
        ];
    }
}
