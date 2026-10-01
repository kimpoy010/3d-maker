<?php

namespace App\Services;

use App\Exceptions\InvalidImageException;
use GdImage;

class ImageSanitizer
{
    public const MAX_EDGE = 2048;

    /**
     * Decode and re-encode an upload as a clean JPEG: metadata stripped, orientation
     * applied, transparency flattened onto white, longest edge capped.
     *
     * @throws InvalidImageException
     */
    public function sanitize(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new InvalidImageException('The image could not be read.');
        }

        $source = @imagecreatefromstring($contents);
        if (! $source instanceof GdImage) {
            throw new InvalidImageException('The file is not a valid image.');
        }

        // Read the orientation from the original bytes, then scale BEFORE rotating so the
        // rotation (which allocates a second canvas) only ever touches at most 2048 px.
        $angle = $this->orientationAngle($contents);
        $canvas = $this->flattenAndScale($source);
        unset($source);
        $canvas = $this->rotate($canvas, $angle);

        ob_start();
        imagejpeg($canvas, null, 90);

        return (string) ob_get_clean();
    }

    private function orientationAngle(string $contents): int
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($contents, "\xFF\xD8")) {
            return 0;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contents));

        return match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    private function rotate(GdImage $image, int $angle): GdImage
    {
        if ($angle === 0) {
            return $image;
        }

        return imagerotate($image, $angle, 0) ?: $image;
    }

    private function flattenAndScale(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }
}
