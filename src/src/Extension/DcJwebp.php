<?php

/**
 * @package     DC JWebP
 * @subpackage  System Plugin
 *
 * @version     1.3.1
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
 * Modified 2026-11-27 by jswebschmiede: conversion on upload in the media manager.
 * Modified 2026-11-27 by jswebschmiede: list the WebP copy in the media manager right after upload.
 */

declare(strict_types=1);

namespace DesignCart\Plugin\System\DcJwebp\Extension;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Application\AfterRenderEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\EventInterface;
use Joomla\Event\Priority;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Converts JPG/PNG images referenced in the site output to WebP and enables native lazy loading.
 * Optionally converts JPG/PNG files when they are uploaded in the media manager.
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
     * Upload mode: uploads in the media manager are not converted.
     *
     * @since  1.3.0
     */
    private const UPLOAD_OFF = 0;

    /**
     * Upload mode: a WebP copy is written next to the uploaded original.
     *
     * @since  1.3.0
     */
    private const UPLOAD_ADD = 1;

    /**
     * Upload mode: only the WebP version is stored, the original is never written.
     *
     * @since  1.3.0
     */
    private const UPLOAD_REPLACE = 2;

    /**
     * Reloads the media manager folder after an upload, so the WebP copy written in "Add WebP"
     * mode is listed right away. The media manager only adds the one file the server returns.
     *
     * It uses the Vuex store of the media manager app (store.subscribe, the getContents action
     * and the UPLOAD_SUCCESS, SELECT_DIRECTORY and SELECT_BROWSER_ITEM mutations of Joomla 5/6).
     * When the store cannot be found, nothing happens. getContents clears the selection, so in
     * a modal the uploaded file the media manager had selected is selected again.
     *
     * @since  1.3.1
     */
    private const MEDIA_MANAGER_RELOAD_SCRIPT = <<<'JS'
        (() => {
          const attach = (tries) => {
            const store = document.getElementById('com-media')?.__vue_app__?.config?.globalProperties?.$store;

            if (!store || typeof store.subscribe !== 'function' || typeof store.dispatch !== 'function') {
              if (tries > 0) {
                window.setTimeout(() => attach(tries - 1), 200);
              }

              return;
            }

            const inModal = window.location !== window.parent.location;
            let timer = 0;
            let reselect = null;

            store.subscribe((mutation, state) => {
              if (mutation.type === 'UPLOAD_SUCCESS') {
                reselect = inModal && mutation.payload ? mutation.payload.path : null;
                window.clearTimeout(timer);
                timer = window.setTimeout(() => store.dispatch('getContents', state.selectedDirectory), 300);

                return;
              }

              if (mutation.type === 'SELECT_DIRECTORY' && reselect !== null) {
                const file = state.files.find((item) => item.path === reselect);
                reselect = null;

                if (file) {
                  store.commit('SELECT_BROWSER_ITEM', file);
                }
              }
            });
          };

          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => attach(50));
          } else {
            attach(50);
          }
        })();
        JS;

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
            'onAfterRender'       => 'onAfterRender',
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            // Low priority: media-action plugins such as Resize change the upload first,
            // so the WebP is encoded from the final image.
            'onContentBeforeSave' => ['onContentBeforeSave', Priority::LOW],
            'onContentAfterSave'  => ['onContentAfterSave', Priority::LOW],
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
        $this->maxPerRequest = max(1, (int) $this->params->get('max_per_request', 5));
        $this->conversions   = 0;
        $this->loadSettings();

        $body = $this->rewrite((string) $app->getBody());

        if ((int) $this->params->get('lazyload', 1)) {
            $body = $this->addLazyLoading($body, max(0, (int) $this->params->get('lazy_skip', 2)));
        }

        $app->setBody($body);
    }

    /**
     * Stores only the WebP version of a JPG/PNG uploaded in the media manager (mode "WebP only").
     *
     * The uploaded data and file name are replaced before Joomla writes the file, so the original
     * never reaches the disk and the media manager lists and inserts the .webp file. The upload
     * stays unchanged when the conversion fails, the WebP is not smaller, a file with the WebP name
     * already exists, or the media manager settings do not allow WebP files. Only new files are
     * handled; files saved by the image editor keep their format.
     *
     * @param   EventInterface  $event  Before-save event
     *
     * @return  void
     *
     * @since   1.3.0
     */
    public function onContentBeforeSave(EventInterface $event): void
    {
        if (
            !$event instanceof BeforeSaveEvent
            || $event->getContext() !== 'com_media.file'
            || !$event->getIsNew()
            || $this->uploadMode() !== self::UPLOAD_REPLACE
        ) {
            return;
        }

        $item   = $event->getItem();
        $folder = $this->uploadFolder($item);
        $name   = (string) ($item->name ?? '');
        $data   = $item->data ?? null;

        if ($folder === null || !\is_string($data) || $data === '' || preg_match(self::IMAGE_EXTENSION, $name) !== 1) {
            return;
        }

        $this->loadSettings();

        if ($this->isExcluded($folder . DIRECTORY_SEPARATOR . $name) || !$this->webpUploadAllowed()) {
            return;
        }

        $webpName = preg_replace(self::IMAGE_EXTENSION, '.webp', $name);

        // Never overwrite an existing WebP file. An upload that replaces an existing original stays
        // as it is, so the old original is not left behind. The local adapter stores safe names.
        if (
            $webpName === null
            || is_file($folder . DIRECTORY_SEPARATOR . File::makeSafe($webpName))
            || is_file($folder . DIRECTORY_SEPARATOR . File::makeSafe($name))
        ) {
            return;
        }

        $webp = $this->encodeUpload($data, strtolower(pathinfo($name, PATHINFO_EXTENSION)));

        if ($webp === null || \strlen($webp) >= \strlen($data)) {
            return;
        }

        $item->data      = $webp;
        $item->name      = $webpName;
        $item->extension = 'webp';
    }

    /**
     * Writes a WebP copy next to a JPG/PNG saved in the media manager (mode "Add WebP").
     *
     * Runs for new uploads and for images saved by the image editor, so the copy is never older
     * than the original. Whether the copy is used is decided when a page is rendered.
     *
     * @param   EventInterface  $event  After-save event
     *
     * @return  void
     *
     * @since   1.3.0
     */
    public function onContentAfterSave(EventInterface $event): void
    {
        if (
            !$event instanceof AfterSaveEvent
            || $event->getContext() !== 'com_media.file'
            || $this->uploadMode() !== self::UPLOAD_ADD
        ) {
            return;
        }

        $item   = $event->getItem();
        $folder = $this->uploadFolder($item);
        $name   = (string) ($item->name ?? '');

        if ($folder === null || preg_match(self::IMAGE_EXTENSION, $name) !== 1) {
            return;
        }

        $src = $folder . DIRECTORY_SEPARATOR . $name;

        $this->loadSettings();

        if (!is_file($src) || $this->isExcluded($src)) {
            return;
        }

        $webp = preg_replace(self::IMAGE_EXTENSION, '.webp', $src);

        if ($webp !== null && $webp !== $src) {
            $this->convertToWebp($src, $webp, false);
        }
    }

    /**
     * Adds the reload script to media manager pages in "Add WebP" mode.
     *
     * @param   EventInterface  $event  Before-compile-head event
     *
     * @return  void
     *
     * @since   1.3.1
     */
    public function onBeforeCompileHead(EventInterface $event): void
    {
        $app = $this->getApplication();

        if (
            $this->uploadMode() !== self::UPLOAD_ADD
            || !$app instanceof CMSWebApplicationInterface
            || $app->getInput()->getCmd('option') !== 'com_media'
        ) {
            return;
        }

        $document = $app->getDocument();

        if ($document instanceof HtmlDocument) {
            $document->getWebAssetManager()->addInlineScript(self::MEDIA_MANAGER_RELOAD_SCRIPT);
        }
    }

    /**
     * Reads the settings shared by page rendering and upload conversion.
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function loadSettings(): void
    {
        $this->quality     = max(0, min(100, (int) $this->params->get('quality', 85)));
        $exclude           = (string) $this->params->get('exclude', '');
        $this->excludeList = array_values(array_filter(array_map(
            static fn (string $line): string => trim(str_replace('\\', '/', trim($line)), '/'),
            explode("\n", $exclude)
        )));
    }

    /**
     * Returns the configured upload mode.
     *
     * @return  int  One of the UPLOAD_* constants
     *
     * @since   1.3.0
     */
    private function uploadMode(): int
    {
        $mode = (int) $this->params->get('upload_mode', self::UPLOAD_OFF);

        return \in_array($mode, [self::UPLOAD_ADD, self::UPLOAD_REPLACE], true) ? $mode : self::UPLOAD_OFF;
    }

    /**
     * Returns the folder a media manager file is saved in.
     *
     * Only the local filesystem adapter is supported. Its adapter name is "local-<directory>",
     * where the directory is relative to JPATH_ROOT (see plg_filesystem_local).
     *
     * @param   object  $item  File object of the media manager save event
     *
     * @return  string|null  Absolute folder path inside the Joomla root, or null
     *
     * @since   1.3.0
     */
    private function uploadFolder(object $item): ?string
    {
        $adapter = (string) ($item->adapter ?? '');
        $root    = realpath(JPATH_ROOT);

        if ($root === false || !str_starts_with($adapter, 'local-')) {
            return null;
        }

        $folder = realpath($root . '/' . substr($adapter, 6) . '/' . ltrim((string) ($item->path ?? ''), '/'));

        if ($folder === false || !is_dir($folder) || !str_starts_with($folder . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $this->root = $root;

        return $folder;
    }

    /**
     * Checks whether the media manager settings accept WebP uploads.
     *
     * Mirrors the extension and MIME checks Joomla applies when the file is written, so a
     * replaced upload is never rejected because of the new format.
     *
     * @return  bool
     *
     * @since   1.3.0
     */
    private function webpUploadAllowed(): bool
    {
        $params = ComponentHelper::getParams('com_media');
        $list   = static fn (string $key, string $default): array => array_map(
            trim(...),
            explode(',', (string) $params->get($key, $default))
        );

        if (
            !\in_array('webp', $list('restrict_uploads_extensions', 'webp'), true)
            || !\in_array('webp', $list('image_extensions', 'webp'), true)
        ) {
            return false;
        }

        if ((int) $params->get('restrict_uploads', 1) && (int) $params->get('check_mime', 1)) {
            return \in_array('image/webp', $list('upload_mime', 'image/webp'), true);
        }

        return true;
    }

    /**
     * Converts uploaded JPG/PNG data to WebP data.
     *
     * The data is written to a temporary file, so the same conversion as for page rendering is
     * used (type check, pixel limit, EXIF rotation, lossless PNG). Temporary files are removed.
     *
     * @param   string  $data       Uploaded file content
     * @param   string  $extension  Lower-case extension of the uploaded file
     *
     * @return  string|null  WebP data, or null when the conversion failed
     *
     * @since   1.3.0
     */
    private function encodeUpload(string $data, string $extension): ?string
    {
        $tmpDir = (string) $this->getApplication()->get('tmp_path', '');

        if ($tmpDir === '' || !is_dir($tmpDir) || !is_writable($tmpDir)) {
            $tmpDir = sys_get_temp_dir();
        }

        $base = rtrim($tmpDir, '/\\') . DIRECTORY_SEPARATOR . 'dc_jwebp-' . bin2hex(random_bytes(8));
        $src  = $base . '.' . $extension;
        $dest = $base . '.webp';

        try {
            if (@file_put_contents($src, $data) === false || !$this->convertToWebp($src, $dest, false)) {
                return null;
            }

            $webp = @file_get_contents($dest);

            return \is_string($webp) && $webp !== '' ? $webp : null;
        } finally {
            foreach ([$src, $dest] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
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
     * @param   string  $src      Absolute path of the source image
     * @param   string  $dest     Absolute path of the WebP file
     * @param   bool    $limited  Whether the conversion counts towards the limit per request
     *
     * @return  bool  True when the WebP file was written
     *
     * @since   1.0.0
     */
    private function convertToWebp(string $src, string $dest, bool $limited = true): bool
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
            // The remaining images are converted on the next requests. Uploads are not limited.
            if ($limited) {
                if ($this->conversions >= $this->maxPerRequest) {
                    return false;
                }

                ++$this->conversions;
            }

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
