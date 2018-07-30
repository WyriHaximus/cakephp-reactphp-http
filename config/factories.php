<?php

use App\Application;

return [
    'WyriHaximus' => [
        'HttpServer' => [
            'factories' => [
                'application' => function () {
                    return new Application(CONFIG);
                },
            ],
        ],
    ],
];
