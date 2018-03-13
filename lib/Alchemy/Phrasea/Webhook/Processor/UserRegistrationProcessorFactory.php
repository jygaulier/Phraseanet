<?php

namespace Alchemy\Phrasea\Webhook\Processor;

use Alchemy\Phrasea\Application;
use Pimple\Container;

class UserRegistrationProcessorFactory implements ProcessorFactory
{
    /**
     * @var Container
     */
    private $app;

    /**
     * @param Container $application
     */
    public function __construct(Container $application)
    {
        $this->app = $application;
    }

    /**
     * @return UserRegistrationProcessor
     */
    public function createProcessor()
    {
        return new UserRegistrationProcessor($this->app['repo.users']);
    }
}
