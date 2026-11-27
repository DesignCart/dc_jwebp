<?php

/**
 * @package     DC JWebP
 * @subpackage  Build
 *
 * @copyright   Design Cart
 * @license     GNU General Public License version 3; see LICENSE.md
 * @author      Design Cart
 *
 * Added 2026-11-27 by jswebschmiede.
 */

declare(strict_types=1);

namespace DesignCart\DcJwebp\Build;

/**
 * Packs the plugin sources into an installable, versioned Joomla ZIP.
 *
 * @since  1.1.0
 */
final class Packager
{
    /**
     * Name prefix of the ZIP file.
     *
     * @since  1.1.0
     */
    private const ZIP_PREFIX = 'plg_system_dc_jwebp';

    /**
     * Manifest file inside the source directory.
     *
     * @since  1.1.0
     */
    private const MANIFEST = 'dc_jwebp.xml';

    /**
     * Files at the project root that ship at the ZIP root next to the manifest.
     *
     * @since  1.1.0
     */
    private const ROOT_FILES = ['LICENSE.md'];

    /**
     * File names that never go into the ZIP.
     *
     * @since  1.1.0
     */
    private const EXCLUDED_NAMES = ['.DS_Store', 'Thumbs.db'];

    /**
     * @param   string  $root  Absolute path of the project root
     *
     * @since   1.1.0
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Runs the packager with the given CLI arguments.
     *
     * Without arguments it writes dist/<prefix>-<version>.zip and prints its path.
     * With --check-tag=<tag> it only checks that the tag matches the manifest version.
     *
     * @param   list<string>  $arguments  CLI arguments without the script name
     *
     * @return  int  Process exit code
     *
     * @since   1.1.0
     */
    public function run(array $arguments): int
    {
        try {
            $version = $this->readVersion();

            foreach ($arguments as $argument) {
                if (str_starts_with($argument, '--check-tag=')) {
                    return $this->checkTag(substr($argument, strlen('--check-tag=')), $version);
                }

                throw new \RuntimeException('Unknown argument: ' . $argument);
            }

            fwrite(STDOUT, $this->build($version) . "\n");

            return 0;
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * Compares a git tag (v1.2.3 or 1.2.3) with the manifest version.
     *
     * @param   string  $tag      Git tag
     * @param   string  $version  Manifest version
     *
     * @return  int  Process exit code
     *
     * @since   1.1.0
     */
    private function checkTag(string $tag, string $version): int
    {
        $tagVersion = ltrim($tag, 'v');

        if ($tagVersion !== $version) {
            fwrite(STDERR, sprintf("Tag %s does not match the manifest version %s.\n", $tag, $version));

            return 1;
        }

        fwrite(STDOUT, sprintf("Tag %s matches the manifest version.\n", $tag));

        return 0;
    }

    /**
     * Writes the ZIP archive.
     *
     * @param   string  $version  Manifest version
     *
     * @return  string  Absolute path of the ZIP file
     *
     * @since   1.1.0
     */
    private function build(string $version): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to package the plugin.');
        }

        $distDir = $this->root . '/dist';
        $zipPath = $distDir . '/' . self::ZIP_PREFIX . '-' . $version . '.zip';

        if (!is_dir($distDir) && !mkdir($distDir, 0755, true) && !is_dir($distDir)) {
            throw new \RuntimeException('Unable to create the dist directory.');
        }

        if (is_file($zipPath) && !unlink($zipPath)) {
            throw new \RuntimeException('Unable to replace the existing ZIP file.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Unable to create the ZIP archive.');
        }

        $count = $this->addSourceFiles($zip);

        foreach (self::ROOT_FILES as $file) {
            if (!is_file($this->root . '/' . $file)) {
                throw new \RuntimeException($file . ' is missing.');
            }

            $zip->addFile($this->root . '/' . $file, $file);
            ++$count;
        }

        if (!$zip->close()) {
            throw new \RuntimeException('Unable to write the ZIP archive.');
        }

        if ($count === 0) {
            throw new \RuntimeException('The ZIP archive would be empty.');
        }

        return $zipPath;
    }

    /**
     * Adds every file below src/ with its path relative to src/.
     *
     * @param   \ZipArchive  $zip  Open ZIP archive
     *
     * @return  int  Number of added files
     *
     * @since   1.1.0
     */
    private function addSourceFiles(\ZipArchive $zip): int
    {
        $sourceDir = $this->sourceDir();
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $count = 0;

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();

            if (in_array($name, self::EXCLUDED_NAMES, true) || str_ends_with($name, '~')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
            $zip->addFile($file->getPathname(), $relative);
            ++$count;
        }

        return $count;
    }

    /**
     * Reads the version from the manifest.
     *
     * @return  string  Plugin version
     *
     * @since   1.1.0
     */
    private function readVersion(): string
    {
        $manifest = $this->sourceDir() . '/' . self::MANIFEST;
        $xml      = is_file($manifest) ? simplexml_load_file($manifest) : false;

        if ($xml === false) {
            throw new \RuntimeException('Unable to read ' . $manifest . '.');
        }

        $version = trim((string) $xml->version);

        if ($version === '') {
            throw new \RuntimeException('The manifest has no <version>.');
        }

        return $version;
    }

    /**
     * Returns the absolute path of the plugin sources.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    private function sourceDir(): string
    {
        return $this->root . '/src';
    }
}
