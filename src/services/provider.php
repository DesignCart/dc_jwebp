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

use DesignCart\Plugin\System\DcJwebp\Extension\DcJwebp;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

\defined('_JEXEC') or die;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * The dispatcher is passed to the constructor because Joomla 5.0 requires it there.
     *
     * @param   Container  $container  The DI container
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new DcJwebp(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'dc_jwebp')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
