<?php

namespace App\Support;

class ReportImage
{
    public static function pdfPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true) || ! is_file($path)) {
            return $path;
        }

        $target = $path.'.pdf.png';
        if (is_file($target)) {
            return $target;
        }

        $blob = file_get_contents($path);
        $image = is_string($blob) ? @imagecreatefromstring($blob) : false;
        if ($image !== false) {
            imagepng($image, $target);
            imagedestroy($image);

            return $target;
        }

        if (! class_exists(\Imagick::class)) {
            return $path;
        }

        try {
            $imagick = new \Imagick($path);
            $imagick->setImageFormat('png');
            $imagick->writeImage($target);
            $imagick->clear();

            return is_file($target) ? $target : $path;
        } catch (\Throwable) {
            return $path;
        }
    }
}
