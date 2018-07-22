<?php

namespace WyriHaximus\React\Cake\Http\Shell;

use Cake\Console\Shell;
use Cake\Event\EventManager;
use React\EventLoop\LoopInterface;
use WyriHaximus\React\Cake\Http\Event\ConstructEvent;

class HttpShell extends Shell
{
    /**
     * @var LoopInterface
     */
    protected $loop;

    public function serv()
    {
        $this->loop = \WyriHaximus\React\Cake\Http\loopResolver();
        EventManager::instance()->dispatch(ConstructEvent::create($this->loop, EventManager::instance()));
        $this->loop->run();
    }

    /**
     * Set options for this console
     *
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function getOptionParser()
    {
        return parent::getOptionParser()->addSubcommand(
            'start',
            [
                'help' => __('Starts and runs both the websocket service')
            ]
        )->description(__('Ratchet Websocket service.'))->addOption(
            'verbose',
            [
                'help' => 'Enable verbose output',
                'short' => 'v',
                'boolean' => true
            ]
        );
    }
}
