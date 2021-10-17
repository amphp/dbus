<?php

namespace Amp\Dbus;

class Decoder
{
    public bool $littleEndian;

    private function __construct()
    {
    }

    public static function parser()
    {
        $decoder = new self;

        $buffer = "";
        $offset = 0;

        while (true) {
            while (\strlen($buffer) < 12) {
                $buffer .= yield;
            }

            $decoder->littleEndian = $buffer[$offset++] === "l";
            $type = MessageType::tryFrom(\ord($buffer[$offset++]));
            $flags = $buffer[$offset++];
            $protocolVersion = $buffer[$offset++];

            switch ($type) {
                case MessageType::METHOD_CALL:
                    $message = new Message\MethodCall;
                    if ($flags & "\x1") {
                        $message->replyExpected = false;
                    }
                    break;
                case MessageType::METHOD_RETURN:
                    $message = new Message\MethodReturn;
                    break;
                case MessageType::SIGNAL:
                    $message = new Message\Signal;
                    break;
                default: // handle null with error
                case MessageType::ERROR:
                    $message = new Message\Error;
                    $message->error = "Protocol.Error.InvalidType";
                    break;
            }

            if ($flags & "\x2") {
                $message->auto_start = false;
            }

            $messageLength = $decoder->consumeType("u", $buffer, $offset);
            $message->serial = $decoder->consumeType("u", $buffer, $offset);

            while (null === $rawHeaders = $decoder->consumeType("a(yv)", $buffer, $offset)) {
                $buffer .= yield;
            }

            $headers = [];
            foreach ($rawHeaders as [$header_id, $header]) {
                /** @var Variant $header */
                $headers[$header_id] = $header->data;
            }

            $message->destination = $headers[Header::DESTINATION->value] ?? null;
            $message->sender = $headers[Header::SENDER->value] ?? null;
            $message->signature = $headers[Header::SIGNATURE->value] ?? "";
            if ($fds = $headers[Header::UNIX_FDS->value] ?? 0) {
                $message->handles = \range(0, $fds - 1);
            }

            switch ($type) {
                case MessageType::METHOD_CALL:
                    $message->method = $headers[Header::MEMBER->value] ?? "";
                    $message->path = $headers[Header::PATH->value] ?? "";
                    $message->interface = $headers[Header::INTERFACE->value] ?? null;
                    break;
                case MessageType::METHOD_RETURN:
                    $message->replySerial = $headers[Header::REPLY_SERIAL->value] ?? 0;
                    break;
                case MessageType::SIGNAL:
                    $message->signal = $headers[Header::MEMBER->value] ?? "";
                    $message->path = $headers[Header::PATH->value] ?? "";
                    $message->interface = $headers[Header::INTERFACE->value] ?? "";
                    break;
                case MessageType::ERROR:
                    $message->replySerial = $headers[Header::REPLY_SERIAL->value] ?? 0;
                    $message->error = $headers[Header::ERROR_NAME->value] ?? "";
                    break;
            }

            // body is 8 byte aligned
            $offset = ($offset + 7) & ~7;

            while (\strlen($buffer) < $offset + $messageLength) {
                $buffer .= yield;
            }

            $body = \substr($buffer, $offset, $messageLength);
            $message->data = $decoder->consumeType("({$message->signature})", $body);

            yield $message;

            $buffer = \substr($buffer, $offset + $messageLength);
            $offset = 0;
        }
    }

