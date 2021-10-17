<?php

require __DIR__ . "/../vendor/autoload.php";

$socket = $argv[1] ?? "";
if ($socket == "") {
    if (posix_getuid() == 0) {
        $socket = "/var/run/dbus/system_bus_socket";
    } else {
        $socket = \Amp\Dbus\default_session_bus();
    }
}
if ($socket[0] === "/") {
    $socket = "unix://$socket";
}

$dbus = new \Amp\Dbus\Dbus($socket);

$becomeMonitor = new \Amp\Dbus\Message\MethodCall;
$becomeMonitor->path = "/org/freedesktop/DBus";
$becomeMonitor->destination = "org.freedesktop.DBus";
$becomeMonitor->interface = "org.freedesktop.DBus.Monitoring";
$becomeMonitor->method = "BecomeMonitor";
$becomeMonitor->signature = "asu";
$becomeMonitor->data = [[], 0];
$dbus->sendAndWaitForReply($becomeMonitor);

while ($message = $dbus->read()) {
    echo \Amp\Dbus\format_message($message), "\n";
}
