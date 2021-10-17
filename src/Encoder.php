<?php

namespace Amp\Dbus;

class Encoder
{
    private static $serial = 1 << 30;

    public static function pack(Message $message): string
    {
        if (!isset($message->serial)) {
            $message->serial = self::$serial++;
        }
        $flags = (!$message->auto_start) * 0x2;
        if ($message instanceof Message\MethodCall) {
            $flags |= (!$message->replyExpected) * 0x1;
        }

        $headers = [];
        if ($message->signature != "") {
            $headers[] = [Header::SIGNATURE->value, new Variant("g", $message->signature)];
        }
        if (isset($message->sender)) {
            $headers[] = [Header::SENDER->value, new Variant("s", $message->sender)];
        }
        if (isset($message->destination)) {
            $headers[] = [Header::DESTINATION->value, new Variant("s", $message->destination)];
        }
        if ($message->handles) {
            $headers[] = [Header::UNIX_FDS->value, new Variant("u", \count($message->handles))];
        }
        if ($message instanceof Message\Error) {
            $headers[] = [Header::REPLY_SERIAL->value, new Variant("u", $message->replySerial)];
            $headers[] = [Header::ERROR_NAME->value, new Variant("s", $message->error)];
        } elseif ($message instanceof Message\MethodCall) {
            $headers[] = [Header::PATH->value, new Variant("o", $message->path)];
            if (isset($message->interface)) {
                $headers[] = [Header::INTERFACE->value, new Variant("s", $message->interface)];
            }
            $headers[] = [Header::MEMBER->value, new Variant("s", $message->method)];
        } elseif ($message instanceof Message\Signal) {
            $headers[] = [Header::PATH->value, new Variant("o", $message->path)];
            $headers[] = [Header::INTERFACE->value, new Variant("s", $message->interface)];
            $headers[] = [Header::MEMBER->value, new Variant("s", $message->signal)];
        } elseif ($message instanceof Message\MethodReturn) {
            $headers[] = [Header::REPLY_SERIAL->value, new Variant("u", $message->replySerial)];
        }


        $body = $message->data == "" ? "" : self::encodeWithSignature("({$message->signature})", $message->data);

        // l = little endian
        $msg = "l" . \pack("CCCVV", $message::TYPE->value, $flags, 1, \strlen($body), $message->serial);
        $alignment = \strlen($msg);
        $msg .= self::encodeWithSignature("a(yv)", $headers, $alignment);
        return \str_pad($msg, (\strlen($msg) + 7) & ~7, "\0") . $body;
    }

    private static function packPadded(string $fmt, $data, int $alignment, int $offset)
    {
        $padding = ($alignment - $offset) & ($alignment - 1);
        return \pack("@$padding$fmt", $data);
    }

    private static function encodeWithSignature(string $signature, $data, int $offset = 0, int &$signatureOffset = 0): string
    {
        switch ($signature[$signatureOffset++]) {
            case "y": // byte
                return \chr($data ?? "\0");
            case "b": // bool
                return self::packPadded("V", (int) (bool) $data, 4, $offset);
            case "n": // int16
            case "q": // uint16
                return self::packPadded("v", $data, 2, $offset);
            case "i": // int32
            case "u": // uint32
            case "h": // handle
                return self::packPadded("V", $data, 4, $offset);
            case "x": // int64
            case "t": // uint64
                return self::packPadded("P", $data, 8, $offset);
            case "d": // double
                return self::packPadded("e", $data, 8, $offset);
            case "s": // string
            case "o": // object path
                return self::packPadded("V", \strlen($data), 4, $offset) . "$data\0";
            case "g": // signature
                return \chr(\strlen($data ?? "")) . "$data\0";
            case "a": // array
                $initialOffset = $offset;
                $offset += 4;
                $msg = "";

                if ($signature[$signatureOffset] === "{") {
                    $originalSignatureOffset = ++$signatureOffset;
                    if ($data) {
                        foreach ($data as $key => $val) {
                            $signatureOffset = $originalSignatureOffset;
                            $padding = (8 - ($offset + \strlen($msg))) & 7;
                            $msg .= \str_repeat("\0", $padding);
                            $msg .= self::encodeWithSignature($signature, $key, $offset + \strlen($msg), $signatureOffset);
                            $msg .= self::encodeWithSignature($signature, $val, $offset + \strlen($msg), $signatureOffset);
                        }
                    } else {
                        self::encodeWithSignature($signature, null, 0, $signatureOffset);
                        self::encodeWithSignature($signature, null, 0, $signatureOffset);
                    }
                    // assert $signature[$signatureOffset] == "}"
                    ++$signatureOffset;
                } else {
                    if ($data) {
                        $originalSignatureOffset = $signatureOffset;
                        foreach ($data as $val) {
                            $signatureOffset = $originalSignatureOffset;
                            $msg .= self::encodeWithSignature($signature, $val, $offset + \strlen($msg), $signatureOffset);
                        }
                    } else {
                        self::encodeWithSignature($signature, null, 0, $signatureOffset);
                    }
                }

                return self::packPadded("V", \strlen($msg), 4, $initialOffset) . $msg;
            case "(": // struct
                $padding = (8 - $offset) & 7;
                $msg = \str_repeat("\0", $padding);
                foreach ($data as $val) {
                    $msg .= self::encodeWithSignature($signature, $val, $offset + \strlen($msg), $signatureOffset);
                }
                // assert $signature[$signatureOffset] == ")"
                ++$signatureOffset;
                return $msg;
            case "v": // variant
                if ($data instanceof Variant) {
                    $msg = self::encodeWithSignature("g", $data->type, $offset);
                    $msg .= self::encodeWithSignature($data->type, $data->data, $offset + \strlen($msg));
                    return $msg;
                }
                // fail instead?
                return self::encodeWithSignature("g", "()", $offset);
        }

        // fail if invalid?
        return "";
    }
}
