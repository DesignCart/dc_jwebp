<?php

namespace DC\JWebp\Helper;

class Converter{
    public static function convert($src, $dest){
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));

        if ($ext === 'jpg' || $ext === 'jpeg') {
            $img = imagecreatefromjpeg($src);
        } elseif ($ext === 'png') {
            $img = imagecreatefrompng($src);
            imagepalettetotruecolor($img);
            imagealphablending($img, true);
            imagesavealpha($img, true);
        } else {
            return false;
        }

        imagewebp($img, $dest, 85);
        imagedestroy($img);
        return true;
    }
}
