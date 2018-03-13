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

use Goodby\CSV\Export\Standard\Exporter;
use Goodby\CSV\Export\Standard\ExporterConfig;
use Goodby\CSV\Import\Standard\Interpreter;
use Goodby\CSV\Import\Standard\Lexer;
use Goodby\CSV\Import\Standard\LexerConfig;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;


class CSVServiceProvider implements ServiceProviderInterface
{
    public function register(Container $app)
    {
        $app['csv.exporter.config'] = function () {
            $config = new ExporterConfig();

            return $config
                ->setDelimiter(";")
                ->setEnclosure('"')
                ->setEscape("\\")
                ->setToCharset('UTF-8')
                ->setFromCharset('UTF-8');

        };

        $app['csv.exporter'] = function ($app) {
            return new Exporter($app['csv.exporter.config']);
        };

        $app['csv.lexer.config'] = function () {
            $lexer = new LexerConfig();
            $lexer->setDelimiter(';')
                ->setEnclosure('"')
                ->setEscape("\\")
                ->setToCharset('UTF-8')
                ->setFromCharset('UTF-8');

            return $lexer;
        };

        $app['csv.lexer'] = function ($app) {
            return new Lexer($app['csv.lexer.config']);
        };

        $app['csv.interpreter'] = function () {
            return new Interpreter();
        };

        $app['csv.response'] = $app->protect(function ($callback) use ($app) {
            // set headers to fix ie issues
            $response =  new StreamedResponse($callback, 200,  [
                'Expires'               => 'Mon, 26 Jul 1997 05:00:00 GMT',
                'Last-Modified'         => gmdate('D, d M Y H:i:s'). ' GMT',
                'Cache-Control'         => 'no-store, no-cache, must-revalidate, max-age=3600',
                'Pragma'                => 'no-cache',
                'Content-Type'          => 'text/csv',
            ]);

            $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'export.csv'
            ));
        });
    }
}
