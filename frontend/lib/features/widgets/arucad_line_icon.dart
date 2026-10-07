import 'package:flutter/material.dart';

/// Runtime counterparts of `assets/icons/arucad-line-sprite.svg` for the five
/// primary destinations. A CustomPainter keeps the reference's rounded line
/// geometry crisp on every device without downloading an icon font or API.
enum ArucadLineIconKind { home, explore, social, ask, profile }

class ArucadLineIcon extends StatelessWidget {
  final ArucadLineIconKind icon;
  final double size;
  final Color? color;
  final bool filled;

  const ArucadLineIcon({
    super.key,
    required this.icon,
    this.size = 24,
    this.color,
    this.filled = false,
  });

  @override
  Widget build(BuildContext context) {
    final resolved = color ??
        IconTheme.of(context).color ??
        Theme.of(context).colorScheme.onSurface;
    // NavigationDestination already exposes the destination label. A second
    // semantic label here made screen readers announce each tab twice.
    return ExcludeSemantics(
      child: SizedBox.square(
        dimension: size,
        child: CustomPaint(
          painter:
              _ArucadLinePainter(icon: icon, color: resolved, filled: filled),
        ),
      ),
    );
  }
}

class _ArucadLinePainter extends CustomPainter {
  final ArucadLineIconKind icon;
  final Color color;
  final bool filled;

  const _ArucadLinePainter(
      {required this.icon, required this.color, required this.filled});

  Paint get _stroke => Paint()
    ..color = color
    ..style = PaintingStyle.stroke
    ..strokeWidth = 1.75
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round;

  @override
  void paint(Canvas canvas, Size size) {
    final scale = size.width / 24;
    canvas.save();
    canvas.scale(scale, scale);
    switch (icon) {
      case ArucadLineIconKind.home:
        _home(canvas);
      case ArucadLineIconKind.explore:
        _explore(canvas);
      case ArucadLineIconKind.social:
        _social(canvas);
      case ArucadLineIconKind.ask:
        _ask(canvas);
      case ArucadLineIconKind.profile:
        _profile(canvas);
    }
    canvas.restore();
  }

  void _home(Canvas canvas) {
    // Thin Figma-style house: roof peak + open body + door cut.
    final outline = Path()
      ..moveTo(4, 11)
      ..lineTo(12, 3.5)
      ..lineTo(20, 11)
      ..moveTo(6.2, 9.8)
      ..lineTo(6.2, 20)
      ..lineTo(17.8, 20)
      ..lineTo(17.8, 9.8);
    if (filled) {
      final solid = Path()
        ..moveTo(4, 11)
        ..lineTo(12, 3.5)
        ..lineTo(20, 11)
        ..lineTo(17.8, 11)
        ..lineTo(17.8, 20)
        ..lineTo(6.2, 20)
        ..lineTo(6.2, 11)
        ..close();
      canvas.drawPath(solid, Paint()..color = color);
      canvas.drawRect(const Rect.fromLTWH(10.2, 14.2, 3.6, 5.8),
          Paint()..color = Colors.white);
    } else {
      canvas.drawPath(outline, _stroke);
      canvas.drawLine(
          const Offset(10.2, 20), const Offset(10.2, 14.2), _stroke);
      canvas.drawLine(
          const Offset(13.8, 14.2), const Offset(13.8, 20), _stroke);
    }
  }

  void _explore(Canvas canvas) {
    // Matches assets/icons/arucad-line-sprite.svg #explore.
    canvas.drawCircle(const Offset(12, 12), 9, _stroke);
    final needle = Path()
      ..moveTo(15.5, 8.5)
      ..lineTo(13.5, 13.5)
      ..lineTo(8.5, 15.5)
      ..lineTo(10.5, 10.5)
      ..close();
    canvas.drawPath(needle, _stroke);
  }

  void _social(Canvas canvas) {
    // Matches assets/icons/arucad-line-sprite.svg #social.
    canvas.drawCircle(const Offset(9, 8), 3, _stroke);
    canvas.drawCircle(const Offset(17, 9), 2.2, _stroke);
    final people = Path()
      ..moveTo(3, 20)
      ..cubicTo(3.5, 16, 5.6, 14, 9, 14)
      ..cubicTo(12.4, 14, 14.5, 16, 15, 20);
    canvas.drawPath(people, _stroke);
    final companion = Path()
      ..moveTo(15, 15)
      ..cubicTo(18, 15, 20, 16.6, 20.5, 19.5);
    canvas.drawPath(companion, _stroke);
  }

  void _ask(Canvas canvas) {
    _star(canvas, const Offset(10.2, 11.8), 7.2);
    _star(canvas, const Offset(18.1, 5.9), 3.2);
    _star(canvas, const Offset(18, 18.5), 2.5);
  }

  void _star(Canvas canvas, Offset c, double radius) {
    final path = Path()
      ..moveTo(c.dx, c.dy - radius)
      ..quadraticBezierTo(c.dx + 1, c.dy - 1, c.dx + radius, c.dy)
      ..quadraticBezierTo(c.dx + 1, c.dy + 1, c.dx, c.dy + radius)
      ..quadraticBezierTo(c.dx - 1, c.dy + 1, c.dx - radius, c.dy)
      ..quadraticBezierTo(c.dx - 1, c.dy - 1, c.dx, c.dy - radius);
    canvas.drawPath(path, _stroke);
  }

  void _profile(Canvas canvas) {
    // Matches assets/icons/arucad-line-sprite.svg #profile.
    canvas.drawCircle(const Offset(12, 8), 4, _stroke);
    final path = Path()
      ..moveTo(4, 21)
      ..cubicTo(4.8, 17, 7.5, 15, 12, 15)
      ..cubicTo(16.5, 15, 19.2, 17, 20, 21);
    canvas.drawPath(path, _stroke);
  }

  @override
  bool shouldRepaint(covariant _ArucadLinePainter oldDelegate) =>
      oldDelegate.icon != icon ||
      oldDelegate.color != color ||
      oldDelegate.filled != filled;
}
