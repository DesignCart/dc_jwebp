<?php

/**
 * @package     DC JWebP
 * @subpackage  Build
 *
 * @copyright   Design Cart
 * @license     GNU General Public License version 3; see LICENSE.md
 * @author      Design Cart
 *
 * Added 2026-11-27 by jswebschmiede, based on the rector.php of template_boilerplate.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/bin',
    ])
    ->withSkip([
        __DIR__ . '/vendor',
        '*/vendor/*',
        '*/node_modules/*',
        ChangeSwitchToMatchRector::class,
        ArrayToFirstClassCallableRector::class,
    ])
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        deadCode: false,
        codeQuality: false,
        codingStyle: false,
        typeDeclarations: false,
        privatization: false,
        naming: false,
        instanceOf: false,
        earlyReturn: false,
    );
