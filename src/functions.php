<?php

namespace WyriHaximus\React\Cake\Http;

use Cake\Core\Configure;
use Cake\Error\ExceptionRenderer;
use Cake\Http\Exception\HttpException;
use Cake\Http\ServerRequest;
use Cake\Http\ServerRequestFactory;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use WyriHaximus\React\Cake\Http\Network\Session;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;

/**
 * @return LoopInterface
 */
function loopResolver()
{
    if (
        Configure::check('WyriHaximus.React.Http.loop') &&
        Configure::read('WyriHaximus.React.Http.loop') instanceof LoopInterface
    ) {
        return Configure::read('WyriHaximus.React.Http.loop');
    }

    return Factory::create();
}

function requestExecutionFunction(ServerRequestInterface $request)
{
    parse_str($request->getUri()->getQuery(), $query);

    $extraEnv = ['REQUEST_URI' => $request->getUri()->getPath(), 'REQUEST_METHOD' => $request->getMethod()];
    foreach ($request->getHeaders() as $header => $contents) {
        $extraEnv['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $request->getHeaderLine($header);
    }
    $environment = ServerRequestFactory::normalizeServer($request->getServerParams() + $extraEnv);
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
        'input' => $request->getBody()->getContents(),
    ]);
    foreach ($request->getAttributes() as $key => $value) {
        $sr = $sr->withAttribute($key, $value);
    }

    $serverFactory = Configure::read('WyriHaximus.HttpServer.factories.server');

    try {
        /** @var ResponseInterface $response */
        $response = ($serverFactory())->run($sr);
    } catch (HttpException $httpException) {
        $response = (new ExceptionRenderer($httpException))->render();
    }

    if ($session->read() === [] || $session->read() === null) {
        $session->destroy();
    }
    unset($session);

    return $response;
}
