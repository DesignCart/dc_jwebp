# DC JWebP

DC JWebP is a lightweight and fully automatic Joomla system plugin that optimizes images on your website by converting JPG and PNG files to WebP, replacing them in the final HTML output, and enabling native lazy loading. The plugin requires no configuration, introduces no external dependencies, and works instantly after activation — significantly improving PageSpeed scores and overall site performance.

## Features
- Automatic JPG/PNG → WebP conversion  
- Replaces the `src` of `<img>` tags and inline CSS `background-image` URLs in the final HTML output; other occurrences of a path (links, `data-src`, scripts) stay untouched  
- Native lazy loading (`loading="lazy"`) — no JavaScript required; content of `<script>`, `<template>` and `<noscript>` is never changed  
- Converts each image once and re-creates the WebP when the original changes; keeps the original when the WebP would not be smaller  
- Broken, unsupported or very large images (over 25 megapixels) are skipped instead of breaking the page; JPEG photos are rotated according to their EXIF orientation  
- Handles relative image URLs of files inside the Joomla root only (paths that leave the root via `../` are ignored)  
- Compatible with Joomla 5 and 6  
- Works with SP Page Builder, Helix Ultimate, YOOtheme, and custom templates  
- Zero impact on database or template files  
- No external libraries required (uses PHP GD)

## Configuration
The plugin includes a simple configuration panel with:
- WebP quality (0–100)  
- Excluded folders  
- Lazy loading on/off  

## Installation
1. Download the ZIP package.  
2. Install it via **Joomla → Extensions → Install**.  
3. Enable the plugin under **System → Plugins → System – DC JWebP**.  
4. (Optional) Adjust quality or excluded paths.

Upgrading from 1.0.0 works in place; the installer removes the files of the old layout.

## Compatibility
- Joomla 5.x, 6.x  
- PHP 8.1 or newer (Joomla 6 requires PHP 8.3)  
- PHP GD extension with WebP support  
- SP Page Builder  
- Helix Ultimate  
- YOOtheme  
- Any Bootstrap-based or custom template

## Languages
English (en-GB), German (de-DE), Polish (pl-PL)

## Development
The installable plugin lives in `src/`. PHP tooling runs through Composer:

```
composer install                # Rector and PHP_CodeSniffer (dev only)
composer php:lint               # PHPCS (PSR-12)
composer php:rector             # Rector dry-run (PHP 8.1 set)
composer php:rector:fix:lint    # apply Rector, then phpcbf and PHPCS
```

## Building
```
composer production
```
Runs Rector (dry-run) and PHPCS and stops on any finding. Then it packs `src/` and `LICENSE.md` into `dist/plg_system_dc_jwebp-<version>.zip`, ready for **Extensions → Install**.

## Releasing
1. Raise `<version>` in `src/dc_jwebp.xml` and add an entry to `CHANGELOG.md`.
2. Commit, then tag and push: `git tag v1.2.0 && git push origin v1.2.0`.
3. The GitHub Actions workflow checks that the tag matches the manifest version, runs the same checks, and creates the GitHub release with the ZIP attached.

In a fork, the workflow titles the release `vX.Y.Z (fork build)` and puts a notice in front of the release notes. The notice links to the original repository and to `CHANGELOG.md`. In the original repository, no notice is added.

## License
GNU General Public License version 3, see [LICENSE.md](LICENSE.md). Changes to the original release are listed in [CHANGELOG.md](CHANGELOG.md).

## Author
<p>Design Cart – https://www.designcart.pl</p>
<p>👨‍💻 author: <a href="https://www.designcart.pl/pawel-nosko.html">Paweł Nosko</a></p>
<p>Project page: <a href="https://www.designcart.pl/laboratorium/264-jak-zoptymalizowac-obrazy-na-stronie-internetowej-joomla-gotowe-narzedzie.html">DC JWEBP</a></p>


