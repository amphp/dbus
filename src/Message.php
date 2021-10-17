<?php

namespace Amp\Dbus;

class Message
{
    public int $serial;
    public bool $auto_start = true;
    public ?string $destination = null;
    public ?string $sender = null;
    public string $signature = "";
    public array $handles = [];
    public array $data = [];
}
