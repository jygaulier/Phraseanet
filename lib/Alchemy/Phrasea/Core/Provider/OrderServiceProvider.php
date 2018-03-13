<?php

/*
 * This file is part of alchemy/pipeline-component.
 *
 * (c) Alchemy <info@alchemy.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Core\Provider;

use Alchemy\Phrasea\Application as PhraseanetApplication;
use Alchemy\Phrasea\Core\Event\Subscriber\OrderSubscriber;
use Alchemy\Phrasea\Core\LazyLocator;
use Alchemy\Phrasea\Model\Entities\Order;
use Alchemy\Phrasea\Order\ValidationNotifier\MailNotifier;
use Alchemy\Phrasea\Order\ValidationNotifier\WebhookNotifier;
use Alchemy\Phrasea\Order\ValidationNotifierRegistry;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Api\EventListenerProviderInterface;
use Silex\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


class OrderServiceProvider implements ServiceProviderInterface, EventListenerProviderInterface
{

    /**
     * Registers services on the given app.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     */
    public function register(Container $app)
    {
        $app['events.order_subscriber'] = function (PhraseanetApplication $app) {
            $notifierRegistry = new ValidationNotifierRegistry();

            $notifierRegistry->registerNotifier(Order::NOTIFY_MAIL, new MailNotifier($app));
            $notifierRegistry->registerNotifier(Order::NOTIFY_WEBHOOK, new WebhookNotifier(
                new LazyLocator($app, 'manipulator.webhook-event')
            ));

            return new OrderSubscriber($app, $notifierRegistry);
        };
    }

    public function subscribe(Container $app, EventDispatcherInterface $dispatcher)
    {
        $dispatcher->addSubscriber($app['events.order_subscriber']);
    }
}
