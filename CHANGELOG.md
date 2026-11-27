# Changelog

## 1.2.0 – 2026-11-27

Modified by jswebschmiede (https://github.com/jswebschmiede) on 2026-11-27.

- `srcset` of `<img>` tags is rewritten, candidate by candidate.
- Absolute URLs of the own domain, query strings (`?v=2`), fragments such as `#joomlaImage://…`, URL-encoded paths and Joomla in a subdirectory are handled. Query string and fragment are kept.
- **Changed:** excluded paths are folders relative to the Joomla root (e.g. `images/headers`) and also cover their subfolders. Before, any part of the URL matched. Check your entries after updating.
- New setting "Images without Lazy Loading" (default 2): the first images of the page stay eager; images with `fetchpriority` too.
- New setting "Max. Conversions per Request" (default 5): the remaining images are converted on the next page views. Only images that are actually decoded count.
- PNG images are saved as lossless WebP.
- README: limitations, and what happens to the `.webp` files when the plugin is uninstalled.

## 1.1.1 – 2026-11-27

Modified by jswebschmiede (https://github.com/jswebschmiede) on 2026-11-27.

- Fixed: the plugin settings were never saved, because the manifest lacked `<fields name="params">`. Quality, excluded paths and the lazy loading switch now take effect.
- Fixed: a broken or wrongly named image, or GD without WebP support, caused a fatal error on the page. The conversion now checks the real image type, catches GD errors and skips images over 25 megapixels.
- Fixed: the path was replaced even when the conversion failed, which led to broken images. It is now replaced only when a WebP exists that is smaller than the original.
- Fixed: the WebP is re-created when the original is newer. It is written to a temporary file first, so parallel requests never see half-written files.
- Fixed: `str_replace` replaced the path everywhere in the page (links, `data-src`, other paths ending the same way). Only the `src` attribute and `background-image` URLs are rewritten now, and `data-src` is no longer mistaken for `src`.
- Fixed: lazy loading changed `<img` inside `<script>`, `<template>` and `<noscript>`, which could break JavaScript.
- Fixed: paths with `../` could point outside the Joomla root. They are resolved and rejected now.
- Fixed: the plugin ran for every output format. It now runs only for HTML pages on the site.
- JPEG photos are rotated according to their EXIF orientation (3, 6, 8).
- PNG transparency is kept (alpha blending off while saving).

## 1.1.0 – 2026-11-27

Modified by jswebschmiede (https://github.com/jswebschmiede) on 2026-11-27, based on DC JWebP 1.0.0 by Design Cart.

- Moved the plugin to the Joomla 5/6 structure: namespace `DesignCart\Plugin\System\DcJwebp`, `services/provider.php`, event subscriber for `onAfterRender`. Minimum requirements: Joomla 5.0, PHP 8.1.
- Installer script that checks the requirements and removes files of the 1.0.0 layout on update.
- Language files without the language prefix, as Joomla 4+ expects; new German (de-DE) translation.
- Removed the unused `helper/converter.php`.
- The image conversion and lazy loading work as before. Under `strict_types`, a failing regular expression now keeps the original value instead of passing `null` on.
- Build and quality tooling: PHPCS (PSR-12), Rector, `composer production` builds the installable ZIP, GitHub Actions workflow creates releases from tags.
- Added the GPL-3.0 license text (`LICENSE.md`).

## 1.0.0 – 2025-11-17

Initial release by Design Cart.
