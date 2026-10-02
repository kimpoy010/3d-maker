<?php

namespace App\Services\Stylizers;

final class StylizedImage
{
    /** File extension for the real format of the image bytes: "jpg" or "png". */
    public static function extension(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);

        return ($info[2] ?? null) === IMAGETYPE_JPEG ? 'jpg' : 'png';
    }
}
