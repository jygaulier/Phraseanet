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
use Alchemy\Phrasea\Controller\Prod\ExportController;
use Alchemy\Phrasea\ControllerProvider\ControllerProviderTrait;
use Alchemy\Phrasea\Core\Event\Listener\OAuthListener;
use Alchemy\Phrasea\Core\LazyLocator;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\ControllerProviderInterface;
use Silex\Application;


class Export implements ControllerProviderInterface, ServiceProviderInterface
{
    use ControllerProviderTrait;

    public function register(Container $app)
    {
        $app['controller.prod.export'] = function (PhraseaApplication $app) {
            return (new ExportController($app))
                ->setDispatcher($app['dispatcher'])
                ->setFileSystemLocator(new LazyLocator($app, 'filesystem'))
                ->setDelivererLocator(new LazyLocator($app, 'notification.deliverer'))
            ;
        };
    }

    /**
     * {@inheritDoc}
     */
    public function connect(Application $app)
    {
        $controllers = $this->createCollection($app);
        $controllers->before(new OAuthListener(['exit_not_present' => false]));
        $this->getFirewall($app)->addMandatoryAuthentication($controllers);

        $controllers->post('/multi-export/', 'controller.prod.export:displayMultiExport')
            ->bind('export_multi_export');

        $controllers->post('/mail/', 'controller.prod.export:exportMail')
            ->bind('export_mail');

        $controllers->post('/ftp/', 'controller.prod.export:exportFtp')
            ->bind('export_ftp');

        $controllers->post('/ftp/test/', 'controller.prod.export:testFtpConnexion')
            ->bind('export_ftp_test');

        return $controllers;
    }
}
