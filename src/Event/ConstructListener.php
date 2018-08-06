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
use Cake\Cache\Cache;
use Cake\Collection\Collection;
use Cake\Core\App;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Server;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Cake\Routing\Router;
use Doctrine\Common\Annotations\AnnotationReader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\StreamingServer as HttpServer;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use React\Socket\Server as SocketServer;
use Cake\Core\Configure;
use Cake\Event\EventListenerInterface;
use function RingCentral\Psr7\stream_for;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflector\ClassReflector;
use Roave\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;
use WyriHaximus\PSR3\CallableThrowableLogger\CallableThrowableLogger;
use WyriHaximus\PSR3\ContextLogger\ContextLogger;
use function WyriHaximus\psr7_response_decode;
use function WyriHaximus\psr7_response_encode;
use function WyriHaximus\psr7_server_request_decode;
use function WyriHaximus\psr7_server_request_encode;
use WyriHaximus\React\Cake\Http\Network\Session;
use WyriHaximus\React\ChildProcess\Closure\ClosureChild;
use WyriHaximus\React\ChildProcess\Closure\MessageFactory;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Pool\Factory\Flexible;
use WyriHaximus\React\ChildProcess\Pool\Options;
use WyriHaximus\React\ChildProcess\Pool\PoolInterface;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;
use WyriHaximus\React\Http\Middleware\WebrootPreloadMiddleware;
use WyriHaximus\React\Http\PSR15MiddlewareGroup\Factory;
use function WyriHaximus\toChildProcessOrNotToChildProcess;
use function WyriHaximus\toCoroutineOrNotToCoroutine;

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

        $childProcessPool = Flexible::createFromClass(
            ClosureChild::class,
            $loop,
            [
                Options::TTL      => 1,
                Options::MIN_SIZE => 0,
                Options::MAX_SIZE => 5,
            ]
        );

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

        $middleware[] = function (ServerRequestInterface $request) use ($childProcessPool) {
            return $this->handleRequest($request, $childProcessPool);
        };

        $socket = new SocketServer(Configure::read('WyriHaximus.HttpServer.address'), $loop);
        $http = new HttpServer($middleware);
        $http->listen($socket);
        $http->on('error', function ($error) {
            echo (string)$error;
        });
        $http->on('error', CallableThrowableLogger::create($logger));
    }

    private function handleRequest(ServerRequestInterface $request, PromiseInterface $childProcessPool)
    {
        $route = Router::parseRequest($request);
        var_export($route);
        foreach (App::path('Controller', $route['plugin']) as $path) {
            $fileName = $path . $route['controller'] . 'Controller.php';
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
                    return $this->handRequestInChildProcess($request, $childProcessPool);
                }

                $request = $request->withAttribute('coroutine', toCoroutineOrNotToCoroutine($requestHandler, $annotationReader));

                break;
            }

            break;
        }

        return $this->requestExecutionFunction()($request);

        //return $this->handRequestInChildProcess($request, $childProcessPool);
    }

    private function handRequestInChildProcess(ServerRequestInterface $request, PromiseInterface $childProcessPool)
    {
        $jsonRequest = psr7_server_request_encode($request);
        $rpc = MessageFactory::rpc($this->childProcessFunction($jsonRequest));

        return $childProcessPool->then(function (PoolInterface $pool) use ($rpc) {
            return $pool->rpc($rpc);
        })->then(function (Payload $payload) {
            $response = $payload->getPayload();
            return psr7_response_decode($response);
        });
    }

    private function childProcessFunction(array $request)
    {
        $root = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        $handler = $this->requestExecutionFunction();

        return function () use ($root, $request, $handler) {
            $request = psr7_server_request_decode($request);

            require $root . '/config/paths.php';
            require CORE_PATH . 'config' . DS . 'bootstrap.php';

            $response = $handler($request);

            return psr7_response_encode($response);

            /*if (method_exists($response, 'getPromise')) {
                return resolve($response->getPromise());
            }

            return resolve($response)->always(function () use ($session) {
                if ($session->read() === [] || $session->read() === null) {
                    $session->destroy();
                }
            });*/
        };
    }

    private function requestExecutionFunction()
    {
        return function (ServerRequestInterface $request) {
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

            /** @var ResponseInterface $response */
            $response = $server->run($sr);
            $response = $response->withBody(stream_for($response->body()));
            return $response;
        };
    }
}