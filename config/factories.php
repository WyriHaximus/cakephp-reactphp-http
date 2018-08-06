<?php

use App\Application;
use Cake\Http\Server;

return [
    'WyriHaximus' => [
        'HttpServer' => [
            'factories' => [
                'server' => function () {
                    return new Server(new Application(CONFIG));;
                },
            ],
        ],
    ],
];
