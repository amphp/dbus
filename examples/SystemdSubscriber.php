<?php

require __DIR__ . "/../vendor/autoload.php";

$options = \getopt("p:u:");

$socket = $options["p"] ?? "";
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

$subscribe = new \Amp\Dbus\Message\MethodCall;
$subscribe->path = "/org/freedesktop/systemd1";
$subscribe->destination = "org.freedesktop.systemd1";
$subscribe->interface = "org.freedesktop.systemd1.Manager";
$subscribe->method = "Subscribe";
$dbus->sendAndWaitForReply($subscribe);

$monitorDbus = new \Amp\Dbus\Dbus($socket);

$monitoredPath = "/org/freedesktop/systemd1";
if (isset($options["u"])) {
    $monitoredPath .= "/unit/" . \Amp\Dbus\bus_label_escape($options["u"]);
}

$becomeMonitor = new \Amp\Dbus\Message\MethodCall;
$becomeMonitor->path = "/org/freedesktop/DBus";
$becomeMonitor->destination = "org.freedesktop.DBus";
$becomeMonitor->interface = "org.freedesktop.DBus.Monitoring";
$becomeMonitor->method = "BecomeMonitor";
$becomeMonitor->signature = "asu";
$becomeMonitor->data = [["eavesdrop=true,type='signal',path='$monitoredPath'"], 0];
$monitorDbus->sendAndWaitForReply($becomeMonitor);

while ($message = $monitorDbus->read()) {
    if ($message instanceof \Amp\Dbus\Message\Signal && $message->path === $monitoredPath) {
        echo \Amp\Dbus\format_message($message), "\n";
    }
}
