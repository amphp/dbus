<?php

namespace Amp\Dbus\Message;

use Amp\Dbus\Message;
use Amp\Dbus\MessageType;

class Signal extends Message
{
    public const TYPE = MessageType::SIGNAL;

    public string $path;
    public string $signal;
    public string $interface;
}
