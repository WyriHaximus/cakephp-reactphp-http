<?php

use Cake\Core\Configure;
use Cake\Event\EventManager;
use WyriHaximus\React\Cake\Http\Event\ConstructListener;

EventManager::instance()->on(new ConstructListener());


Configure::load('WyriHaximus/React/Cake/Http.factories', 'default');
