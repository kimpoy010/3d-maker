<?php

namespace App\Services\Clickers;

use App\Exceptions\InvalidImageException;
use GdImage;

/** Re-encodes uploaded artwork and thumbnails so only clean, bounded raster images are ever stored. */
class ClickerImages
{
    public const THUMBNAIL_EDGE = 480;

    /**
     * Decode and re-encode artwork as a PNG: metadata stripped, transparency kept, longest side capped.
     *
     * @throws InvalidImageException
     */
    public function artwork(string $path): string
    {
        $source = $this->decode($path);
        $width = imagesx($source);
        $height = imagesy($source);
        $minEdge = (int) config('clicker.upload.min_edge');

        if (min($width, $height) < $minEdge) {
            throw new InvalidImageException("The artwork must be at least {$minEdge} pixels on each side.");
        }

        $scale = min(1, (int) config('clicker.upload.max_edge') / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagepng($canvas, null, 6);

        return (string) ob_get_clean();
    }

    /**
     * A small JPEG preview of the design, flattened onto white.
     *
     * @throws InvalidImageException
     */
    public function thumbnail(string $path): string
    {
        $source = $this->decode($path);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::THUMBNAIL_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }

    /** @throws InvalidImageException */
    private function decode(string $path): GdImage
    {
        $size = is_file($path) ? @getimagesize($path) : false;

        if ($size === false) {
            throw new InvalidImageException('The file is not a valid image.');
        }

        // Decoding costs memory per pixel, so cap the total before decoding anything.
        if ((int) config('clicker.upload.max_pixels') < $size[0] * $size[1]) {
            throw new InvalidImageException('The image is too large. Use an image under 40 megapixels.');
        }

        $image = @imagecreatefromstring((string) file_get_contents($path));

        if (! $image instanceof GdImage) {
            throw new InvalidImageException('The file is not a valid image.');
        }

        return $image;
    }
}
