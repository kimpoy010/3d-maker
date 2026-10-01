<?php

namespace App\Services\Stylizers;

use App\Models\Style;
use App\Services\ModelProviders\PermanentProviderException;
use GdImage;

final class MockStylizer implements ImageStylizer
{
    private const BORDER_PX = 12;

    public function stylize(string $photoPath, Style $style): string
    {
        $rate = (float) config('stylizer.mock.fail_rate');

        if ($rate > 0 && random_int(1, 1_000_000) <= $rate * 1_000_000) {
            throw new PermanentProviderException("We can't process this photo. Please try a different one.");
        }

        $contents = is_file($photoPath) ? file_get_contents($photoPath) : false;
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new PermanentProviderException('We could not read that photo.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $border = imagecolorallocate($image, 230, 120, 90);

        for ($i = 0; $i < self::BORDER_PX; $i++) {
            imagerectangle($image, $i, $i, $width - 1 - $i, $height - 1 - $i, $border);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
