<?php

namespace WyriHaximus\React\Cake\Http;

use Cake\Core\Configure;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

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
