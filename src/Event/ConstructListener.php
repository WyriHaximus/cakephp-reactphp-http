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
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Event\EventListenerInterface;
use Cake\Routing\Router;
use Cake\Utility\Inflector;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\CachedReader;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\EventLoop\LoopInterface;
use React\Http\StreamingServer as HttpServer;
use React\Socket\Server as SocketServer;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflector\ClassReflector;
use Roave\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;
use WyriHaximus\PSR3\CallableThrowableLogger\CallableThrowableLogger;
use WyriHaximus\PSR3\ContextLogger\ContextLogger;
use WyriHaximus\React\Cake\Http\PoolWorker;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Pool\Factory\Flexible;
use WyriHaximus\React\ChildProcess\Pool\Options;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;
use WyriHaximus\React\Http\Middleware\WebrootPreloadMiddleware;
use WyriHaximus\React\Http\PSR15MiddlewareGroup\Factory;
use WyriHaximus\React\Inspector\ChildProcessPools\ChildProcessPoolsCollector;
use function React\Promise\resolve;
use function WyriHaximus\psr7_response_decode;
use function WyriHaximus\psr7_server_request_encode;
use function WyriHaximus\React\Cake\Http\requestExecutionFunction;
use function WyriHaximus\React\tickingFuturePromise;
use function WyriHaximus\toChildProcessOrNotToChildProcess;
use function WyriHaximus\toCoroutineOrNotToCoroutine;

final class ConstructListener implements EventListenerInterface
{
    /** @var LoopInterface */
    private $loop;

    /** @var PoolInterface */
    private $pool;

    /** @var SocketServer */
    private $socket;

    /** @var HttpServer */
    private $http;

    /** @var array  */
    private $cache = [];

    /**
     * @return array
     */
    public function implementedEvents()
    {
        return [
            ConstructEvent::EVENT => 'construct',
            DestructEvent::EVENT => 'destruct',
        ];
    }

    /**
     * @param ConstructEvent $event
     */
    public function construct(ConstructEvent $event)
    {

        $astLocator = (new BetterReflection())->astLocator();
        $annotationReader = new CachedReader(
            new AnnotationReader(),
            new \Doctrine\Common\Cache\ArrayCache()
        );

        foreach (array_merge(Plugin::loaded(), [null]) as $plugin) {
            foreach (App::path('Controller', $plugin) as $path) {
                if (!file_exists($path)) {
                    continue;
                }

                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                    $fileName = (string)$file;

                    if (!file_exists($fileName) || !is_file($fileName)) {
                        continue;
                    }

                    $refelector = new ClassReflector(new SingleFileSourceLocator($fileName, $astLocator));
                    foreach ($refelector->getAllClasses() as $class) {
                        /*if ($class->getShortName() !== $route['controller'] . 'Controller') {
                            //continue;
                        }*/
                        if (strpos($class->getName(), '@') !== false) {
                            continue;
                        }

                        foreach ($class->getImmediateMethods() as $method) {
                            $requestHandler = $class->getName() . '::' . $method->getName();
                            $this->cache[($plugin !== null ? $plugin . '.' : '') . substr(explode('\Controller\\', $class->getName())[1], 0, -10) . '::' . $method->getName()] = [
                                'fqmn' => $requestHandler,
                                'coroutine' => toCoroutineOrNotToCoroutine($requestHandler, $annotationReader),
                                'child-process' => toChildProcessOrNotToChildProcess($requestHandler, $annotationReader),
                            ];
                        }
                    }
                }
            }
        }

        $this->loop = $event->getLoop();

        Flexible::createFromClass(
            PoolWorker::class,
            $this->loop,
            [
                Options::TTL      => 4,
                Options::MIN_SIZE => 0,
                Options::MAX_SIZE => 5,
            ]
        )->done(function (PoolInterface $pool) use ($event) {
            $this->pool = $pool;
            $data = $event->getData();
            if (isset($data['cpc']) && $data['cpc'] instanceof ChildProcessPoolsCollector) {
                $data['cpc']->register('http-server', $pool);
            }
        });

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

        $middleware[] = Factory::create($this->loop, $logger);

        $cache = null;
        if (Configure::check('WyriHaximus.HttpServer.middleware.preload.cache')) {
            $cache = Configure::read('WyriHaximus.HttpServer.middleware.preload.cache');
        }
        if (!($cache instanceof CacheInterface)) {
            $cache = new ArrayCache();
        }

        $middleware[] = new WebrootPreloadMiddleware(
            WWW_ROOT,
            new ContextLogger($logger, ['section' => 'webroot'], 'webroot'),
            $cache
        );

        if (Configure::check('WyriHaximus.HttpServer.middleware.suffix')) {
            array_push($middleware, ...Configure::read('WyriHaximus.HttpServer.middleware.suffix'));
        }

        $middleware[] = function (ServerRequestInterface $request) {
            $response = $this->handleRequest($request);

            if (method_exists($response, 'getPromise')) {
                return resolve($response->getPromise());
            }

            return $response;
        };

        $this->socket = new SocketServer(Configure::read('WyriHaximus.HttpServer.address'), $this->loop);
        $this->http = new HttpServer($middleware);
        $this->http->listen($this->socket);
        $this->http->on('error', function ($error) {
            echo (string)$error;
        });
        $this->http->on('error', CallableThrowableLogger::create($logger));
    }

    public function destruct(DestructEvent $event)
    {
        $this->socket->close();
    }

    private function handleRequest(ServerRequestInterface $request)
    {
        $route = Router::parseRequest($request);

        if (isset($route['child-process']) && $route['child-process'] === true) {
            return $this->handRequestInChildProcess($request);
        }

        $lookup = ($route['plugin'] !== null ? $route['plugin'] . '.' : '') . (isset($route['prefix']) ? Inflector::camelize($route['prefix']) . '\\' : '') . $route['controller'] . '::' . $route['action'];

        if (!isset($this->cache[$lookup])) {
            return $this->handRequestInChildProcess($request);
        }

        if ($this->cache[$lookup]['child-process'] === true) {
            return $this->handRequestInChildProcess($request);
        }

        if ($this->cache[$lookup]['coroutine'] === true) {
            return requestExecutionFunction($request->withAttribute('coroutine', true));
        }

        return $this->handRequestInChildProcess($request);
    }

    private function handRequestInChildProcess(ServerRequestInterface $request)
    {
        $func = function (PoolInterface $pool) use ($request) {
            if ($request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME, false) !== false) {
                $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME);
                $request = $request->withAttribute(SessionMiddleware::ATTRIBUTE_NAME, $session->toArray());
            }

            $jsonRequest = psr7_server_request_encode($request);

            return $this->pool->rpc(\WyriHaximus\React\ChildProcess\Messenger\Messages\Factory::rpc('request', ['request' => $jsonRequest]))->then(function (Payload $payload) use ($session) {
                $response = $payload->getPayload();
                $session->fromArray($response['session'], false);
                return psr7_response_decode($response['response']);
            });
        };

        if ($this->pool instanceof PoolInterface) {
            return $func($this->pool);
        }

        return tickingFuturePromise($this->loop, function () {
            return $this->pool instanceof PoolInterface;
        })->then($func);
    }
}
