<?php

namespace Alchemy\Tests\Phrasea\Controller\Prod;

use Alchemy\Phrasea\SearchEngine\SearchEngineOptions;
use Prophecy\Argument;

/**
 * @group functional
 * @group legacy
 * @group authenticated
 * @group web
 */
class QueryTest extends \PhraseanetAuthenticatedWebTestCase
{
    public function testQuery()
    {
        $app = self::getApplication();

        $route = '/prod/query/';

        $userManipulator = $this->getMockBuilder('Alchemy\Phrasea\Model\Manipulator\UserManipulator')
            ->setConstructorArgs([
                $app['model.user-manager'],
                $app['auth.password-encoder'],
                $app['geonames.connector'],
                $app['repo.users'],
                $app['random.low'],
                $app['dispatcher'],
            ])
            ->setMethods(['logQuery'])
            ->getMock();

        $app->offsetUnset('manipulator.user');
        $app['manipulator.user'] = $userManipulator;

        $userManipulator->expects($this->once())->method('logQuery');

        $client = $this->getClient();
        $client->request('POST', $route);

        $response = $client->getResponse();
        $this->assertEquals('application/json', $response->headers->get('Content-type'));
        $data = json_decode($response->getContent(), true);
        $this->assertInternalType('array', $data);
    }

    public function testQueryAnswerTrain()
    {
        $app = $this->mockElasticsearchResult(self::$DI['record_2']);
        $this->authenticate($app);

        $options = new SearchEngineOptions();
        $searchableBasesIds = $app->getAclForUser($app->getAuthenticatedUser())->getSearchableBasesIds();
        $options->onBasesIds($searchableBasesIds);
        $serializedOptions = $options->serialize();

        $response = $this->request('POST', '/prod/query/answer-train/', [
            'options_serial' => $serializedOptions,
            'pos'            => 0,
            'query'          => ''
            ]);
        $this->assertTrue($response->isOk());
        $datas = (array) json_decode($response->getContent());
        $this->assertArrayHasKey('current', $datas);
        unset($response, $datas);
    }
}
