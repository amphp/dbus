<?php

namespace Amp\Dbus;

use Amp\Dbus\Message\Error;
use Amp\Dbus\Message\MethodCall;
use Amp\Dbus\Message\MethodReturn;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Socket;
use function Amp\async;
use function Amp\Socket\connect;

class Dbus
{
    public readonly Socket $socket;
    private \Generator $parser;
    private string $connectionName;
    private Future $connectionReady;
    private bool $supportsFileDescriptors = false;
    private array $messageBuffer = [];
    private array $pendingReplyDeferred = [];
    private array $pendingReadDeferred = [];
    private ?Future $backgroundReader = null;

    public bool $bufferMessages = true;

    public function __construct(string|Socket $socket, ?int $asUser = null)
    {
        $this->parser = Decoder::parser();

        $this->connectionReady = async(function () use ($socket, $asUser) {
            if (is_string($socket)) {
                $socket = connect($socket);
            }
            $this->socket = $socket;

            $socket->write("\0");
            $socket->write("AUTH EXTERNAL " . bin2hex($asUser ?? posix_getuid()) . "\r\n");

            $reply = $socket->read();
            while (!str_ends_with($reply, "\r\n")) {
                $reply .= $socket->read();
            }
            if (!str_starts_with($reply, "OK ")) {
                throw new \Exception("Could not establish connection, got reply: $reply");
            }

            if ($socket instanceof ResourceSocket && \extension_loaded("sockets")) {
                $socket->write("NEGOTIATE_UNIX_FD\r\n");
                $reply = $socket->read();
                while (!str_ends_with($reply, "\r\n")) {
                    $reply .= $socket->read();
                }
                $this->supportsFileDescriptors = $reply === "AGREE_UNIX_FD\r\n";
            }

            $socket->write("BEGIN\r\n");

            $hello = new Message\MethodCall;
            $hello->path = "/org/freedesktop/DBus";
            $hello->destination = "org.freedesktop.DBus";
            $hello->interface = "org.freedesktop.DBus";
            $hello->method = "Hello";
            $this->sendMessageInternal($hello);
            $reply = $this->readMessageInternal();
            $this->connectionName = $reply->data[0];

            $this->readMessageInternal(); // skip the signal
        });
    }

    private function readMessageInternal(): ?Message
    {
        $chunk = null;
        do {
            /** @var ?Message $message */
            $message = $this->parser->send($chunk);
            if ($message) {
                if ($message->handles && $this->supportsFileDescriptors) {
                    $socket = socket_import_stream($this->socket->getResource());
                    foreach ($message->handles as &$handle) {
                        $data = ["controllen" => \socket_cmsg_space(\SOL_SOCKET, \SCM_RIGHTS) + 4];
                        \socket_recvmsg($socket, $data);
                        $imported_fd = $data["control"][0]["data"][0] ?? null;
                        if ($imported_fd instanceof \Socket) {
                            $handle = \socket_export_stream($imported_fd);
                        }
                    }
                }

                return $message;
            }
        } while (null !== $chunk = $this->socket->read());
        return null;
    }

    private function sendMessageInternal(Message $message): void
    {
        $raw = Encoder::pack($message);
        if ($message->handles && $this->supportsFileDescriptors) {
            $socket = socket_import_stream($this->socket->getResource());
            socket_sendmsg($socket, ["iov" => [Encoder::pack($message)], "control" => [array_map(fn($fd) => ["level" => \SOL_SOCKET, "type" => \SCM_RIGHTS, "data" => [$fd]], $message->handles)]], MSG_NOSIGNAL);
        } else {
            $this->socket->write($raw);
        }
    }

    public function read(): ?Message
    {
        $this->connectionReady->await();

        if ($this->messageBuffer) {
            $key = array_key_first($this->messageBuffer);
            $msg = $this->messageBuffer[$key];
            unset($this->messageBuffer[$key]);
            return $msg;
        }
        $future = ($this->pendingReadDeferred[] = new DeferredFuture)->getFuture();
        if (!$this->backgroundReader) {
            $this->backgroundReader = async($this->backgroundReader(...));
        }
        return $future->await();
    }

    private function backgroundReader(): void
    {
        while (($this->pendingReplyDeferred || $this->pendingReadDeferred) && $msg = $this->readMessageInternal()) {
            if (($msg instanceof MethodReturn || $msg instanceof Error) && isset($this->pendingReplyDeferred[$msg->replySerial])) {
                $this->pendingReplyDeferred[$msg->replySerial]->complete($msg);
                unset($this->pendingReplyDeferred[$msg->replySerial]);
            } elseif ($this->pendingReadDeferred) {
                $key = array_key_first($this->pendingReadDeferred);
                $deferred = $this->pendingReadDeferred[$key];
                unset($this->pendingReadDeferred[$key]);
                $deferred->complete($msg);
            } elseif ($this->bufferMessages) {
                $this->messageBuffer[] = $msg;
            }
        }

        $this->backgroundReader = null;

        $remaining = array_merge($this->pendingReplyDeferred, $this->pendingReadDeferred);
        $this->pendingReadDeferred = $this->pendingReplyDeferred = [];
        foreach ($remaining as $deferred) {
            $deferred->complete(null);
        }
    }

    public function send(Message $message): void
    {
        $this->connectionReady->await();
        $this->sendMessageInternal($message);
    }

    public function sendAndWaitForReply(Message $message): ?Message
    {
        $this->connectionReady->await();
        if (!($message instanceof MethodCall) || !$message->replyExpected) {
            throw new \Error("Cannot wait for non-MethodCall or message without expected reply");
        }
        if ($message->sender === null) {
            $message->sender = $this->connectionName;
        }
        $this->sendMessageInternal($message);
        $future = ($this->pendingReplyDeferred[$message->serial] = new DeferredFuture)->getFuture();
        if (!$this->backgroundReader) {
            $this->backgroundReader = async($this->backgroundReader(...));
        }
        return $future->await();
    }
}
