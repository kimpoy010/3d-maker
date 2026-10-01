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

/** Insert an EXIF APP1 segment carrying the given orientation right after the SOI marker. */
function withExifOrientation(string $jpeg, int $orientation): string
{
    $tiff = "II\x2A\x00\x08\x00\x00\x00"
        .pack('v', 1)
        .pack('vvVv', 0x0112, 3, 1, $orientation)."\x00\x00"
        ."\x00\x00\x00\x00";
    $payload = "Exif\x00\x00".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

it('applies EXIF orientation 6 and scales to the max edge', function () {
    $img = imagecreatetruecolor(3000, 2000);
    ob_start();
    imagejpeg($img, null, 80);
    $path = tempnam(sys_get_temp_dir(), 'exif');
    file_put_contents($path, withExifOrientation(ob_get_clean(), 6));

    [$w, $h] = getimagesizefromstring((new ImageSanitizer)->sanitize($path));

    expect([$w, $h])->toBe([1365, 2048]);
});

it('scales before rotating so a large portrait photo stays within a small memory limit', function () {
    $script = tempnam(sys_get_temp_dir(), 'scr').'.php';
    $out = tempnam(sys_get_temp_dir(), 'big');
    file_put_contents($script, '<?php
        require '.var_export(base_path('vendor/autoload.php'), true).';
        $img = imagecreatetruecolor(6000, 4000);
        ob_start(); imagejpeg($img, null, 50); $jpeg = ob_get_clean(); unset($img);
        $tiff = "II\x2A\x00\x08\x00\x00\x00".pack("v", 1).pack("vvVv", 0x0112, 3, 1, 6)."\x00\x00"."\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;
        file_put_contents($argv[1], substr($jpeg, 0, 2)."\xFF\xE1".pack("n", strlen($payload) + 2).$payload.substr($jpeg, 2));
        unset($jpeg);
        $bytes = (new App\Services\ImageSanitizer)->sanitize($argv[1]);
        echo implode("x", array_slice(getimagesizefromstring($bytes), 0, 2));
    ');

    // Decoding 6000x4000 needs ~96 MB. Rotating the full-size image first would need ~192 MB.
    $result = shell_exec(escapeshellarg(PHP_BINARY).' -d memory_limit=150M '.escapeshellarg($script).' '.escapeshellarg($out).' 2>&1');

    expect(trim((string) $result))->toBe('1365x2048');
});
