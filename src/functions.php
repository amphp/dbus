<?php

namespace Amp\Dbus;

function default_session_bus()
{
    $bus = \getenv("DBUS_SESSION_BUS_ADDRESS");
    if ($bus != "") {
        return $bus;
    }

    $busdir = \getenv("XDG_RUNTIME_DIR");
    if ($busdir != "") {
        return "$busdir/bus";
    }

    $uid = posix_getuid();
    return "/var/run/user/$uid/bus";
}

function format_message(Message $message): string
{
    $msg = \substr(\strrchr(\get_class($message), "\\"), 1) . " #{$message->serial}" . ($message->sender !== null ? " from '{$message->sender}'" : "") . ($message->destination !== null ? " to '{$message->destination}'" : "") . ($message->handles ? " with " . \count($message->handles) . " handles" : "") . ":\n\t";
    if ($message instanceof Message\Error) {
        $msg .= "#{$message->replySerial} = {$message->error}";
        if ($message->data) {
            $msg .= ": " . \var_export($message->data, true);
        }
    } elseif ($message instanceof Message\MethodCall) {
        $msg .= "{$message->path}: ";
        if ($message->interface !== null) {
            $msg .= "{$message->interface}.";
        }
        $msg .= "{$message->method}(" . \var_export($message->data, true) . ")";
    } elseif ($message instanceof Message\Signal) {
        $msg .= "{$message->path}: {$message->interface}.{$message->signal}(" . \var_export($message->data, true) . ")";
    } elseif ($message instanceof Message\MethodReturn) {
        $msg .= "#{$message->replySerial} = " . \var_export($message->data, true);
    }
    return $msg;
}

// @see https://github.com/systemd/systemd/blob/5efbd0bf897a990ebe43d7dc69141d87c404ac9a/src/basic/bus-label.c#L10
function bus_label_escape($label)
{
    if ($label == "") {
        return "_";
    }
    $escaped = "";
    for ($i = 0; $i < \strlen($label); ++$i) {
        $c = $label[$i];
        $n = \ord($c);
        if (($n >= \ord('A') && $n <= \ord('Z')) || ($n >= \ord('a') && $n <= \ord('z')) || ($n >= \ord('0') && $n <= \ord('9') && $i)) {
            $escaped .= $c;
        } else {
            $escaped .= "_" . \bin2hex($c);
        }
    }
    return $escaped;
}
