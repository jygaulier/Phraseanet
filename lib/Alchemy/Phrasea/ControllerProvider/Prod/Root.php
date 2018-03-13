<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\ControllerProvider\Prod;

use Alchemy\Phrasea\Application as PhraseaApplication;
use Alchemy\Phrasea\Controller\Prod\RootController;
use Alchemy\Phrasea\ControllerProvider\ControllerProviderTrait;
use Alchemy\Phrasea\Helper;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\ControllerProviderInterface;
use Silex\Application;


class Root implements ControllerProviderInterface, ServiceProviderInterface
{
    use ControllerProviderTrait;

    public function register(Container $app)
    {
        $app['controller.prod'] = function (PhraseaApplication $app) {
            return (new RootController($app))
                ->setFirewall($app['firewall'])
            ;
        };
    }

    public function connect(Application $app)
    {
        $controllers = $this->createCollection($app);

        $controllers->before('controller.prod:assertAuthenticated');

        $controllers->get('/', 'controller.prod:indexAction')
            ->bind('prod');

        return $controllers;
    }
}
