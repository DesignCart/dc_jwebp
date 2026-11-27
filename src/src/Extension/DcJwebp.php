<?php

/**
 * @package     DC JWebP
 * @subpackage  System Plugin
 *
 * @version     1.1.0
 * @copyright   Design Cart
 * @license     GNU General Public License version 3; see LICENSE.md
 * @author      Design Cart
 *
 * Modified 2026-11-27 by jswebschmiede: moved to the Joomla 5/6 plugin
 * structure (namespace, service provider, event subscriber).
 */

declare(strict_types=1);

namespace DesignCart\Plugin\System\DcJwebp\Extension;

use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Converts JPG/PNG images referenced in the site output to WebP and enables native lazy loading.
 *
 * @since  1.0.0
 */
final class DcJwebp extends CMSPlugin implements SubscriberInterface
{
    /**
     * Returns the events this plugin listens to.
     *
     * @return  array<string, string>
     *
     * @since   1.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRender' => 'onAfterRender',
        ];
    }

    /**
     * Replaces JPG/PNG references in the rendered site body with WebP versions.
     *
     * @param   AfterRenderEvent  $event  After-render event
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterRender(AfterRenderEvent $event): void
    {
        $app = $this->getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $body        = (string) $app->getBody();
        $quality     = (int) $this->params->get('quality', 85);
        $exclude     = trim((string) $this->params->get('exclude', ''));
        $lazyload    = (int) $this->params->get('lazyload', 1);
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

                $webpAbs = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $abs) ?? $abs;
                $webpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $src) ?? $src;

                if (!file_exists($webpAbs)) {
                    $this->convertToWebp($abs, $webpAbs, $quality);
                }

                $body = str_replace($src, $webpUrl, $body);
            }
        }

        preg_match_all('/background-image:\s*url\(([^)]+)\)/i', $body, $bgMatches);

        if (!empty($bgMatches[1])) {
            foreach ($bgMatches[1] as $rawUrl) {
                $src   = trim($rawUrl, "\"'");
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

                $webpAbs = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $abs) ?? $abs;
                $webpUrl = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $src) ?? $src;

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
            ) ?? $body;
        }

        $app->setBody($body);
    }

    /**
     * Writes a WebP copy of a JPG or PNG file.
     *
     * @param   string  $src      Absolute path of the source image
     * @param   string  $dest     Absolute path of the WebP file
     * @param   int     $quality  WebP quality (0-100)
     *
     * @return  bool  False when the source type is not supported
     *
     * @since   1.0.0
     */
    private function convertToWebp(string $src, string $dest, int $quality = 85): bool
    {
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
