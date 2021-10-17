<?php

namespace Amp\Dbus\Message;

use Amp\Dbus\Message;
use Amp\Dbus\MessageType;

class Error extends Message
{
    public const TYPE = MessageType::ERROR;

    public int $replySerial;
    public string $error;
}
