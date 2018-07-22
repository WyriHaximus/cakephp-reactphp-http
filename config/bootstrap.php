<?php

use Cake\Event\EventManager;
use WyriHaximus\React\Cake\Http\Event\ConstructListener;

EventManager::instance()->on(new ConstructListener());
