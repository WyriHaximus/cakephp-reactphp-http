<?php

/*
 * This file is part of Ratchet.
 *
 ** (c) 2016 Cees-Jan Kiewiet
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace WyriHaximus\React\Cake\Http;

use React\EventLoop\LoopInterface;
use WyriHaximus\React\ChildProcess\Messenger\ChildInterface;
use WyriHaximus\React\ChildProcess\Messenger\Messages\Payload;
use WyriHaximus\React\ChildProcess\Messenger\Messenger;
use WyriHaximus\React\Http\Middleware\Session;
use WyriHaximus\React\Http\Middleware\SessionId\RandomBytes;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;
use function React\Promise\resolve;
use function RingCentral\Psr7\stream_for;
use function WyriHaximus\psr7_response_encode;
use function WyriHaximus\psr7_server_request_decode;

final class PoolWorker implements ChildInterface
{
    public static function create(Messenger $messenger, LoopInterface $loop)
    {
        $root = dirname(dirname(dirname(dirname(__DIR__))));
        $messenger->registerRpc('request', function (Payload $payload) use ($root) {
            $request = psr7_server_request_decode($payload['request']);
            $session = new Session('', [], new RandomBytes());

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

            $response = requestExecutionFunction($request);
            $response = $response->withBody(stream_for($response->body()));

            return resolve([
                'response' => psr7_response_encode($response),
                'session' => $session->toArray(),
            ]);
        });
    }
}