    public function consumeType(string $type, string $buffer, int &$offset = 0, int &$typeOffset = 0)
    {
        $originalOffset = $offset;

        if (\strlen($type) <= $typeOffset || \strlen($buffer) <= $offset) {
            not_enough_data:
            $offset = $originalOffset;
            if ($type === "()") {
                return [];
            }
            print new \Exception;
            return null;
        }

        switch ($type[$typeOffset++]) {
            case "y": // byte
                return \ord($buffer[$offset++]);
            case "b": // bool
                $offset = ($offset + 3) & ~3;
                if (\strlen($buffer) < $offset + 4) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "V" : "N", $buffer, $offset)[1];
                $offset += 4;
                return (bool) $val;
            case "n": // int16
                $offset += $offset & 1;
                if (\strlen($buffer) < $offset + 2) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "v" : "n", $buffer, $offset)[1];
                $offset += 2;
                return $val >= (1 << 15) ? $val - (1 << 16) : $val;
            case "q": // uint16
                $offset += $offset & 1;
                if (\strlen($buffer) < $offset + 2) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "v" : "n", $buffer, $offset)[1];
                $offset += 2;
                return $val;
            case "i": // int32
                $offset = ($offset + 3) & ~3;
                if (\strlen($buffer) < $offset + 4) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "V" : "N", $buffer, $offset)[1];
                $offset += 4;
                return $val >= (1 << 31) ? $val - (1 << 32) : $val;
            case "u": // uint32
            case "h": // handle
                $offset = ($offset + 3) & ~3;
                if (\strlen($buffer) < $offset + 4) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "V" : "N", $buffer, $offset)[1];
                $offset += 4;
                return $val;
            case "x": // int64
            case "t": // uint64
                $offset = ($offset + 7) & ~7;
                if (\strlen($buffer) < $offset + 8) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "P" : "J", $buffer, $offset)[1];
                $offset += 8;
                return $val;
            case "d": // double
                $offset = ($offset + 7) & ~7;
                if (\strlen($buffer) < $offset + 8) {
                    goto not_enough_data;
                }
                $val = \unpack($this->littleEndian ? "e" : "E", $buffer, $offset)[1];
                $offset += 8;
                return $val;
            case "s": // string
            case "o": // object path
                $offset = ($offset + 3) & ~3;
                if (\strlen($buffer) < $offset + 4) {
                    goto not_enough_data;
                }
                $len = \unpack($this->littleEndian ? "V" : "N", $buffer, $offset)[1];
                if (\strlen($buffer) < $offset + 4 + $len) {
                    goto not_enough_data;
                }
                $val = \substr($buffer, $offset + 4, $len);
                $offset += $len + 5;
                return $val;
            case "g": // signature
                $len = \ord($buffer[$offset++]);
                $val = \substr($buffer, $offset, $len);
                $offset += $len + 1;
                return $val;
            case "a": // array
                $offset = ($offset + 3) & ~3;
                if (\strlen($buffer) < $offset + 4) {
                    goto not_enough_data;
                }
                $len = \unpack($this->littleEndian ? "V" : "N", $buffer, $offset)[1];
                $offset += 4;
                $endOffset = $offset + $len;
                $data = [];
                if ($type[$typeOffset] === "{") {
                    $originalTypeOffset = ++$typeOffset;
                    if ($len) {
                        while ($offset < $endOffset) {
                            $typeOffset = $originalTypeOffset;
                            $offset = ($offset + 7) & ~7;
                            $key = $this->consumeType($type, $buffer, $offset, $typeOffset);
                            $val = $this->consumeType($type, $buffer, $offset, $typeOffset);
                            if ($key === null || $val === null) {
                                goto not_enough_data;
                            }
                            $data[$key] = $val;
                        }
                    } else {
                        $dummyOffset = 0;
                        $this->consumeType($type, \str_repeat("\0", \strlen($type) * 8), $dummyOffset, $typeOffset);
                    }
                    ++$typeOffset;
                } else {
                    $originalTypeOffset = $typeOffset;
                    if ($len) {
                        while ($offset < $endOffset) {
                            $typeOffset = $originalTypeOffset;
                            $val = $this->consumeType($type, $buffer, $offset, $typeOffset);
                            if ($val === null) {
                                goto not_enough_data;
                            }
                            $data[] = $val;
                        }
                    } else {
                        $dummyOffset = 0;
                        $this->consumeType($type, \str_repeat("\0", \strlen($type) * 8), $dummyOffset, $typeOffset);
                    }
                }
                return $data;
            case "(": // struct
                $offset = ($offset + 7) & ~7;
                $data = [];
                while ($typeOffset < \strlen($type) && $type[$typeOffset] !== ")") {
                    $val = $this->consumeType($type, $buffer, $offset, $typeOffset);
                    if ($val === null) {
                        goto not_enough_data;
                    }
                    $data[] = $val;
                }
                ++$typeOffset;
                return $data;
            case "v": // variant
                $len = \ord($buffer[$offset++]);
                if (\strlen($buffer) < $offset + $len) {
                    goto not_enough_data;
                }
                $type = \substr($buffer, $offset, $len);
                $offset += $len + 1;
                $variantTypeOffset = 0;
                $val = $this->consumeType($type, $buffer, $offset, $variantTypeOffset);
                if ($val === null) {
                    goto not_enough_data;
                }
                $variantType = \substr($type, 0, $variantTypeOffset);
                return new Variant($variantType, $val);
        }

        return "";
    }
}
