<?php

use App\Exceptions\InvalidImageException;
use App\Services\Clickers\ClickerImages;

beforeEach(function () {
    $this->images = new ClickerImages;
    $this->file = function (string $bytes): string {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return $path;
    };
});

it('re-encodes artwork as a PNG and keeps its transparency', function () {
    $png = $this->images->artwork(($this->file)(fakePngBytes(400, 400)));
    $image = imagecreatefromstring($png);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and((imagecolorat($image, 5, 5) >> 24) & 0x7F)->toBe(127, 'the top-left stays transparent')
        ->and((imagecolorat($image, 300, 300) >> 24) & 0x7F)->toBe(0, 'the rest stays opaque');
});

it('shrinks artwork wider than 4096 px', function () {
    $png = $this->images->artwork(($this->file)(fakePngBytes(5000, 300)));

    expect(getimagesizefromstring($png)[0])->toBe(4096)
        ->and(getimagesizefromstring($png)[1])->toBeLessThan(300);
});

it('rejects artwork that is too small', function () {
    expect(fn () => $this->images->artwork(($this->file)(fakePngBytes(100, 100))))
        ->toThrow(InvalidImageException::class, 'at least 256');
});

it('rejects a file that is not an image', function () {
    expect(fn () => $this->images->artwork(($this->file)('this is not an image')))
        ->toThrow(InvalidImageException::class, 'not a valid image');
    expect(fn () => $this->images->artwork('/no/such/file.png'))
        ->toThrow(InvalidImageException::class);
});

it('rejects an image over 40 megapixels without decoding it', function () {
    // A header that only declares 8000 x 8000 pixels: decoding it would need far more memory than the file is worth.
    $bomb = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 8000, 8000)."\x08\x02\x00\x00\x00".pack('N', 0);

    expect(fn () => $this->images->artwork(($this->file)($bomb)))
        ->toThrow(InvalidImageException::class, '40 megapixels');
});

it('makes a small JPEG thumbnail', function () {
    $jpeg = $this->images->thumbnail(($this->file)(fakePngBytes(1000, 600)));
    $size = getimagesizefromstring($jpeg);

    expect(substr($jpeg, 0, 2))->toBe("\xFF\xD8")
        ->and($size[0])->toBe(480)
        ->and($size[1])->toBe(288);
});
