<?php

namespace Vendor;

use Alchemy\Phrasea\Application as PhraseaApplication;
use Pimple\Container;
use Silex\Application;
use Alchemy\Phrasea\Plugin\PluginProviderInterface;

class PluginService implements PluginProviderInterface
{
    public function register(Container $app)
    {
        $app['plugin-test'] = 'hello world';
    }

    public static function create(Container $app)
    {
        return new static();
    }
}
