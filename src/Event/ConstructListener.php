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

use Psr\Http\Message\ServerRequestInterface;
use React\Http\StreamingServer as HttpServer;
use React\Socket\Server as SocketServer;
use Cake\Core\Configure;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use Thruway\Authentication\AuthenticationManager;
use Thruway\Peer\Router;
use Thruway\Transport\RatchetTransportProvider;
use WyriHaximus\PSR3\CallableThrowableLogger\CallableThrowableLogger;
use WyriHaximus\PSR3\ContextLogger\ContextLogger;
use WyriHaximus\Ratchet\Security\AuthorizationManager;
use WyriHaximus\Ratchet\Security\JWTAuthProvider;
use WyriHaximus\Ratchet\Security\WampCraAuthProvider;
use WyriHaximus\Ratchet\Websocket\InternalClient;

final class ConstructListener implements EventListenerInterface
{
    private $annotations = [];

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
        $http->on('error', CallableThrowableLogger::create($logger));
    }

    private function handlerRequest(ServerRequestInterface $request)
    {
        //
    }
}