# Changelog

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
