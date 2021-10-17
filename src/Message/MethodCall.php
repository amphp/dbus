<?php

namespace Amp\Dbus\Message;

use Amp\Dbus\Message;
use Amp\Dbus\MessageType;

class MethodCall extends Message
{
    public const TYPE = MessageType::METHOD_CALL;

    public bool $replyExpected = true;
    public string $path;
    public string $method;
    public ?string $interface = null;
}
