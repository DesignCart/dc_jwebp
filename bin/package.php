<?php

/**
 * Builds the installable plugin ZIP in dist/ or checks a release tag.
 *
 * Usage: php bin/package.php [--check-tag=<tag>]
 *
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

use DesignCart\DcJwebp\Build\Packager;

if (PHP_SAPI !== 'cli') {
    // STDERR is only defined in the CLI SAPI.
    echo "This script can only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/Packager.php';

exit((new Packager(dirname(__DIR__)))->run(array_slice($argv, 1)));
