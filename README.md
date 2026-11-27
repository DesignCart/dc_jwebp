# DC JWebP

DC JWebP is a lightweight and fully automatic Joomla system plugin that optimizes images on your website by converting JPG and PNG files to WebP, replacing them in the final HTML output, and enabling native lazy loading. The plugin requires no configuration, introduces no external dependencies, and works instantly after activation — significantly improving PageSpeed scores and overall site performance.

## Features
- Automatic JPG/PNG → WebP conversion  
- Replaces `src` and `srcset` of `<img>` tags and inline CSS `background-image` URLs in the final HTML output; other occurrences of a path (links, `data-src`, scripts) stay untouched  
- Native lazy loading (`loading="lazy"`) — no JavaScript required; the first images of the page and images with `fetchpriority` load immediately, and content of `<script>`, `<template>` and `<noscript>` is never changed  
- Converts each image once and re-creates the WebP when the original changes; keeps the original when the WebP would not be smaller  
- Broken, unsupported or very large images (over 25 megapixels) are skipped instead of breaking the page; JPEG photos are rotated according to their EXIF orientation  
- Handles relative URLs and absolute URLs of the own domain, with query strings, URL encoding and Joomla installed in a subdirectory; paths that leave the Joomla root via `../` and images on other domains are ignored  
- PNG images are saved as lossless WebP, so logos and graphics stay sharp  
- Converts at most a set number of images per page view, so the first visit of a page with many images stays fast  
- Compatible with Joomla 5 and 6  
- Works with SP Page Builder, Helix Ultimate, YOOtheme, and custom templates  
- Zero impact on database or template files  
- No external libraries required (uses PHP GD)

## Configuration
The plugin includes a simple configuration panel with:
- WebP quality for JPEG images (0–100)  
- Excluded folders, one per line, relative to the Joomla root (e.g. `images/headers`)  
- Maximum conversions per page view (default 5)  
- Lazy loading on/off  
- Number of images at the top of the page that are not lazy loaded (default 2)  

## Limitations
- `<picture>`/`<source srcset>`, external CSS files and the shorthand `background: url(...)` are not rewritten.
- Browsers without WebP support get no fallback.
- Images reached through a symbolic link that points outside the Joomla root are skipped.
- GD drops ICC colour profiles; mirrored EXIF orientations (2, 4, 5, 7) are not corrected.
- Uninstalling the plugin leaves the generated `.webp` files next to the originals. The plugin cannot tell them apart from WebP files you uploaded yourself, so remove them manually if needed (for example `find images -name '*.webp'` and check the list before deleting).

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


