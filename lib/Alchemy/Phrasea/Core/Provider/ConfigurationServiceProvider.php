<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Core\Provider;

use Alchemy\Phrasea\Application as PhraseanetApplication;
use Alchemy\Phrasea\Core\Configuration\AccessRestriction;
use Alchemy\Phrasea\Core\Configuration\Compiler;
use Alchemy\Phrasea\Core\Configuration\Configuration;
use Alchemy\Phrasea\Core\Configuration\DisplaySettingService;
use Alchemy\Phrasea\Core\Configuration\HostConfiguration;
use Alchemy\Phrasea\Core\Configuration\PropertyAccess;
use Alchemy\Phrasea\Core\Configuration\RegistryManipulator;
use Alchemy\Phrasea\Core\Configuration\StructureTemplate;
use Alchemy\Phrasea\Core\Event\Subscriber\ConfigurationLoaderSubscriber;
use Alchemy\Phrasea\Core\Event\Subscriber\TrustedProxySubscriber;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\EventListenerProviderInterface;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Yaml\Yaml;


class ConfigurationServiceProvider implements ServiceProviderInterface, EventListenerProviderInterface
{
    public function register(Container $app)
    {
        $app['phraseanet.configuration.yaml-parser'] = function (PhraseanetApplication $app) {
            return new Yaml();
        };

        $app['phraseanet.configuration.compiler'] = function (PhraseanetApplication $app) {
            return new Compiler();
        };

        $app['phraseanet.configuration.config-path'] = $app->factory(function (PhraseanetApplication $app) {
            return sprintf('%s/config/configuration.yml', $app['root.path']);
        });

        $app['phraseanet.configuration.config-compiled-path'] = $app->factory(function (PhraseanetApplication $app) {
            return sprintf('%s/config/configuration-compiled.php', $app['root.path']);
        });

        $app['configuration.store'] = function (PhraseanetApplication $app) {
            return new HostConfiguration(new Configuration(
                $app['phraseanet.configuration.yaml-parser'],
                $app['phraseanet.configuration.compiler'],
                $app['phraseanet.configuration.config-path'],
                $app['phraseanet.configuration.config-compiled-path'],
                $app['debug']
            ));
        };

        $app['registry.manipulator'] = function (PhraseanetApplication $app) {
            return new RegistryManipulator($app['form.factory'], $app['translator'], $app['locales.available']);
        };

        $app['conf'] = function (PhraseanetApplication $app) {
            return new PropertyAccess($app['configuration.store']);
        };

        // Maintaining BC until 3.10
        $app['phraseanet.configuration'] = function (PhraseanetApplication $app) {
            return $app['configuration.store'];
        };

        $app['settings'] = function (PhraseanetApplication $app) {
            return new DisplaySettingService($app['conf']);
        };

        $app['conf.restrictions'] = function (PhraseanetApplication $app) {
            return new AccessRestriction($app['conf'], $app->getApplicationBox(), $app['monolog']);
        };

        $app['phraseanet.structure-template'] = function (PhraseanetApplication $app) {
            return new StructureTemplate($app['root.path']);
        };
    }

    public function subscribe(Container $app, EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addSubscriber(new ConfigurationLoaderSubscriber($app['configuration.store']));
        $dispatcher->addSubscriber(new TrustedProxySubscriber($app['configuration.store']));
    }
}
