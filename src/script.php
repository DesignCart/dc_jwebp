<?php

/**
 * @package     DC JWebP
 * @subpackage  System Plugin
 *
 * @version     1.1.0
 * @copyright   Design Cart
 * @license     GNU General Public License version 3; see LICENSE.md
 * @author      Design Cart
 *
 * Added 2026-11-27 by jswebschmiede for the Joomla 5/6 plugin structure.
 */

declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

\defined('_JEXEC') or die;

return new class () implements InstallerScriptInterface {
    /**
     * Minimum Joomla version required by this plugin.
     *
     * @var    string
     * @since  1.1.0
     */
    private string $minimumJoomla = '5.0.0';

    /**
     * Minimum PHP version required by this plugin.
     *
     * @var    string
     * @since  1.1.0
     */
    private string $minimumPhp = '8.1.0';

    /**
     * Called after installing the plugin.
     *
     * @param   InstallerAdapter  $adapter  Installer adapter
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Called after updating the plugin.
     *
     * @param   InstallerAdapter  $adapter  Installer adapter
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Called after uninstalling the plugin.
     *
     * @param   InstallerAdapter  $adapter  Installer adapter
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    /**
     * Called before install, update or uninstall.
     *
     * @param   string            $type     Installation type
     * @param   InstallerAdapter  $adapter  Installer adapter
     *
     * @return  bool  False when the environment does not meet the requirements
     *
     * @since   1.1.0
     */
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, $this->minimumPhp, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf(Text::_('JLIB_INSTALLER_MINIMUM_PHP'), $this->minimumPhp),
                'error'
            );

            return false;
        }

        if (version_compare(JVERSION, $this->minimumJoomla, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf(Text::_('JLIB_INSTALLER_MINIMUM_JOOMLA'), $this->minimumJoomla),
                'error'
            );

            return false;
        }

        return true;
    }

    /**
     * Called after install, update or uninstall.
     *
     * @param   string            $type     Installation type
     * @param   InstallerAdapter  $adapter  Installer adapter
     *
     * @return  bool
     *
     * @since   1.1.0
     */
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'update') {
            $this->removeLegacyFiles();
        }

        return true;
    }

    /**
     * Removes files of the 1.0.0 layout that the new structure no longer uses.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function removeLegacyFiles(): void
    {
        $pluginPath = JPATH_PLUGINS . '/system/dc_jwebp';
        $files      = [$pluginPath . '/dc_jwebp.php'];

        foreach (['en-GB', 'pl-PL'] as $tag) {
            foreach (['ini', 'sys.ini'] as $suffix) {
                $files[] = JPATH_ADMINISTRATOR . '/language/' . $tag . '/' . $tag . '.plg_system_dc_jwebp.' . $suffix;
            }
        }

        try {
            foreach ($files as $file) {
                if (is_file($file)) {
                    File::delete($file);
                }
            }

            if (is_dir($pluginPath . '/helper')) {
                Folder::delete($pluginPath . '/helper');
            }
        } catch (\Throwable $exception) {
            // Leftover files are harmless; report them instead of failing the update.
            Factory::getApplication()->enqueueMessage($exception->getMessage(), 'warning');
        }
    }
};
