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
use Cake\Event\EventListenerInterface;
use Cake\Http\Server;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\Routing\Router;
use Doctrine\Common\Annotations\AnnotationReader;
use Psr\Http\Message\ResponseInterface;
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
use WyriHaximus\React\Cake\Http\Network\Session;
use WyriHaximus\React\ChildProcess\Closure\ClosureChild;
use WyriHaximus\React\ChildProcess\Closure\MessageFactory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Pool\Factory\Flexible;
use WyriHaximus\React\ChildProcess\Pool\Options;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
use WyriHaximus\React\Http\Middleware\SessionId\RandomBytes;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;
use WyriHaximus\React\Http\Middleware\WebrootPreloadMiddleware;
use WyriHaximus\React\Http\PSR15MiddlewareGroup\Factory;
use function React\Promise\resolve;
use function RingCentral\Psr7\stream_for;
use function WyriHaximus\psr7_response_decode;
use function WyriHaximus\psr7_response_encode;
use function WyriHaximus\psr7_server_request_decode;
use function WyriHaximus\psr7_server_request_encode;
use WyriHaximus\React\Inspector\ChildProcessPools\ChildProcessPoolsCollector;
use function WyriHaximus\React\tickingFuturePromise;
use function WyriHaximus\toChildProcessOrNotToChildProcess;
use function WyriHaximus\toCoroutineOrNotToCoroutine;

final class ConstructListener implements EventListenerInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var PoolInterface
     */
    private $pool;

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
        $this->loop = $event->getLoop();

        Flexible::createFromClass(
            ClosureChild::class,
            $this->loop,
            [
                Options::TTL      => 1,
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

        $socket = new SocketServer(Configure::read('WyriHaximus.HttpServer.address'), $this->loop);
        $http = new HttpServer($middleware);
        $http->listen($socket);
        $http->on('error', function ($error) {
            echo (string)$error;
        });
        $http->on('error', CallableThrowableLogger::create($logger));
    }

    private function handleRequest(ServerRequestInterface $request)
    {
        $route = Router::parseRequest($request);

        if (isset($route['child-process']) && $route['child-process'] === true) {
            return $this->handRequestInChildProcess($request);
        }

        foreach (App::path('Controller', $route['plugin']) as $path) {
            $fileName = str_replace('//', '/', $path . (isset($route['prefix']) ? $route['prefix'] . '/' : '') . $route['controller'] . 'Controller.php');
            if (!file_exists($fileName)) {
                continue;
            }

            $astLocator = (new BetterReflection())->astLocator();
            $reflector = new ClassReflector(new SingleFileSourceLocator($fileName, $astLocator));

            foreach ($reflector->getAllClasses() as $class) {
                if ($class->getShortName() !== $route['controller'] . 'Controller') {
                    continue;
                }

                $requestHandler = $class->getName() . '::' . $route['action'];
                $annotationReader = new AnnotationReader();
                if (toChildProcessOrNotToChildProcess($requestHandler, $annotationReader)) {
                    return $this->handRequestInChildProcess($request);
                }

                $request = $request->withAttribute('coroutine', toCoroutineOrNotToCoroutine($requestHandler, $annotationReader));
                break;
            }

            break;
        }

        return $this->requestExecutionFunction()($request);
    }

    private function handRequestInChildProcess(ServerRequestInterface $request)
    {
        $func = function (PoolInterface $pool) use ($request) {
            if ($request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME, false) !== false) {
                $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME);
                $request = $request->withAttribute(SessionMiddleware::ATTRIBUTE_NAME, $session->toArray());
            }

            $jsonRequest = psr7_server_request_encode($request);
            $rpc = MessageFactory::rpc($this->childProcessFunction($jsonRequest));

            return $this->pool->rpc($rpc)->then(function (Payload $payload) use ($session) {
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

    private function childProcessFunction(array $request)
    {
        $root = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        $handler = $this->requestExecutionFunction();

        return function () use ($root, $request, $handler) {
            $request = psr7_server_request_decode($request);
            $session = (new \WyriHaximus\React\Http\Middleware\Session('', [], new RandomBytes()));

            if ($request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME, false) !== false) {
                $serializedSession = $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME);
                $session = $session->fromArray(
                    $serializedSession
                );
                $request = $request->withAttribute(
                    SessionMiddleware::ATTRIBUTE_NAME,
                    $session
                );
            }

            require_once $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php';

            $response = $handler($request);
            $response = $response->withBody(stream_for($response->body()));

            return [
                'response' => psr7_response_encode($response),
                'session' => $session->toArray(),
            ];
        };
    }

    private function requestExecutionFunction()
    {
        return static function (ServerRequestInterface $request) {
            parse_str($request->getUri()->getQuery(), $query);

            $serverFactory = Configure::read('WyriHaximus.HttpServer.factories.server');
            /** @var Server $application */
            $server = $serverFactory();

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
            foreach ($request->getAttributes() as $key => $value) {
                $sr = $sr->withAttribute($key, $value);
            }

            /** @var ResponseInterface $response */
            $response = $server->run($sr);

            if ($session->read() === [] || $session->read() === null) {
                $session->destroy();
            }

            return $response;
        };
    }
}
