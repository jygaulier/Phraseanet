<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2015 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Controller\Api;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Core\Event\Listener\OAuthListener;
use Silex\Application as SilexApplication;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class V1 implements ControllerProviderInterface, ServiceProviderInterface
{
    public function register(SilexApplication $app)
    {
        $app['oauth2_server'] = $app->share(function (Application $app) {
            return new \API_OAuth2_Adapter($app);
        });

        /**
         * @var \API_OAuth2_Token|null
         */
        $app['token'] = $app->share(function (Application $app) {
            /** @var \API_OAuth2_Adapter $oauth2 */
            $oauth2 = $app['oauth2_server'];

            $token = $oauth2->getToken();

            return $token ? \API_OAuth2_Token::load_by_oauth_token($app, $token) : null;
        });

        /**
         * Api Service
         *
         * @return \API_V1_adapter
         */
        $app['api'] = function () use ($app) {
            return new \API_V1_adapter($app);
        };
    }

    public function boot(SilexApplication $app)
    {
        // Nothing to do
    }

    public function connect(SilexApplication $app)
    {
        $controllers = $app['controllers_factory'];

        $controllers->before(new OAuthListener());

        $requirePasswordGrant = function () use ($app) {
            /** @var \API_OAuth2_Token $oauthToken */
            $oauthToken = $app['token'];

            if (! $oauthToken) {
                throw new AccessDeniedHttpException('Invalid application.');
            }

            $oauthApplication = $oauthToken->get_account()->get_application();

            if ($oauthApplication->get_creator()->get_id() !== $oauthToken->get_account()->get_user()->get_id()) {
                throw new AccessDeniedHttpException('Invalid token.');
            }

            if (! $oauthApplication->is_password_granted()) {
                throw new AccessDeniedHttpException('Insufficient privileges.');
            }
        };

        $requireUserManagementRight = function () use ($app) {
            $app['firewall']
                ->requireAccessToModule('admin')
                ->requireRight('manageusers');
        };

        /**
         * OAuth log process
         *
         * Parse the requested route to fetch
         * - the ressource (databox, basket, record etc ..)
         * - general action (list, add, search)
         * - the action (setstatus, setname etc..)
         * - the aspect (collections, related, content etc..)
         *
         * @return array
         */
        $parseRoute = function ($route, Response $response) {
            $ressource = $general = $aspect = $action = null;
            $exploded_route = explode('/', \p4string::delFirstSlash(\p4string::delEndSlash($route)));
            if (sizeof($exploded_route) > 0 && $response->isOk()) {
                $ressource = $exploded_route[0];

                if (sizeof($exploded_route) == 2 && (int) $exploded_route[1] == 0) {
                    $general = $exploded_route[1];
                } else {
                    switch ($ressource) {
                        case \API_V1_Log::DATABOXES_RESSOURCE :
                            if ((int) $exploded_route[1] > 0 && sizeof($exploded_route) == 3)
                                $aspect = $exploded_route[2];
                            break;
                        case \API_V1_Log::RECORDS_RESSOURCE :
                            if ((int) $exploded_route[1] > 0 && sizeof($exploded_route) == 4) {
                                if (!isset($exploded_route[3]))
                                    $aspect = "record";
                                elseif (preg_match("/^set/", $exploded_route[3]))
                                    $action = $exploded_route[3];
                                else
                                    $aspect = $exploded_route[3];
                            }
                            break;
                        case \API_V1_Log::BASKETS_RESSOURCE :
                            if ((int) $exploded_route[1] > 0 && sizeof($exploded_route) == 3) {
                                if (preg_match("/^set/", $exploded_route[2]) || preg_match("/^delete/", $exploded_route[2]))
                                    $action = $exploded_route[2];
                                else
                                    $aspect = $exploded_route[2];
                            }
                            break;
                        case \API_V1_Log::FEEDS_RESSOURCE :
                            if ((int) $exploded_route[1] > 0 && sizeof($exploded_route) == 3)
                                $aspect = $exploded_route[2];
                            break;
                    }
                }
            }

            return array('ressource' => $ressource, 'general'   => $general, 'aspect'    => $aspect, 'action'    => $action);
        };

        /**
         * Log occurs in after filter
         */
        $controllers->after(function (Request $request, Response $response) use ($app, $parseRoute) {
            if (false !== stripos($request->headers->get('user-agent', ''), 'Phraseanet SDK Player')) {
                return;
            }

            $account = $app['token']->get_account();
            $pathInfo = $request->getPathInfo();
            $route = $parseRoute($pathInfo, $response);

            \API_V1_Log::create(
                $app,
                $account,
                $request->getMethod() . " " . $pathInfo,
                $response->getStatusCode(),
                $response->headers->get('content-type'),
                $route['ressource'],
                $route['general'],
                $route['aspect'],
                $route['action']
            );
        });

        /**
         * Method Not Allowed Closure
         */
        $bad_request_exception = function () {
            throw new \API_V1_exception_badrequest();
        };

        /**
         * Check whether the current user is Admin or not
         */
        $mustBeAdmin = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            if (!$user->ACL()->is_admin()) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Check whether the current user has access to databox
         */
        $hasAccessToDatabox = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            $databox = $app['phraseanet.appbox']->get_databox($request->attributes->get('databox_id'));
            if (!$user->ACL()->has_access_to_sbas($databox->get_sbas_id())) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Check whether the current user has access to the record
         */
        $hasAccessToRecord = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            $record = $app['phraseanet.appbox']->get_databox($request->attributes->get('databox_id'))->get_record($request->attributes->get('record_id'));
            if (!$user->ACL()->has_access_to_record($record)) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Check whether the current user can modify the record
         */
        $canModifyRecord = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            if (!$user->ACL()->has_right('modifyrecord')) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Check whether the current user can modify the record status
         */
        $canModifyRecordStatus = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            $record = $app['phraseanet.appbox']->get_databox($request->attributes->get('databox_id'))->get_record($request->attributes->get('record_id'));
            if (!$user->ACL()->has_right_on_base($record->get_base_id(), 'chgstatus')) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Check whether the current user can see databox structure
         */
        $canSeeDataboxStructure = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            $databox = $app['phraseanet.appbox']->get_databox($request->attributes->get('databox_id'));
            if (!$user->ACL()->has_right_on_sbas($databox->get_sbas_id(), 'bas_modify_struct')) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Check whether the current user can move record from a collection to an other
         */
        $canMoveRecord = function (Request $request) use ($app) {
            $user = $app['token']->get_account()->get_user();
            $record = $app['phraseanet.appbox']->get_databox($request->attributes->get('databox_id'))->get_record($request->attributes->get('record_id'));
            if ((!$user->ACL()->has_right('addrecord') && !$user->ACL()->has_right('deleterecord')) || !$user->ACL()->has_right_on_base($record->get_base_id(), 'candeleterecord')) {
                throw new \API_V1_exception_unauthorized('You are not authorized');
            }
        };

        /**
         * Get scheduler informations
         *
         * Route : /monitor/scheduler/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/monitor/scheduler/', function (SilexApplication $app, Request $request) {
            return $app['api']->get_scheduler($app)->get_response();
        })->before($mustBeAdmin);

        /**
         * Get all tasks information
         *
         * Route : /monitor/tasks/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/monitor/tasks/', function (SilexApplication $app, Request $request) {
            return $app['api']->get_task_list($app)->get_response();
        })->before($mustBeAdmin);

        /**
         * Get task informations
         *
         * Route : /monitor/task/{task_id}/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/monitor/task/{task_id}/', function (SilexApplication $app, Request $request, $task_id) {
            return $app['api']->get_task($app, $task_id)->get_response();
        })->before($mustBeAdmin)->assert('task_id', '\d+');

        /**
         * Start task
         *
         * Route : /monitor/task/{task_id}/
         *
         * Method : POST
         *
         * Parameters :
         * - name (string) change the name of the task
         * - autostart (boolean) start task when scheduler starts
         */
        $controllers->post('/monitor/task/{task_id}/', function (SilexApplication $app, Request $request, $task_id) {
            return $app['api']->set_task_property($app, $task_id)->get_response();
        })->before($mustBeAdmin)->assert('task_id', '\d+');

        /**
         * Start task
         *
         * Route : /monitor/task/{task_id}/start/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/monitor/task/{task_id}/start/', function (SilexApplication $app, Request $request, $task_id) {
            return $app['api']->start_task($app, $task_id)->get_response();
        })->before($mustBeAdmin);

        /**
         * Stop task
         *
         * Route : /monitor/task/{task_id}/stop/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/monitor/task/{task_id}/stop/', function (SilexApplication $app, Request $request, $task_id) {
            return $app['api']->stop_task($app, $task_id)->get_response();
        })->before($mustBeAdmin);

        /**
         * Get some information about phraseanet
         *
         * Route : /monitor/phraseanet/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/monitor/phraseanet/', function (SilexApplication $app, Request $request) {
            return $app['api']->get_phraseanet_monitor($app)->get_response();
        })->before($mustBeAdmin);

        /**
         * Route : /collections/BASE_ID/
         * Method : GET
         * Parameters :
         */
        $controllers->get('/collections/{base_id}/', function (SilexApplication $app, Request $request, $base_id) {
           return $app['api']->get_collection($request, $base_id)->get_response();
        });

        /**
         * Route : /databoxes/list/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/databoxes/list/', function (SilexApplication $app, Request $request) {
            return $app['api']->get_databoxes($request)->get_response();
        });

        /**
         * Route /databoxes/DATABOX_ID/collections/
         *
         * Method : GET
         *
         * Parameters ;
         *    DATABOX_ID : required INT
         */
        $controllers->get('/databoxes/{databox_id}/collections/', function (SilexApplication $app, $databox_id) {
            return $app['api']
                    ->get_databox_collections($app['request'], $databox_id)
                    ->get_response();
        })->before($hasAccessToDatabox)->assert('databox_id', '\d+');

        $controllers->get('/databoxes/{any_id}/collections/', $bad_request_exception);

        /**
         * Route /databoxes/DATABOX_ID/status/
         *
         * Method : GET
         *
         * Parameters ;
         *    DATABOX_ID : required INT
         *
         */
        $controllers->get('/databoxes/{databox_id}/status/', function (SilexApplication $app, $databox_id) {
            return $app['api']
                    ->get_databox_status($app['request'], $databox_id)
                    ->get_response();
        })->before($hasAccessToDatabox)->before($canSeeDataboxStructure)->assert('databox_id', '\d+');

        $controllers->get('/databoxes/{any_id}/status/', $bad_request_exception);

        /**
         * Route /databoxes/DATABOX_ID/metadatas/
         *
         * Method : GET
         *
         * Parameters ;
         *    DATABOX_ID : required INT
         */
        $controllers->get('/databoxes/{databox_id}/metadatas/', function (SilexApplication $app, $databox_id) {
            return $app['api']
                    ->get_databox_metadatas($app['request'], $databox_id)
                    ->get_response();
        })->before($hasAccessToDatabox)->before($canSeeDataboxStructure)->assert('databox_id', '\d+');

        $controllers->get('/databoxes/{any_id}/metadatas/', $bad_request_exception);

        /**
         * Route /databoxes/DATABOX_ID/termsOfUse/
         *
         * Method : GET
         *
         * Parameters ;
         *    DATABOX_ID : required INT
         */
        $controllers->get('/databoxes/{databox_id}/termsOfUse/', function (SilexApplication $app, $databox_id) {
            return $app['api']
                    ->get_databox_terms($app['request'], $databox_id)
                    ->get_response();
        })->assert('databox_id', '\d+');

        $controllers->get('/databoxes/{any_id}/termsOfUse/', $bad_request_exception);

        /**
         * Route /quarantine/list/
         *
         * Method : GET
         *
         * Parameters ;
         */
        $controllers->get('/quarantine/list/', function (SilexApplication $app, Request $request) {
            return $app['api']->list_quarantine($app, $request)->get_response();
        });

        /**
         * Route /quarantine/item/{lazaret_id}/
         *
         * Method : GET
         *
         * Parameters ;
         *      LAZARET_ID : required INT
         */
        $controllers->get('/quarantine/item/{lazaret_id}/', function ($lazaret_id, SilexApplication $app, Request $request) {
            return $app['api']->list_quarantine_item($lazaret_id, $app, $request)->get_response();
        });

        $controllers->get('/quarantine/item/{any_id}/', $bad_request_exception);

        /**
         * Route : /records/add/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/records/add/', function (SilexApplication $app, Request $request) {
            return $app['api']->add_record($app, $request)->get_response();
        });

        /**
         * Route : /embed/substitute/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/embed/substitute/', function (SilexApplication $app, Request $request) {
            return $app['api']->substitute_subdef($app, $request)->get_response();
        });

        /**
         * Route : /stories/add/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/stories', function (SilexApplication $app, Request $request) {
            return $app['api']->add_story($app, $request)->get_response();
        })->before(function(Request $request, SilexApplication $app) {
            if($request->getContentType() != 'json') {
                $app->abort(406, 'Invalid Content Type given.');
            }
        });

        /**
         * Route : /stories/{databox_id}/{story_id}/addrecords
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/stories/{databox_id}/{story_id}/addrecords', function (SilexApplication $app, Request $request, $databox_id, $story_id) {
            return $app['api']->add_records_to_story($app, $request, $databox_id, $story_id)->get_response();
        })->before(function(Request $request, SilexApplication $app) {
            if($request->getContentType() != 'json') {
                $app->abort(406, 'Invalid Content Type given.');
            }
        });

        /**
         * Route : /stories/{databox_id}/{story_id}/delrecords
         *
         * Method : DELETE
         *
         * Parameters :
         *
         */
        $controllers->delete('/stories/{databox_id}/{story_id}/delrecords', function (SilexApplication $app, Request $request, $databox_id, $story_id) {
            return $app['api']->del_records_from_story($app, $request, $databox_id, $story_id)->get_response();
        })->before(function(Request $request, SilexApplication $app) {
            if($request->getContentType() != 'json') {
                $app->abort(406, 'Invalid Content Type given.');
            }
        });

        /**
         * Route : /stories/{databox_id}/{story_id}/setcover
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/stories/{databox_id}/{story_id}/setcover', function (SilexApplication $app, Request $request, $databox_id, $story_id) {
            return $app['api']->set_story_cover($app, $request, $databox_id, $story_id)->get_response();
        })->before(function(Request $request, SilexApplication $app) {
            if($request->getContentType() != 'json') {
                $app->abort(406, 'Invalid Content Type given.');
            }
        });


        /**
         * Route : /search/
         *
         * Method : GET or POST
         *
         * Parameters :
         *    bases[] : array
         *    status[] : array
         *    fields[] : array
         *    record_type : boolean
         *    media_type : string
         *
         * Response :
         *    Array containing an array of records and stories collection
         *
         */
        $controllers->match('/search/', function () use ($app) {
            return $app['api']->search($app['request'])->get_response();
        });

        /**
         * Route : /records/search/
         *
         * Method : GET or POST
         *
         * Parameters :
         *    bases[] : array
         *    status[] : array
         *    fields[] : array
         *    record_type : boolean
         *    media_type : string
         *
         * Response :
         *    Array of record objects
         *
         */
        $controllers->match('/records/search/', function (SilexApplication $app) {
            return $app['api']->search_records($app['request'])->get_response();
        });

        /**
         * Route : /records/{databox_id}/{record_id}/caption/
         *
         * Parameters ;
         *      DATABOX_ID : required INT
         *      RECORD_ID : required INT
         */
        $controllers->get('/records/{databox_id}/{record_id}/caption/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->caption_records($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/records/{any_id}/{anyother_id}/caption/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/metadatas/
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->get('/records/{databox_id}/{record_id}/metadatas/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->get_record_metadatas($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/records/{any_id}/{anyother_id}/metadatas/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/status/
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->get('/records/{databox_id}/{record_id}/status/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->get_record_status($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/records/{any_id}/{anyother_id}/status/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/related/
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->get('/records/{databox_id}/{record_id}/related/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->get_record_related($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/records/{any_id}/{anyother_id}/related/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/embed/
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->get('/records/{databox_id}/{record_id}/embed/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->get_record_embed($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/records/{any_id}/{anyother_id}/embed/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/setmetadatas/
         *
         * Method : POST
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->post('/records/{databox_id}/{record_id}/setmetadatas/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->set_record_metadatas($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->before($canModifyRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->post('/records/{any_id}/{anyother_id}/setmetadatas/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/setstatus/
         *
         * Method : POST
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->post('/records/{databox_id}/{record_id}/setstatus/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->set_record_status($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->before($canModifyRecord)->before($canModifyRecordStatus)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->post('/records/{any_id}/{anyother_id}/setstatus/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID/setcollection/
         *
         * Method : POST
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->post('/records/{databox_id}/{record_id}/setcollection/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->set_record_collection($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->before($canMoveRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->post('/records/{wrong_databox_id}/{wrong_record_id}/setcollection/', $bad_request_exception);

        /**
         * Route : /records/DATABOX_ID/RECORD_ID
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    RECORD_ID : required INT
         *
         */
        $controllers->get('/records/{databox_id}/{record_id}/', function (SilexApplication $app, $databox_id, $record_id) {
            return $app['api']
                    ->get_record($app['request'], $databox_id, $record_id)
                    ->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/records/{any_id}/{anyother_id}/', $bad_request_exception);

        /**
         * Route : /baskets/list/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->get('/baskets/list/', function (SilexApplication $app) {
            return $app['api']->search_baskets($app['request'])->get_response();
        });

        /**
         * Route : /baskets/add/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->post('/baskets/add/', function (SilexApplication $app) {
            return $app['api']->create_basket($app['request'])->get_response();
        });

        /**
         * Route : /baskets/BASKET_ID/content/
         *
         * Method : GET
         *
         * Parameters :
         *    BASKET_ID : required INT
         *
         */
        $controllers->get('/baskets/{basket_id}/content/', function (SilexApplication $app, $basket_id) {
            return $app['api']->get_basket($app['request'], $basket_id)->get_response();
        })->assert('basket_id', '\d+');

        $controllers->get('/baskets/{wrong_basket_id}/content/', $bad_request_exception);

        /**
         * Route : /baskets/BASKET_ID/settitle/
         *
         * Method : GET
         *
         * Parameters :
         *    BASKET_ID : required INT
         *
         */
        $controllers->post('/baskets/{basket_id}/setname/', function (SilexApplication $app, $basket_id) {
            return $app['api']
                    ->set_basket_title($app['request'], $basket_id)
                    ->get_response();
        })->assert('basket_id', '\d+');

        $controllers->post('/baskets/{wrong_basket_id}/setname/', $bad_request_exception);

        /**
         * Route : /baskets/BASKET_ID/setdescription/
         *
         * Method : POST
         *
         * Parameters :
         *    BASKET_ID : required INT
         *
         */
        $controllers->post('/baskets/{basket_id}/setdescription/', function (SilexApplication $app, $basket_id) {
            return $app['api']
                    ->set_basket_description($app['request'], $basket_id)
                    ->get_response();
        })->assert('basket_id', '\d+');

        $controllers->post('/baskets/{wrong_basket_id}/setdescription/', $bad_request_exception);

        /**
         * Route : /baskets/BASKET_ID/delete/
         *
         * Method : POST
         *
         * Parameters :
         *    BASKET_ID : required INT
         *
         */
        $controllers->post('/baskets/{basket_id}/delete/', function (SilexApplication $app, $basket_id) {
            return $app['api']->delete_basket($app['request'], $basket_id)->get_response();
        })->assert('basket_id', '\d+');

        $controllers->post('/baskets/{wrong_basket_id}/delete/', $bad_request_exception);

        /**
         * Route : /feeds/list/
         *
         * Method : POST
         *
         * Parameters :
         *
         */
        $controllers->get('/feeds/list/', function (SilexApplication $app) {
            return $app['api']
                    ->search_publications($app['request'], $app['authentication']->getUser())
                    ->get_response();
        });

        /**
         * Route : /feeds/content/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/feeds/content/', function (SilexApplication $app) {
            return $app['api']
                    ->get_publications($app['request'], $app['authentication']->getUser())
                    ->get_response();
        });

        /**
         * Route : /feeds/entry/{entry_id}/
         *
         * Method : GET
         *
         * Parameters :
         *    ENTRY_ID : required INT
         *
         */
        $controllers->get('/feeds/entry/{entry_id}/', function (SilexApplication $app, $entry_id) {
            return $app['api']
                    ->get_feed_entry($app['request'], $entry_id, $app['authentication']->getUser())
                    ->get_response();
        })->assert('entry_id', '\d+');

        $controllers->get('/feeds/entry/{entry_id}/', $bad_request_exception);

        /**
         * Route : /feeds/PUBLICATION_ID/content/
         *
         * Method : GET
         *
         * Parameters :
         *    PUBLICATION_ID : required INT
         *
         */
        $controllers->get('/feeds/{feed_id}/content/', function (SilexApplication $app, $feed_id) {
            return $app['api']
                    ->get_publication($app['request'], $feed_id, $app['authentication']->getUser())
                    ->get_response();
        })->assert('feed_id', '\d+');

        $controllers->get('/feeds/{wrong_feed_id}/content/', $bad_request_exception);

        /**
         * Route : /stories/DATABOX_ID/RECORD_ID/embed/
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    STORY_ID : required INT
         *
         */
        $controllers->get('/stories/{databox_id}/{record_id}/embed/', function ($databox_id, $record_id) use ($app) {
                $result = $app['api']->get_story_embed($app['request'], $databox_id, $record_id);

                return $result->get_response();
            }
        )->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/stories/{any_id}/{anyother_id}/embed/', $bad_request_exception);

        /**
         * Route : /stories/DATABOX_ID/RECORD_ID/
         *
         * Method : GET
         *
         * Parameters :
         *    DATABOX_ID : required INT
         *    STORY_ID : required INT
         *
         */
        $controllers->get('/stories/{databox_id}/{record_id}/', function ($databox_id, $record_id) use ($app) {
            $result = $app['api']->get_story($app['request'], $databox_id, $record_id);

            return $result->get_response();
        })->before($hasAccessToRecord)->assert('databox_id', '\d+')->assert('record_id', '\d+');

        $controllers->get('/stories/{any_id}/{anyother_id}/', $bad_request_exception);

        /**
         * Route : /me/
         *
         * Method : GET
         *
         * Parameters :
         *
         */
        $controllers->get('/me/', function (SilexApplication $app, Request $request) {
            $result = $app['api']->get_current_user($app, $request);

            return $result->get_response();
        });

        $controllers->delete('/me/',function (SilexApplication $app, Request $request) {
            $result = $app['api']->delete_account(null);

            return $result->get_response();
        });

        $controllers->post('/me/update-account/', function (Request $request) use ($app) {
            $data = json_decode($request->getContent(false), true);
            $result = $app['api']->update_account(null, $data);

            return $result->get_response();
        });

        $controllers->post('/me/update-password/', function (Request $request) use ($app) {
            $data = json_decode($request->getContent(false), true);
            $result = $app['api']->update_password(null, $data);

            return $result->get_response();
        });

        $controllers->post('/me/request-collections/', function (Request $request) use ($app) {
            $data = json_decode($request->getContent(false), true);
            $result = $app['api']->create_collection_requests($data);

            return $result->get_response();
        });

        /**
         * Route : /accounts/reset-password/{login}/
         *
         * Method : POST
         *
         * Parameters :
         *      email : required STRING
         */
        $controllers->post('/accounts/reset-password/{login}/', function ($login) use ($app) {
            $result = $app['api']->reset_password($login);

            return $result->get_response();
        })
            ->before($requirePasswordGrant)
            ->before($requireUserManagementRight);

        /**
         * Route : /accounts/update-password/{token}/
         *
         * Method : POST
         *
         * Parameters :
         *  token : required STRING
         */
        $controllers->post('/accounts/update-password/{token}/', function (Request $request, $token) use ($app) {
            $result = $app['api']->set_new_password($token, $request->request->get('password', null));

            return $result->get_response();
        })
            ->before($requirePasswordGrant)
            ->before($requireUserManagementRight);

        $controllers->post('/accounts/access-demand/', function (Request $request) use ($app) {
            $data = json_decode($request->getContent(false), true);
            $result = $app['api']->create_account($data);

            return $result->get_response();
        })
            ->before($requirePasswordGrant)
            ->before($requireUserManagementRight);

        $controllers->post('/accounts/unlock/{token}/', function ($token) use ($app) {
            $result = $app['api']->unlock_account($token);

            return $result->get_response();
        })
            ->before($requirePasswordGrant)
            ->before($requireUserManagementRight);

        return $controllers;
    }
}