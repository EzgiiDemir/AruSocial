import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// A published legal document, rendered.
///
/// Shares its look with the consent screen that links to it — the ARUCAD
/// wordmark at the top, the same white ground, the same type — because a
/// student tapping "Privacy Policy" before signing in should not feel like
/// they have been dropped into a different application.
///
/// The back button is an ordinary [AppBar] leading, so the system back
/// gesture and the on-screen control do the same thing and neither strands
/// anyone on the document.
class LegalDocumentScreen extends StatelessWidget {
  const LegalDocumentScreen({
    super.key,
    required this.title,
    required this.assetPath,
    this.publicUrl,
    this.language,
  });

  final String title;

  /// The Turkish document, e.g. `assets/legal/privacy.md`. Translations sit
  /// beside it as `privacy.en.md` / `privacy.ru.md`.
  final String assetPath;

  final String? publicUrl;

  /// Overrides the in-app language.
  ///
  /// The consent screen runs before anyone has signed in or picked a
  /// language, so it reads the device locale and passes the answer down —
  /// otherwise a document opened from there would arrive in a different
  /// language from the screen that linked to it.
  final AppLanguage? language;

  /// The document in [language], falling back to the Turkish original.
  ///
  /// Turkish is the fallback rather than English on purpose: it is the
  /// original, it is the version legal review approves, and every
  /// translation says so in its own header. A missing translation must
  /// show the governing text, never an empty page.
  static Future<String> loadFor(
    String assetPath,
    AppLanguage language, {
    AssetBundle? bundle,
  }) async {
    final source = bundle ?? rootBundle;

    if (language != AppLanguage.tr) {
      final dot = assetPath.lastIndexOf('.');
      final tag = language.name; // 'en' | 'ru'
      final localised =
          '${assetPath.substring(0, dot)}.$tag${assetPath.substring(dot)}';
      try {
        return await source.loadString(localised);
      } on FlutterError {
        // Not translated yet.
      }
    }

    return source.loadString(assetPath);
  }

  @override
  Widget build(BuildContext context) {
    final resolved = language ?? AppLocale.languageOf(context);

    return Scaffold(
      backgroundColor: Colors.white,
      appBar: AppBar(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: true,
        // The wordmark rather than the document name: the title is already
        // the first thing in the body, and repeating it in the bar wastes
        // the one place the student can confirm which app they are in.
        title: Image.asset(
          'assets/images/arucad_home_logo.png',
          height: 26,
          filterQuality: FilterQuality.high,
        ),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: ArucadColors.ink),
          tooltip: MaterialLocalizations.of(context).backButtonTooltip,
          onPressed: () => Navigator.of(context).maybePop(),
        ),
      ),
      body: FutureBuilder<String>(
        future: loadFor(assetPath, resolved),
        builder: (context, snap) {
          if (!snap.hasData) {
            return const Center(child: CircularProgressIndicator());
          }

          return Align(
            alignment: Alignment.topCenter,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 640),
              child: ListView(
                padding: const EdgeInsets.fromLTRB(24, 8, 24, 48),
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      fontSize: 28,
                      height: 1.15,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -0.5,
                      color: ArucadColors.ink,
                    ),
                  ),
                  if (publicUrl != null && publicUrl!.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Text(
                        '${AppStrings(resolved).t('legal_public_url')}: $publicUrl',
                        style: const TextStyle(
                          color: ArucadColors.muted,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  const SizedBox(height: 20),
                  ...LegalMarkdown.render(snap.data!),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// The small slice of Markdown the legal documents actually use.
///
/// Deliberately not a Markdown package: these files use headings, bullets,
/// bold and paragraphs, and pulling in a parser to cover the rest of the
/// syntax would be more dependency than the job needs. The previous version
/// rendered the raw file as one unbroken block of text, headings and
/// asterisks included, which is what a student read before agreeing to it.
class LegalMarkdown {
  const LegalMarkdown._();

  static const _ink = ArucadColors.ink;
  static const _body = Color(0xFF3F4754);

  static List<Widget> render(String markdown) {
    final widgets = <Widget>[];
    final paragraph = <String>[];

    void flush() {
      if (paragraph.isEmpty) return;
      widgets.add(Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: _rich(paragraph.join(' '), 15, 1.55, _body),
      ));
      paragraph.clear();
    }

    for (final raw in markdown.split(RegExp(r'\r?\n'))) {
      final line = raw.trim();

      if (line.isEmpty) {
        flush();
        continue;
      }

      // The file's own `# Title` duplicates the screen heading.
      if (line.startsWith('# ')) {
        flush();
        continue;
      }

      if (line.startsWith('### ')) {
        flush();
        widgets.add(Padding(
          padding: const EdgeInsets.only(top: 12, bottom: 8),
          child: _rich(line.substring(4), 16, 1.3, _ink, FontWeight.w800),
        ));
        continue;
      }

      if (line.startsWith('## ')) {
        flush();
        widgets.add(Padding(
          padding: const EdgeInsets.only(top: 20, bottom: 10),
          child: _rich(line.substring(3), 19, 1.25, _ink, FontWeight.w900),
        ));
        continue;
      }

      if (line.startsWith('- ')) {
        flush();
        widgets.add(Padding(
          padding: const EdgeInsets.only(left: 4, bottom: 8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Padding(
                padding: EdgeInsets.only(top: 7, right: 12),
                child: _Bullet(),
              ),
              Expanded(child: _rich(line.substring(2), 15, 1.5, _body)),
            ],
          ),
        ));
        continue;
      }

      paragraph.add(line);
    }

    flush();

    return widgets;
  }

  /// Renders `**bold**` inline and leaves everything else as written.
  static Widget _rich(
    String text,
    double size,
    double height,
    Color color, [
    FontWeight weight = FontWeight.w400,
  ]) {
    final base = TextStyle(
      fontSize: size,
      height: height,
      color: color,
      fontWeight: weight,
    );

    final spans = <TextSpan>[];
    var rest = text;

    while (true) {
      final open = rest.indexOf('**');
      if (open < 0) break;
      final close = rest.indexOf('**', open + 2);
      if (close < 0) break;

      if (open > 0) spans.add(TextSpan(text: rest.substring(0, open)));
      spans.add(TextSpan(
        text: rest.substring(open + 2, close),
        style: const TextStyle(fontWeight: FontWeight.w800),
      ));
      rest = rest.substring(close + 2);
    }

    if (rest.isNotEmpty) spans.add(TextSpan(text: rest));

    return Text.rich(TextSpan(style: base, children: spans));
  }
}

class _Bullet extends StatelessWidget {
  const _Bullet();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 5,
      height: 5,
      decoration: const BoxDecoration(
        color: Color(0xFF9AA2B1),
        shape: BoxShape.circle,
      ),
    );
  }
}
