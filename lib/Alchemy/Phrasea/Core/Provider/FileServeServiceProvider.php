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
use Alchemy\Phrasea\Core\Event\Subscriber\XSendFileSubscriber;
use Alchemy\Phrasea\Http\H264PseudoStreaming\H264Factory;
use Alchemy\Phrasea\Http\ServeFileResponseFactory;
use Alchemy\Phrasea\Http\StaticFile\StaticMode;
use Alchemy\Phrasea\Http\XSendFile\XSendFileFactory;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\EventListenerProviderInterface;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class FileServeServiceProvider implements ServiceProviderInterface, EventListenerProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function register(Container $app)
    {
        $app['phraseanet.xsendfile-factory'] = function (PhraseanetApplication $app) {
            return XSendFileFactory::create($app);
        };

        $app['phraseanet.h264-factory'] = function (PhraseanetApplication $app) {
            return H264Factory::create($app);
        };

        $app['phraseanet.h264'] = function (PhraseanetApplication $app) {
            return $app['phraseanet.h264-factory']->createMode(false);
        };

        $app['phraseanet.static-file'] = function (PhraseanetApplication $app) {
            return new StaticMode($app['phraseanet.thumb-symlinker']);
        };

        $app['phraseanet.file-serve'] = function (PhraseanetApplication $app) {
            return new ServeFileResponseFactory($app['unicode']);
        };
    }

    public function subscribe(Container $app, EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addSubscriber(new XSendFileSubscriber($app));
    }
}
