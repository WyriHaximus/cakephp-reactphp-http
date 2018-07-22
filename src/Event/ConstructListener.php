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

use Cake\Core\Configure;
use Cake\Event\EventListenerInterface;
use Cake\Event\EventManager;
use React\EventLoop\LoopInterface;
use Thruway\Authentication\AuthenticationManager;
use Thruway\Peer\Router;
use Thruway\Transport\RatchetTransportProvider;
use WyriHaximus\Ratchet\Security\AuthorizationManager;
use WyriHaximus\Ratchet\Security\JWTAuthProvider;
use WyriHaximus\Ratchet\Security\WampCraAuthProvider;
use WyriHaximus\Ratchet\Websocket\InternalClient;

final class ConstructListener implements EventListenerInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;

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
    }
}