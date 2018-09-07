<?php

namespace WyriHaximus\React\Cake\Http\Event;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;

class DestructEvent extends Event
{
    const EVENT = 'WyriHaximus.HttpServer.destruct';

    public static function create()
    {
        return new static(static::EVENT);
    }
}
