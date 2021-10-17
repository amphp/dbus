<?php

namespace Amp\Dbus\Message;

use Amp\Dbus\Message;
use Amp\Dbus\MessageType;

class MethodReturn extends Message
{
    public const TYPE = MessageType::METHOD_RETURN;

    public int $replySerial;
}
