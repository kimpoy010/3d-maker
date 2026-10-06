<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** A real JPEG for tests that need file contents on the fake disk. */
function fakeJpegBytes(int $width = 800, int $height = 800): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 160, 200));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

/** A PNG whose header is valid but whose body is cut off, so GD cannot decode it. */
function truncatedPngBytes(int $width = 64, int $height = 96): string
{
    $image = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            imagesetpixel($image, $x, $y, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
    }

    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    return substr($png, 0, intdiv(strlen($png), 2));
}

/** A real PNG. When $transparent, the top-left quarter is fully transparent and the rest is opaque red. */
function fakePngBytes(int $width = 400, int $height = 400, bool $transparent = true): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 220, 38, 38, 0));

    if ($transparent) {
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, intdiv($height, 2) - 1, imagecolorallocatealpha($image, 0, 0, 0, 127));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}
