<?php

namespace Amp\Dbus;

enum Header: int
{
    case PATH = 1;
    case INTERFACE = 2;
    case MEMBER = 3;
    case ERROR_NAME = 4;
    case REPLY_SERIAL = 5;
    case DESTINATION = 6;
    case SENDER = 7;
    case SIGNATURE = 8;
    case UNIX_FDS = 9;
}
