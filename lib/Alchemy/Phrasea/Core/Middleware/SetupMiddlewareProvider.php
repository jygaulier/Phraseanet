<?php

namespace Alchemy\Phrasea\Core\Middleware;

use Alchemy\Phrasea\Application as PhraseanetApplication;
use Assert\Assertion;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
// use Alchemy\PhraseanetServiceProviderInterface;


class SetupMiddlewareProvider implements ServiceProviderInterface
{
    /**
     * Registers services on the given app.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     * @param Container $app
     */
    public function register(Container $app)
    {
        Assertion::isInstanceOf($app, PhraseanetApplication::class);

        /** @var PhraseanetApplication $app */
        $app['setup.validate-config'] = $app->protect(function (Request $request) use ($app) {
            if (0 === strpos($request->getPathInfo(), '/setup')) {
                if (!$app['phraseanet.configuration-tester']->isInstalled()) {
                    if (!$app['phraseanet.configuration-tester']->isBlank()) {
                        if ('setup_upgrade_instructions' !== $app['request_stack']->getCurrentRequest()->attributes->get('_route')) {
                            return $app->redirectPath('setup_upgrade_instructions');
                        }
                    }
                }
                elseif (!$app['phraseanet.configuration-tester']->isBlank()) {
                    return $app->redirectPath('homepage');
                }
            }
            else {
                if (false === strpos($request->getPathInfo(), '/include/minify')) {
                    $app['firewall']->requireSetup();
                }
            }
        });
    }
}
