/// Camera capture that works on the web.
///
/// On Android and iOS `image_picker` opens the real camera app and this is
/// not used. On the web it is: `image_picker` there is a file input with a
/// `capture` attribute, which a desktop browser ignores completely, so
/// "Kameradan çek" only ever opened a file dialog on a PC.
library;

export 'web_camera_stub.dart'
    if (dart.library.js_interop) 'web_camera_web.dart';
