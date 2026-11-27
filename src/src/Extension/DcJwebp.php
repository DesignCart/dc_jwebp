<?php

/**
 * @package     DC JWebP
 * @subpackage  System Plugin
 *
 * @version     1.1.1
 * @copyright   Design Cart
 * @license     GNU General Public License version 3; see LICENSE.md
 * @author      Design Cart
 *
 * Modified 2026-11-27 by jswebschmiede: moved to the Joomla 5/6 plugin
 * structure (namespace, service provider, event subscriber).
 * Modified 2026-11-27 by jswebschmiede: safe conversion, targeted rewriting,
 * path checks and script-safe lazy loading.
 */

declare(strict_types=1);

namespace DesignCart\Plugin\System\DcJwebp\Extension;

use Joomla\CMS\Application\SiteApplication;
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
     * Matches the file extensions the plugin converts.
     *
     * @since  1.1.1
     */
    private const IMAGE_EXTENSION = '/\.(jpe?g|png)$/i';

    /**
     * Images above this pixel count are skipped, because GD keeps the bitmap uncompressed in memory.
     *
     * @since  1.1.1
     */
    private const MAX_PIXELS = 25_000_000;

    /**
     * WebP quality (0-100).
     *
     * @var    int
     * @since  1.1.1
     */
    private int $quality = 85;

    /**
     * URL fragments that exclude an image from conversion.
     *
     * @var    list<string>
     * @since  1.1.1
     */
    private array $excludeList = [];

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
     * Rewrites JPG/PNG references in rendered HTML pages of the site to WebP versions.
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

        // Site only; no backend, API or CLI. HTML only; no raw, json or feed output.
        if (!$app instanceof SiteApplication || $app->getDocument()?->getType() !== 'html') {
            return;
        }

        $this->quality     = max(0, min(100, (int) $this->params->get('quality', 85)));
        $exclude           = (string) $this->params->get('exclude', '');
        $this->excludeList = array_values(array_filter(array_map(trim(...), explode("\n", $exclude))));

        $body = $this->rewrite((string) $app->getBody());

        if ((int) $this->params->get('lazyload', 1)) {
            $body = $this->addLazyLoading($body);
        }

        $app->setBody($body);
    }

    /**
     * Rewrites the src attribute of img tags and inline background-image URLs.
     *
     * Only the matched attribute or url() value changes, never other occurrences of the same path.
     *
     * @param   string  $body  Rendered HTML
     *
     * @return  string
     *
     * @since   1.1.1
     */
    private function rewrite(string $body): string
    {
        // "\s" before src excludes data-src and similar attributes.
        $body = preg_replace_callback(
            '/(<img\b[^>]*?\ssrc=)(["\'])([^"\']+)\2/i',
            fn (array $m): string => $m[1] . $m[2] . $this->toWebpUrl($m[3]) . $m[2],
            $body
        ) ?? $body;

        return preg_replace_callback(
            '/(background-image:\s*url\(\s*)(["\']?)([^"\')\s]+)\2(\s*\))/i',
            fn (array $m): string => $m[1] . $m[2] . $this->toWebpUrl($m[3]) . $m[2] . $m[4],
            $body
        ) ?? $body;
    }

    /**
     * Returns the WebP URL for an image URL, or the unchanged URL when no usable WebP exists.
     *
     * The WebP is (re)created when it is missing or older than the original. It is only used
     * when it is smaller than the original.
     *
     * @param   string  $url  Image URL from the HTML
     *
     * @return  string
     *
     * @since   1.1.1
     */
    private function toWebpUrl(string $url): string
    {
        foreach ($this->excludeList as $exclude) {
            if (str_contains($url, $exclude)) {
                return $url;
            }
        }

        $abs = $this->resolveLocalPath($url);

        if ($abs === null) {
            return $url;
        }

        $webp = preg_replace(self::IMAGE_EXTENSION, '.webp', $abs);

        if ($webp === null || $webp === $abs) {
            return $url;
        }

        if (
            (!is_file($webp) || filemtime($webp) < filemtime($abs))
            && !$this->convertToWebp($abs, $webp)
        ) {
            return $url;
        }

        clearstatcache(true, $webp);

        // A WebP that is not smaller than the original brings no benefit.
        if (!is_file($webp) || filesize($webp) >= filesize($abs)) {
            return $url;
        }

        return preg_replace(self::IMAGE_EXTENSION, '.webp', $url) ?? $url;
    }

    /**
     * Maps a relative image URL to an existing JPG/PNG file inside the Joomla root.
     *
     * URLs with a scheme, host, query string or fragment are not handled. Paths that resolve
     * outside JPATH_ROOT (for example via "../") are rejected.
     *
     * @param   string  $url  Image URL from the HTML
     *
     * @return  string|null  Absolute file path, or null when the URL is not a local image
     *
     * @since   1.1.1
     */
    private function resolveLocalPath(string $url): ?string
    {
        if ($url === '' || preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $url) === 1 || strpbrk($url, '?#') !== false) {
            return null;
        }

        $root = realpath(JPATH_ROOT);

        if ($root === false) {
            return null;
        }

        $abs = realpath($root . '/' . ltrim($url, '/'));

        if ($abs === false || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
            return null;
        }

        return preg_match(self::IMAGE_EXTENSION, $abs) === 1 ? $abs : null;
    }

    /**
     * Writes a WebP copy of a JPG or PNG file.
     *
     * The type comes from the file content, not the extension. The WebP is written to a temporary
     * file and renamed, so no request ever sees a partly written file. Any GD error returns false.
     *
     * @param   string  $src   Absolute path of the source image
     * @param   string  $dest  Absolute path of the WebP file
     *
     * @return  bool  True when the WebP file was written
     *
     * @since   1.0.0
     */
    private function convertToWebp(string $src, string $dest): bool
    {
        if (!function_exists('imagewebp') || !is_writable(\dirname($dest))) {
            return false;
        }

        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';

        try {
            $info = @getimagesize($src);

            if ($info === false || $info[0] * $info[1] > self::MAX_PIXELS) {
                return false;
            }

            $img = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
                IMAGETYPE_PNG  => @imagecreatefrompng($src),
                default        => false,
            };

            if (!$img instanceof \GdImage) {
                return false;
            }

            if ($info[2] === IMAGETYPE_JPEG) {
                $img = $this->applyExifOrientation($img, $src);
            }

            if ($info[2] === IMAGETYPE_PNG) {
                imagepalettetotruecolor($img);
                imagealphablending($img, false);
                imagesavealpha($img, true);
            }

            if (!@imagewebp($img, $tmp, $this->quality) || !is_file($tmp) || filesize($tmp) === 0) {
                return false;
            }

            return @rename($tmp, $dest);
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Rotates a JPEG according to its EXIF orientation (values 3, 6 and 8).
     *
     * Mirrored orientations (2, 4, 5, 7) are left as they are.
     *
     * @param   \GdImage  $img  Decoded image
     * @param   string    $src  Absolute path of the JPEG file
     *
     * @return  \GdImage
     *
     * @since   1.1.1
     */
    private function applyExifOrientation(\GdImage $img, string $src): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }

        $exif  = @exif_read_data($src);
        $angle = match ((int) (\is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $img;
        }

        $rotated = imagerotate($img, $angle, 0);

        return $rotated instanceof \GdImage ? $rotated : $img;
    }

    /**
     * Adds loading="lazy" to img tags without a loading attribute.
     *
     * Content of script, template and noscript elements stays untouched, so HTML inside
     * JavaScript strings is never changed. When the split fails (PCRE limits), the body is
     * returned unchanged.
     *
     * @param   string  $body  Rendered HTML
     *
     * @return  string
     *
     * @since   1.1.1
     */
    private function addLazyLoading(string $body): string
    {
        $parts = preg_split(
            '#(<script\b.*?</script>|<template\b.*?</template>|<noscript\b.*?</noscript>)#is',
            $body,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return $body;
        }

        foreach ($parts as $index => $part) {
            // Odd indexes are the captured script/template/noscript blocks.
            if ($index % 2 === 1) {
                continue;
            }

            $parts[$index] = preg_replace(
                '/<img\b(?![^>]*\sloading=)/i',
                '<img loading="lazy"',
                $part
            ) ?? $part;
        }

        return implode('', $parts);
    }
}
