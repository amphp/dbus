<?php

namespace Amp\Dbus;

class Variant
{
    public function __construct(public string $type, public $data)
    {
    }
}
