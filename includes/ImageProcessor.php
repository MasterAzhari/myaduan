<?php
namespace MyAduan;

/**
 * Validates and re-encodes uploaded photos. Re-encoding drops all metadata
 * (EXIF, including the GPS position of the complainant's phone) and any
 * payload hidden after the image data.
 */
final class ImageProcessor
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 2000;
    private const MAX_PIXELS = 40_000_000; // Guards against decompression bombs.
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * @return array{bytes:string, mime:string, ext:string, width:int, height:int, source_sha256:string}
     * @throws WorkflowException
     */
    public static function process(string $bytes, string $label = 'Gambar'): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new WorkflowException("$label mesti kurang daripada 5 MB.", 'invalid_image');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!isset(self::TYPES[$mime])) {
            throw new WorkflowException("$label mesti JPG, PNG atau WebP.", 'invalid_image');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new WorkflowException("$label rosak atau terlalu besar.", 'invalid_image');
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw new WorkflowException("$label tidak dapat dibaca.", 'invalid_image');
        }
        if ($mime === 'image/jpeg') {
            $img = self::apply_orientation($img, $bytes);
        }
        $img = self::fit($img, self::MAX_SIDE);

        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($img, null, 82),
            'image/png' => imagepng($img, null, 6),
            'image/webp' => imagewebp($img, null, 80),
        };
        $out = (string) ob_get_clean();
        $width = imagesx($img);
        $height = imagesy($img);
        imagedestroy($img);
        if (!$ok || $out === '') {
            throw new WorkflowException("$label tidak dapat diproses.", 'invalid_image');
        }
        return ['bytes' => $out, 'mime' => $mime, 'ext' => self::TYPES[$mime], 'width' => $width, 'height' => $height, 'source_sha256' => hash('sha256', $bytes)];
    }

    /** Phones store rotation in EXIF; bake it into the pixels before EXIF is dropped. */
    private static function apply_orientation(\GdImage $img, string $bytes): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated) {
            imagedestroy($img);
            return $rotated;
        }
        return $img;
    }

    private static function fit(\GdImage $img, int $max): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) <= $max) {
            return $img;
        }
        $scale = $max / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $out;
    }
}
