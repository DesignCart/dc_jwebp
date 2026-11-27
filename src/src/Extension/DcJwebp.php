<?php

/**
 * @package     DC JWebP
 * @subpackage  System Plugin
 *
 * @version     1.2.0
 * @copyright   Design Cart
 * @license     GNU General Public License version 3; see LICENSE.md
 * @author      Design Cart
 *
 * Modified 2026-11-27 by jswebschmiede: moved to the Joomla 5/6 plugin
 * structure (namespace, service provider, event subscriber).
 * Modified 2026-11-27 by jswebschmiede: safe conversion, targeted rewriting,
 * path checks and script-safe lazy loading.
 * Modified 2026-11-27 by jswebschmiede: srcset, absolute URLs, query strings,
 * subdirectory installs, exclusion by path, lazy loading skip, conversion
 * limit per request, lossless PNG.
 */

declare(strict_types=1);

namespace DesignCart\Plugin\System\DcJwebp\Extension;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
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
     * WebP quality for JPEG images (0-100).
     *
     * @var    int
     * @since  1.1.1
     */
    private int $quality = 85;

    /**
     * Folders relative to the Joomla root whose images are not converted.
     *
     * @var    list<string>
     * @since  1.1.1
     */
    private array $excludeList = [];

    /**
     * Maximum number of conversions in one request.
     *
     * @var    int
     * @since  1.2.0
     */
    private int $maxPerRequest = 5;

    /**
     * Conversions done in the current request.
     *
     * @var    int
     * @since  1.2.0
     */
    private int $conversions = 0;

    /**
     * Real path of the Joomla root, or null when it cannot be resolved.
     *
     * @var    string|null
     * @since  1.2.0
     */
    private ?string $root = null;

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

        $root = realpath(JPATH_ROOT);

        if ($root === false) {
            return;
        }

        $this->root          = $root;
        $this->quality       = max(0, min(100, (int) $this->params->get('quality', 85)));
        $this->maxPerRequest = max(1, (int) $this->params->get('max_per_request', 5));
        $this->conversions   = 0;
        $exclude             = (string) $this->params->get('exclude', '');
        $this->excludeList   = array_values(array_filter(array_map(
            static fn (string $line): string => trim(str_replace('\\', '/', trim($line)), '/'),
            explode("\n", $exclude)
        )));

        $body = $this->rewrite((string) $app->getBody());

        if ((int) $this->params->get('lazyload', 1)) {
            $body = $this->addLazyLoading($body, max(0, (int) $this->params->get('lazy_skip', 2)));
        }

        $app->setBody($body);
    }

    /**
     * Rewrites src and srcset of img tags and inline background-image URLs.
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
        // "\s" before src/srcset excludes data-src, data-srcset and similar attributes.
        $body = preg_replace_callback(
            '/(<img\b[^>]*?\ssrc=)(["\'])([^"\']+)\2/i',
            fn (array $m): string => $m[1] . $m[2] . $this->toWebpUrl($m[3]) . $m[2],
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '/(<img\b[^>]*?\ssrcset=)(["\'])([^"\']+)\2/i',
            fn (array $m): string => $m[1] . $m[2] . $this->rewriteSrcset($m[3]) . $m[2],
            $body
        ) ?? $body;

        return preg_replace_callback(
            '/(background-image:\s*url\(\s*)(["\']?)([^"\')\s]+)\2(\s*\))/i',
            fn (array $m): string => $m[1] . $m[2] . $this->toWebpUrl($m[3]) . $m[2] . $m[4],
            $body
        ) ?? $body;
    }

    /**
     * Rewrites every image candidate of a srcset value.
     *
     * @param   string  $srcset  srcset attribute value
     *
     * @return  string
     *
     * @since   1.2.0
     */
    private function rewriteSrcset(string $srcset): string
    {
        $candidates = array_map(
            function (string $candidate): string {
                $parts = preg_split('/\s+/', trim($candidate), 2);

                if ($parts === false || $parts[0] === '') {
                    return trim($candidate);
                }

                $parts[0] = $this->toWebpUrl($parts[0]);

                return implode(' ', $parts);
            },
            explode(',', $srcset)
        );

        return implode(', ', $candidates);
    }

    /**
     * Returns the WebP URL for an image URL, or the unchanged URL when no usable WebP exists.
     *
     * The WebP is (re)created when it is missing or older than the original (see convertToWebp()
     * for the limit per request). It is only used when it is smaller than the original.
     * Query string and fragment of the URL are kept.
     *
     * @param   string  $url  Image URL from the HTML
     *
     * @return  string
     *
     * @since   1.1.1
     */
    private function toWebpUrl(string $url): string
    {
        $abs = $this->resolveLocalPath($url);

        if ($abs === null || $this->isExcluded($abs)) {
            return $url;
        }

        $webp = preg_replace(self::IMAGE_EXTENSION, '.webp', $abs);

        if ($webp === null || $webp === $abs) {
            return $url;
        }

        if ((!is_file($webp) || filemtime($webp) < filemtime($abs)) && !$this->convertToWebp($abs, $webp)) {
            return $url;
        }

        clearstatcache(true, $webp);

        // A WebP that is not smaller than the original brings no benefit.
        if (!is_file($webp) || filesize($webp) >= filesize($abs)) {
            return $url;
        }

        // Change the extension in the path only; keep "?query" and "#fragment".
        $cut  = strcspn($url, '?#');
        $path = substr($url, 0, $cut);

        return (preg_replace(self::IMAGE_EXTENSION, '.webp', $path) ?? $path) . substr($url, $cut);
    }

    /**
     * Maps an image URL to an existing JPG/PNG file inside the Joomla root.
     *
     * Handles relative URLs, absolute URLs of the current host, query strings, fragments
     * (including #joomlaImage://), URL encoding and installs in a subdirectory. Paths that
     * resolve outside JPATH_ROOT (for example via "../") are rejected.
     *
     * @param   string  $url  Image URL from the HTML
     *
     * @return  string|null  Absolute file path, or null when the URL is not a local image
     *
     * @since   1.1.1
     */
    private function resolveLocalPath(string $url): ?string
    {
        if ($this->root === null || $url === '') {
            return null;
        }

        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($parts === false) {
            return null;
        }

        // Other schemes (data:, blob:, ...) and other hosts (CDN, external sites) are skipped.
        if (isset($parts['scheme']) && !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['host']) && strcasecmp($parts['host'], (string) Uri::getInstance()->getHost()) !== 0) {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '');

        if ($path === '') {
            return null;
        }

        // Joomla in a subdirectory: "/subdir/images/x.jpg" lives in JPATH_ROOT/images/x.jpg.
        $base = rtrim(Uri::root(true), '/');

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, \strlen($base));
        }

        $abs = realpath($this->root . '/' . ltrim($path, '/'));

        if ($abs === false || !str_starts_with($abs, $this->root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
            return null;
        }

        return preg_match(self::IMAGE_EXTENSION, $abs) === 1 ? $abs : null;
    }

    /**
     * Checks whether a file lies in one of the excluded folders.
     *
     * @param   string  $abs  Absolute file path inside the Joomla root
     *
     * @return  bool
     *
     * @since   1.2.0
     */
    private function isExcluded(string $abs): bool
    {
        $relative = str_replace('\\', '/', substr($abs, \strlen((string) $this->root) + 1));

        foreach ($this->excludeList as $folder) {
            if ($relative === $folder || str_starts_with($relative, $folder . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes a WebP copy of a JPG or PNG file.
     *
     * The type comes from the file content, not the extension. JPEGs use the configured quality,
     * PNGs are saved lossless. The WebP is written to a temporary file and renamed, so no request
     * ever sees a partly written file. Any GD error returns false.
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

            if ($info === false || !\in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
                return false;
            }

            // Only decoding is expensive, so only images that get this far count towards the limit.
            // The remaining images are converted on the next requests.
            if ($this->conversions >= $this->maxPerRequest) {
                return false;
            }

            ++$this->conversions;

            $img = match ($info[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
                IMAGETYPE_PNG  => @imagecreatefrompng($src),
                default        => false,
            };

            if (!$img instanceof \GdImage) {
                return false;
            }

            $quality = $this->quality;

            if ($info[2] === IMAGETYPE_JPEG) {
                $img = $this->applyExifOrientation($img, $src);
            }

            if ($info[2] === IMAGETYPE_PNG) {
                imagepalettetotruecolor($img);
                imagealphablending($img, false);
                imagesavealpha($img, true);

                // Lossy WebP blurs and often enlarges graphics and logos.
                if (\defined('IMG_WEBP_LOSSLESS')) {
                    $quality = IMG_WEBP_LOSSLESS;
                }
            }

            if (!@imagewebp($img, $tmp, $quality) || !is_file($tmp) || filesize($tmp) === 0) {
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
     * The first $skip images of the page (usually logo and hero image) and images with
     * fetchpriority stay eager. Content of script, template and noscript elements stays
     * untouched, so HTML inside JavaScript strings is never changed. When the split fails
     * (PCRE limits), the body is returned unchanged.
     *
     * @param   string  $body  Rendered HTML
     * @param   int     $skip  Number of leading images that are not lazy loaded
     *
     * @return  string
     *
     * @since   1.1.1
     */
    private function addLazyLoading(string $body, int $skip): string
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

        $count = 0;

        foreach ($parts as $index => $part) {
            // Odd indexes are the captured script/template/noscript blocks.
            if ($index % 2 === 1) {
                continue;
            }

            $parts[$index] = preg_replace_callback(
                '/<img\b[^>]*>/i',
                static function (array $m) use (&$count, $skip): string {
                    ++$count;

                    if ($count <= $skip || preg_match('/\s(loading|fetchpriority)=/i', $m[0]) === 1) {
                        return $m[0];
                    }

                    return '<img loading="lazy"' . substr($m[0], 4);
                },
                $part
            ) ?? $part;
        }

        return implode('', $parts);
    }
}
