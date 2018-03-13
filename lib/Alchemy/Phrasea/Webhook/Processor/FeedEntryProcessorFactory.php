<?php

namespace Alchemy\Phrasea\Webhook\Processor;

use Alchemy\Phrasea\Application as PhraseanetApplication;


class FeedEntryProcessorFactory implements ProcessorFactory
{
    /**
     * @var PhraseanetApplication
     */
    private $app;

    public function __construct(PhraseanetApplication $application)
    {
        $this->app = $application;
    }

    public function createProcessor()
    {
        return new FeedEntryProcessor(
            $this->app,
            $this->app['repo.feed-entries'],
            $this->app['phraseanet.user-query']
        );
    }
}
