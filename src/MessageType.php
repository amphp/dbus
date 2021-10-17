<?php

namespace Amp\Dbus;

enum MessageType: int
{
    case METHOD_CALL = 1;
    case METHOD_RETURN = 2;
    case ERROR = 3;
    case SIGNAL = 4;
}
