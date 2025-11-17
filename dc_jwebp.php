<?php

/**
 * @package     DC JWebP
 * @subpackage  System Plugin
 * @author      Design Cart
 * @version     1.0.0
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;

class PlgSystemDc_jwebp extends CMSPlugin{
    protected $app;

    public function onAfterRender(){
        if ($this->app->isClient('administrator')) {
            return;
        }

        $body      = $this->app->getBody();
        $quality   = (int) $this->params->get('quality', 85);
        $exclude   = trim($this->params->get('exclude', ''));
        $lazyload  = (int) $this->params->get('lazyload', 1);
        $excludeList = array_filter(array_map('trim', explode("\n", $exclude)));

        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $body, $imgMatches);

        if (!empty($imgMatches[1])) {
            foreach ($imgMatches[1] as $src) {

                $lower = strtolower($src);

                if (str_ends_with($lower, '.webp')) {
                    continue;
                }

                foreach ($excludeList as $ex) {
                    if ($ex !== '' && str_contains($src, $ex)) {
                        continue 2;
                    }
                }

                $abs = JPATH_ROOT . '/' . ltrim($src, '/');

                if (!file_exists($abs)) {
                    continue;
                }

                $webpAbs = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $abs);
                $webpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $src);

                if (!file_exists($webpAbs)) {
                    $this->convertToWebp($abs, $webpAbs, $quality);
                }

                $body = str_replace($src, $webpUrl, $body);
            }
        }

        preg_match_all('/background-image:\s*url\(([^)]+)\)/i', $body, $bgMatches);

        if (!empty($bgMatches[1])) {
            foreach ($bgMatches[1] as $rawUrl) {

                $src = trim($rawUrl, "\"'");
                $lower = strtolower($src);

                if (str_ends_with($lower, '.webp')) {
                    continue;
                }

                foreach ($excludeList as $ex) {
                    if ($ex !== '' && str_contains($src, $ex)) {
                        continue 2;
                    }
                }

                $abs = JPATH_ROOT . '/' . ltrim($src, '/');

                if (!file_exists($abs)) {
                    continue;
                }

                $webpAbs = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $abs);
                $webpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $src);

                if (!file_exists($webpAbs)) {
                    $this->convertToWebp($abs, $webpAbs, $quality);
                }

                $body = str_replace($src, $webpUrl, $body);
            }
        }

        if ($lazyload) {
            $body = preg_replace(
                '/<img(?![^>]*loading=)/i',
                '<img loading="lazy"',
                $body
            );
        }

        $this->app->setBody($body);
    }


    private function convertToWebp($src, $dest, $quality = 85){
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

        imagewebp($img, $dest, $quality);
        imagedestroy($img);

        return true;
    }
}
