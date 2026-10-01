<?php

use App\Exceptions\InvalidImageException;
use App\Services\ImageSanitizer;

function tempImage(int $w, int $h, string $format = 'png'): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
    $path = tempnam(sys_get_temp_dir(), 'img');
    $format === 'png' ? imagepng($img, $path) : imagejpeg($img, $path);

    return $path;
}

it('re-encodes any supported image as jpeg', function () {
    $path = tempImage(800, 600, 'png');

    $bytes = (new ImageSanitizer)->sanitize($path);

    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8");
    [$w, $h] = getimagesizefromstring($bytes);
    expect([$w, $h])->toBe([800, 600]);
});

it('downscales images whose longest edge exceeds 2048', function () {
    $path = tempImage(4096, 2048);

    [$w, $h] = getimagesizefromstring((new ImageSanitizer)->sanitize($path));

    expect([$w, $h])->toBe([2048, 1024]);
});

it('rejects files that are not images', function () {
    $path = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($path, '<?php echo "hi";');

    expect(fn () => (new ImageSanitizer)->sanitize($path))->toThrow(InvalidImageException::class);
});

it('rejects missing files', function () {
    expect(fn () => (new ImageSanitizer)->sanitize('/no/such/file.jpg'))->toThrow(InvalidImageException::class);
});
