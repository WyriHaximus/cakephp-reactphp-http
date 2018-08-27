<?php

namespace WyriHaximus\React\Cake\Http\Http;

use Cake\Http\Response;
use React\Promise\PromiseInterface;

final class PromiseResponse extends Response
{
    /** @var PromiseInterface */
    private $promise;

    public function getPromise(): PromiseInterface
    {
        return $this->promise;
    }

    public function setPromise(PromiseInterface $promise): self
    {
        $this->promise = $promise;

        return $this;
    }
}
