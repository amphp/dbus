`amphp/dbus` provides a simple dbus message encoder and decoder and allows to send and receive these on a socket.

To get the basic idea of the API, check the examples folder.

The basic usage is: create a `Dbus` instance, instantiate some `Message`, fill its fields and then call `send()` or, if interested in the reply, `sendAndWaitForReply()` on it.

To read generic messages, use the `read()` method on the `Dbus` instance.

Dbus types are converted from and into the adequate native PHP type, except for variants, which are wrapped in a `Variant` object as to retain type information.

## Security

If you discover any security related issues, please email [`contact@amphp.org`](mailto:contact@amphp.org) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
