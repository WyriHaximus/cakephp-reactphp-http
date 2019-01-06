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

use Cake\Controller\Exception\MissingActionException;
use Cake\Error\ExceptionRenderer;
use Cake\Http\Exception\HttpException;
use LogicException;
use React\Promise\Promise;
use Throwable;
use WyriHaximus\Cake\DI\Annotations\Inject;
use WyriHaximus\React\Cake\Http\Http\PromiseResponse;
use WyriHaximus\Recoil\Call;
use WyriHaximus\Recoil\QueueCallerInterface;
use function ApiClients\Tools\Rx\observableFromArray;
use function React\Promise\reject;
use function React\Promise\resolve;

trait CoroutineInvokeActionTrait
{
    /** @var QueueCallerInterface */
    private $queueCaller;

    /**
     * @param QueueCallerInterface $queueCaller
     * @Inject()
     */
    public function setQueueCaller(QueueCallerInterface $queueCaller): void
    {
        $this->queueCaller = $queueCaller;
    }

    public function invokeAction()
    {
        $request = $this->request;
        if (!$request) {
            throw new LogicException('No Request object configured. Cannot invoke action');
        }
        if (!$this->isAction($request->getParam('action'))) {
            throw new MissingActionException([
                'controller' => $this->name . 'Controller',
                'action' => $request->getParam('action'),
                'prefix' => $request->getParam('prefix') ?: '',
                'plugin' => $request->getParam('plugin'),
            ]);
        }

        if ($request->getAttribute('coroutine', false) !== true || !($this->queueCaller instanceof QueueCallerInterface)) {
            return parent::invokeAction();
        }

        /* @var callable $callable */
        $callable = [$this, $request->getParam('action')];

        $call = new Call($callable, ...array_values($request->getParam('pass')));
        $this->queueCaller->call(observableFromArray([$call]));

        return (new PromiseResponse())->setPromise((new Promise(function ($resolve, $reject) use ($call) {
            $call->wait($resolve, $reject);
        }))->then(function ($response) {
            if ($response !== null) {
                return $response;
            }

            if ($this->isAutoRenderEnabled()) {
                return $this->render();
            }

            return $response;
        }, function (Throwable $throwable) {
            if ($throwable instanceof HttpException) {
                return resolve((new ExceptionRenderer($throwable))->render());
            }

            return reject($throwable);
        }));
    }
}
