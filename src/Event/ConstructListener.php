<?php

/*
 * This file is part of Ratchet.
 *
 ** (c) 2016 Cees-Jan Kiewiet
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WyriHaximus\React\Cake\Http\Event;

use App\Application;
use Cake\Http\Server;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\StreamingServer as HttpServer;
use function React\Promise\resolve;
use React\Socket\Server as SocketServer;
use Cake\Core\Configure;
use Cake\Event\EventListenerInterface;
use WyriHaximus\PSR3\CallableThrowableLogger\CallableThrowableLogger;
use WyriHaximus\PSR3\ContextLogger\ContextLogger;
use WyriHaximus\React\Cake\Http\Network\Session;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;
use WyriHaximus\React\Http\Middleware\WebrootPreloadMiddleware;
use WyriHaximus\React\Http\PSR15MiddlewareGroup\Factory;

final class ConstructListener implements EventListenerInterface
{
    /**
     * @return array
     */
    public function implementedEvents()
    {
        return [
            ConstructEvent::EVENT => 'construct',
        ];
    }

    /**
     * @param ConstructEvent $event
     */
    public function construct(ConstructEvent $event)
    {
        $loop = $event->getLoop();
        $logger = new ContextLogger(
            $event->getLogger(),
            [
                'plugin' => 'WyriHaximus/React/Cake/Http',
                'department' => 'http-server',
            ],
            'http-server'
        );

        $middleware = [];

        if (Configure::check('WyriHaximus.HttpServer.middleware.prefix')) {
            array_push($middleware, ...Configure::read('WyriHaximus.HttpServer.middleware.prefix'));
        }

        $middleware[] = Factory::create($loop, $logger);
        $middleware[] = new WebrootPreloadMiddleware(
            WWW_ROOT,
            new ContextLogger($logger, ['section' => 'webroot'], 'webroot')
        );

        if (Configure::check('WyriHaximus.HttpServer.middleware.suffix')) {
            array_push($middleware, ...Configure::read('WyriHaximus.HttpServer.middleware.suffix'));
        }

        $middleware[] = function (ServerRequestInterface $request) {
            return $this->handlerRequest($request);
        };

        $socket = new SocketServer(Configure::read('WyriHaximus.HttpServer.address'), $loop);
        $http = new HttpServer($middleware);
        $http->listen($socket);
        $http->on('error', function ($error) {
            echo (string)$error;
        });
        $http->on('error', CallableThrowableLogger::create($logger));
    }

    private function handlerRequest(ServerRequestInterface $request)
    {
        parse_str($request->getUri()->getQuery(), $query);

        $applicationFactory = Configure::read('WyriHaximus.HttpServer.factories.application');
        /** @var Application $application */
        $application = $applicationFactory();
        $server = new Server($application);

        $environment = ServerRequestFactory::normalizeServer($request->getServerParams() + ['REQUEST_URI' => $request->getUri()->getPath()]);
        $uri = ServerRequestFactory::createUri($environment);

        $session = new Session($request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME));

        $sr = new ServerRequest([
            'environment' => $environment,
            'uri' => $uri,
            'files' => $request->getUploadedFiles(),
            'cookies' => $request->getCookieParams(),
            'query' => $query,
            'post' => (array)$request->getParsedBody(),
            'webroot' => $uri->webroot,
            'base' => $uri->base,
            'session' => $session,
        ]);

        /** @var ResponseInterface $response */
        $response = $server->run($sr);
        if (method_exists($response, 'getPromise')) {
            return resolve($response->getPromise());
        }

        return resolve($response)->always(function () use ($session) {
            if ($session->read() === [] || $session->read() === null) {
                $session->destroy();
            }
        });
    }
}