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

use function ApiClients\Tools\Rx\observableFromArray;
use Cake\Controller\Exception\MissingActionException;
use LogicException;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use Recoil\React\ReactKernel;
use WyriHaximus\Cake\DI\Annotations\Inject;
use WyriHaximus\React\Cake\Http\Http\PromiseResponse;
use WyriHaximus\Recoil\Call;
use WyriHaximus\Recoil\FiniteCaller;
use WyriHaximus\Recoil\QueueCallerInterface;

trait CoroutineInvokeActionTrait
{
    /** @var QueueCallerInterface */
    private $queueCaller;

    /**
     * @param LoopInterface $loop
     * @Inject()
     */
    public function setQueueCaller(LoopInterface $loop): void
    {
        $this->queueCaller = new FiniteCaller(ReactKernel::create($loop), 13);
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

        return (new PromiseResponse())->setPromise(new Promise(function ($resolve, $reject) use ($call) {
            $call->wait($resolve, $reject);
        }));
    }
}
