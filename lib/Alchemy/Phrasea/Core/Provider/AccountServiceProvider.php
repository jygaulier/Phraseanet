<?php

namespace Alchemy\Phrasea\Core\Provider;

use Alchemy\Phrasea\Account\AccountService;
use Alchemy\Phrasea\Application;
use Pimple\Container;
use Pimple\ServiceProviderInterface;


class AccountServiceProvider implements ServiceProviderInterface
{
    /**
     * Registers services on the given app.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     *
     * @param Container $app
     */
    public function register(Container $app)
    {
        $app['accounts.service'] = function () use ($app) {
            return new AccountService(
                $app['authentication'],
                $app['auth.password-encoder'],
                $app['dispatcher'],
                $app['orm.em'],
                $app['model.user-manager'],
                $app['manipulator.user'],
                $app['repo.users']
            );
        };
    }
}
