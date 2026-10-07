import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Text whose links and e-mail addresses are actually tappable.
///
/// AICAD cites its sources, so nearly every grounded answer ends with a URL.
/// Rendered as plain text those were dead strings: a student could read the
/// address but not open it, and on a phone there is no practical way to copy
/// one out of a chat bubble. Anything that looks like a destination is now
/// styled as a link and opens on tap.
///
/// Recognisers are owned by the state and disposed with it — building them
/// inline in `build` leaks a recogniser on every rebuild.
class LinkifiedText extends StatefulWidget {
  const LinkifiedText(
    this.text, {
    super.key,
    this.style,
    this.linkColor,
  });

  final String text;
  final TextStyle? style;

  /// Defaults to the theme's primary colour; pass a lighter one when the text
  /// sits on a dark bubble.
  final Color? linkColor;

  @override
  State<LinkifiedText> createState() => _LinkifiedTextState();
}

class _LinkifiedTextState extends State<LinkifiedText> {
  /// http(s) links, bare www. links, and e-mail addresses.
  static final _pattern = RegExp(
    r'(https?://[^\s<>"]+)|(www\.[^\s<>"]+)|([\w.+-]+@[\w-]+\.[\w.-]+)',
    caseSensitive: false,
  );

  /// A sentence usually ends right after a citation — "(Kaynak: https://x/)."
  /// — and those characters are punctuation, not part of the address.
  static const _trailing = '.,;:!?)]}\'"';

  final List<TapGestureRecognizer> _recognizers = [];

  @override
  void dispose() {
    for (final recognizer in _recognizers) {
      recognizer.dispose();
    }
    super.dispose();
  }

  Future<void> _open(String token) async {
    final uri = token.contains('@') && !token.startsWith('http')
        ? Uri(scheme: 'mailto', path: token)
        : Uri.parse(token.startsWith('http') ? token : 'https://$token');
    try {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      if (!mounted) return;
      // A dead or malformed link must not take the chat down with it.
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Bağlantı açılamadı.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    for (final recognizer in _recognizers) {
      recognizer.dispose();
    }
    _recognizers.clear();

    final baseStyle = widget.style ?? DefaultTextStyle.of(context).style;
    final linkStyle = baseStyle.copyWith(
      color: widget.linkColor ?? Theme.of(context).colorScheme.primary,
      decoration: TextDecoration.underline,
      decorationColor: widget.linkColor ?? Theme.of(context).colorScheme.primary,
      fontWeight: FontWeight.w600,
    );

    final spans = <InlineSpan>[];
    var index = 0;

    for (final match in _pattern.allMatches(widget.text)) {
      if (match.start > index) {
        spans.add(TextSpan(text: widget.text.substring(index, match.start)));
      }

      var token = match[0]!;
      var tail = '';
      while (token.isNotEmpty && _trailing.contains(token.characters.last)) {
        tail = token.characters.last + tail;
        token = token.substring(0, token.length - 1);
      }

      if (token.isEmpty) {
        spans.add(TextSpan(text: match[0]));
      } else {
        final recognizer = TapGestureRecognizer()..onTap = () => _open(token);
        _recognizers.add(recognizer);
        spans.add(TextSpan(
          text: token,
          style: linkStyle,
          recognizer: recognizer,
        ));
        if (tail.isNotEmpty) spans.add(TextSpan(text: tail));
      }

      index = match.end;
    }

    if (index < widget.text.length) {
      spans.add(TextSpan(text: widget.text.substring(index)));
    }

    return Text.rich(TextSpan(style: baseStyle, children: spans));
  }
}
