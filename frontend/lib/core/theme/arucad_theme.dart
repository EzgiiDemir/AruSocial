import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// ARUCAD Social Life palette: mature, calm, premium — charcoal, graphite,
/// navy and a deep saturated blue carry the brand instead of the earlier
/// red/yellow scheme. `blue` is a brighter secondary accent (info, routes,
/// events) kept visually distinct from the deeper `primary`; `warning`
/// covers the old decorative "yellow" uses (ratings, weather, moderate
/// density) since those were always status signals, not brand color.
class ArucadColors {
  static const primary = Color(0xFF2B4C7E);
  static const navy = Color(0xFF141B2E);
  static const slate = Color(0xFF5B6472);
  static const blue = Color(0xFF3B7DD8);
  static const ink = Color(0xFF1C1E22);
  static const paper = Color(0xFFF6F7F9);
  static const mist = Color(0xFFE9EBEF);
  static const muted = Color(0xFF6B7280);
  static const success = Color(0xFF2F9C55);
  static const warning = Color(0xFFD99721);
  static const danger = Color(0xFFBC4050);

  /// Score-level ramp, lowest rung — a neutral graphite, distinct from
  /// both `ink` (body text) and `slate` (level 2), so "Level 1" reads as a
  /// real starting point rather than invisible/black text.
  static const graphite = Color(0xFF3A3F47);

  /// Score-level ramp, highest rung — the deepest, most saturated blue in
  /// the app. Reaching it should read as "arrived," the way `navy` reads
  /// as more premium than `blue`.
  static const premiumBlue = Color(0xFF15458F);

  /// A second, warmer palette for card/section backgrounds and category
  /// accents — chosen to break up the app's white + brand-blue starting
  /// point without touching `primary`/`navy`/`blue` (the actual brand
  /// colors) or the status colors above (`success`/`warning`/`danger`,
  /// which still carry the heatmap/density meaning they always have).
  /// Named after the shade itself, not one screen, since each one is
  /// reused as a deterministic category accent across cards app-wide —
  /// see `categoryAccent()` in campus_widgets.dart.
  static const slateBlue = Color(0xFF4D6787); // Mavi-Gri
  static const mistLilac = Color(0xFF898AA6); // Puslu Mavi-Lila
  static const sage = Color(0xFF94A378); // Adaçayı Yeşili
  static const honey = Color(0xFFF2D388); // Sıcak Sarı
  static const terracotta = Color(0xFFDA8359); // Kiremit
  static const dustyRose = Color(0xFF886F6F); // Puslu Gül
}

/// Score level → color, levels 1 through 5+. Deliberately a "graphite to
/// deepening/more saturated blue" ramp, not a red→yellow→green gamification
/// gradient — a higher level should read as more premium, not more urgent.
Color levelColor(int level) {
  switch (level) {
    case <= 1:
      return ArucadColors.graphite;
    case 2:
      return ArucadColors.slate;
    case 3:
      return ArucadColors.blue;
    case 4:
      return ArucadColors.navy;
    default:
      return ArucadColors.premiumBlue;
  }
}

/// Brand spacing tokens for consistent mobile-first layout.
class ArucadSpacing {
  static const double xs = 6.0;
  static const double sm = 10.0;
  static const double md = 16.0;
  static const double lg = 22.0;
  static const double xl = 32.0;
}

