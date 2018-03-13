<?php

namespace Alchemy\Phrasea\Webhook\Processor;

use Alchemy\Phrasea\Application;
use Pimple\Container;

class OrderNotificationProcessorFactory implements ProcessorFactory
{
    /**
     * @var Container
     */
    private $application;

    public function __construct(Container $application)
    {
        $this->application = $application;
    }

    /**
     * @return ProcessorInterface
     */
    public function createProcessor()
    {
        return new OrderNotificationProcessor(
            $this->application['repo.orders'],
            $this->application['repo.users']
        );
    }
}
