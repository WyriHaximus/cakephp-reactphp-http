<?php

namespace WyriHaximus\React\Cake\Http\Event;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

class ConstructEvent extends Event
{
    const EVENT = 'WyriHaximus.HttpServer.construct';

    /**
     * @param LoopInterface $loop
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     * @return static
     */
    public static function create(LoopInterface $loop, EventManager $eventManager, LoggerInterface $logger)
    {
        return new static(static::EVENT, $loop, [
            'loop' => $loop,
            'eventManager' => $eventManager,
            'logger' => $logger,
        ]);
    }

    public function getLoop(): LoopInterface
    {
        return $this->data()['loop'];
    }

    public function getEventManager(): EventManager
    {
        return $this->data()['eventManager'];
    }

    public function getLogger(): LoggerInterface
    {
        return $this->data()['logger'];
    }
}
