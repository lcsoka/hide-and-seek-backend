<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an uploaded avatar into two optimized, center-cropped square WebP images: a display-sized
 * `full` and a small `thumb` (for map markers / lists). Uses plain GD — no extra dependency — and
 * honours the JPEG EXIF orientation so phone photos aren't sideways.
 */
class AvatarProcessor
{
    private const FULL = 512;

    private const THUMB = 96;

    private const QUALITY = 82;

    /**
     * @return array{avatar: string, thumb: string} public URLs of the stored images
     */
    public function process(UploadedFile $file): array
    {
        $src = $this->readOriented($file);

        try {
            $square = $this->cropSquare($src);
            $base = 'avatars/'.Str::uuid()->toString();
            $avatar = $this->writeWebp($square, self::FULL, $base.'.webp');
            $thumb = $this->writeWebp($square, self::THUMB, $base.'_thumb.webp');
            imagedestroy($square);

            return [
                'avatar' => Storage::disk('public')->url($avatar),
                'thumb' => Storage::disk('public')->url($thumb),
            ];
        } finally {
            imagedestroy($src);
        }
    }

    /** Delete the stored full + thumb files behind a public /storage URL (ignores anything else). */
    public function deleteByUrl(?string $avatarUrl, ?string $thumbUrl): void
    {
        foreach ([$avatarUrl, $thumbUrl] as $url) {
            if ($url && str_contains($url, '/storage/')) {
                Storage::disk('public')->delete(Str::after($url, '/storage/'));
            }
        }
    }

    private function readOriented(UploadedFile $file): \GdImage
    {
        $data = (string) file_get_contents($file->getRealPath());
        $image = imagecreatefromstring($data);
        if ($image === false) {
            throw new \RuntimeException('Unsupported image.');
        }

        // JPEG orientation lives in EXIF; rotate so the picture is upright before cropping.
        if (function_exists('exif_read_data') && in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            $exif = @exif_read_data($file->getRealPath());
            $orientation = $exif['Orientation'] ?? 0;
            $angle = match ($orientation) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($image, $angle, 0);
                if ($rotated !== false) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }

        return $image;
    }

    /** Center-crop to a square using the shorter side. */
    private function cropSquare(\GdImage $src): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = (int) (($w - $side) / 2);
        $y = (int) (($h - $side) / 2);

        $square = imagecreatetruecolor($side, $side);
        imagecopy($square, $src, 0, 0, $x, $y, $side, $side);

        return $square;
    }

    /** Scale the square to $size and write a WebP to the public disk; returns the relative path. */
    private function writeWebp(\GdImage $square, int $size, string $path): string
    {
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $square, 0, 0, 0, 0, $size, $size, imagesx($square), imagesy($square));

        ob_start();
        imagewebp($out, null, self::QUALITY);
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
}