/// Centralized text styles to follow ARUCAD's real official visual identity
/// (brand guide: Montserrat primary, Oswald secondary/display) — not a
/// generic system-font stand-in.
class ArucadTextStyles {
  static TextTheme textTheme([Color? color]) {
    final base = TextTheme(
      displaySmall: TextStyle(
          fontWeight: FontWeight.w900,
          fontSize: 28,
          color: color ?? ArucadColors.ink,
          height: 1.05),
      headlineSmall: TextStyle(
          fontWeight: FontWeight.w800,
          fontSize: 20,
          color: color ?? ArucadColors.ink),
      bodyLarge: TextStyle(
          fontWeight: FontWeight.w400,
          fontSize: 15,
          color: color ?? ArucadColors.ink),
      bodySmall: TextStyle(
          fontWeight: FontWeight.w600,
          fontSize: 12,
          color: color ?? ArucadColors.muted),
    );
    // Stamps Montserrat's font family across every text role (including
    // ones not explicitly set above, e.g. bodyMedium — what Scaffold/
    // Material actually uses for its DefaultTextStyle) while keeping the
    // sizes/weights/colors already chosen above.
    return GoogleFonts.montserratTextTheme(base);
  }

  /// ARUCAD's official secondary/display face — for occasional short
  /// labels (level badges, status chips), never for body copy or long
  /// headings; Montserrat (via [textTheme]) carries everything else.
  static TextStyle display({
    double fontSize = 14,
    FontWeight fontWeight = FontWeight.w700,
    Color? color,
    double? letterSpacing,
  }) =>
      GoogleFonts.oswald(
        fontSize: fontSize,
        fontWeight: fontWeight,
        color: color ?? ArucadColors.ink,
        letterSpacing: letterSpacing,
      );
}

class ArucadTheme {
  static ThemeData data() {
    final scheme = ColorScheme.fromSeed(
      seedColor: ArucadColors.primary,
      brightness: Brightness.light,
    ).copyWith(
      primary: ArucadColors.primary,
      onPrimary: Colors.white,
      surface: Colors.white,
      onSurface: ArucadColors.ink,
      error: ArucadColors.danger,
    );

    final buttonShape =
        RoundedRectangleBorder(borderRadius: BorderRadius.circular(14));
    const buttonPadding = EdgeInsets.symmetric(
        vertical: ArucadSpacing.sm, horizontal: ArucadSpacing.lg);
    const buttonTextStyle = TextStyle(fontWeight: FontWeight.w800);

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: ArucadColors.paper,
      textTheme: ArucadTextStyles.textTheme(),
      appBarTheme: const AppBarTheme(
        backgroundColor: ArucadColors.paper,
        foregroundColor: ArucadColors.ink,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
            color: ArucadColors.ink, fontSize: 20, fontWeight: FontWeight.w900),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      ),
      dividerTheme: const DividerThemeData(
          color: ArucadColors.mist, thickness: 1, space: 1),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(
            horizontal: ArucadSpacing.md, vertical: ArucadSpacing.sm),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide.none,
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: ArucadColors.primary, width: 1.4),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        elevation: 0,
        indicatorColor: ArucadColors.primary.withValues(alpha: .12),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
              color: states.contains(WidgetState.selected)
                  ? ArucadColors.primary
                  : ArucadColors.muted),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
              fontWeight: FontWeight.w700,
              fontSize: 12,
              color: states.contains(WidgetState.selected)
                  ? ArucadColors.primary
                  : ArucadColors.muted),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: ArucadColors.primary,
          foregroundColor: Colors.white,
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: buttonTextStyle,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: ArucadColors.primary,
          foregroundColor: Colors.white,
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: buttonTextStyle,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: ArucadColors.primary,
          side: const BorderSide(color: ArucadColors.primary, width: 1.2),
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: buttonTextStyle,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: ArucadColors.primary,
          textStyle: buttonTextStyle,
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: ArucadColors.mist,
        selectedColor: ArucadColors.primary.withValues(alpha: .12),
        disabledColor: ArucadColors.mist,
        labelStyle: const TextStyle(
            fontWeight: FontWeight.w700, color: ArucadColors.ink, fontSize: 13),
        secondarySelectedColor: ArucadColors.primary,
        checkmarkColor: ArucadColors.primary,
        side: BorderSide.none,
        shape: const StadiumBorder(),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      ),
      progressIndicatorTheme:
          const ProgressIndicatorThemeData(color: ArucadColors.primary),
    );
  }
}
