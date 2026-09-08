import 'package:flutter/material.dart';

/// Figma Super Set palette — exact tokens from the mobile UI sheet.
class ArucadColors {
  /// The ARUCAD blue from the supplied brand palette is the app's main action
  /// colour. Red remains available for destructive actions and alerts.
  static const primary = Color(0xFF000F9F);
  static const red = Color(0xFFEA0029);
  // Darkened from the original 0xFFFFD700: pure gold was too light to read
  // against white/light surfaces (icons/text in this color nearly
  // disappeared). This amber tone keeps the "yellow" identity but has real
  // contrast; anything filled solid with it must use black content — see
  // [onAccent].
  static const yellow = Color(0xFFC79100);
  static const blue = primary;
  static const campusGreen = Color(0xFF28A745);
  static const lavender = Color(0xFFC9BFE3);
  static const orange = Color(0xFFF7941D);

  static const navy = primary;
  static const slate = Color(0xFF5B6472);
  static const ink = Color(0xFF111111);
  static const paper = Color(0xFFFFFFFF);
  static const canvas = Color(0xFFF2F2F2);
  static const mist = Color(0xFFF2F2F2);
  static const border = Color(0xFFE0E0E0);
  static const muted = Color(0xFF6B7280);

  static const green = campusGreen;
  static const success = campusGreen;
  static const warning = yellow;
  static const danger = red;
}

/// Device-level appearance choice. System is the default so ARUCAD follows
/// the phone unless a student deliberately selects light or dark.
enum ArucadThemePreference {
  system,
  light,
  dark;

  ThemeMode get themeMode => switch (this) {
        ArucadThemePreference.system => ThemeMode.system,
        ArucadThemePreference.light => ThemeMode.light,
        ArucadThemePreference.dark => ThemeMode.dark,
      };

  String get label => switch (this) {
        ArucadThemePreference.system => 'Sistem ayarını kullan',
        ArucadThemePreference.light => 'Açık tema',
        ArucadThemePreference.dark => 'Koyu tema',
      };

  String labelKey() => switch (this) {
        ArucadThemePreference.system => 'theme_system',
        ArucadThemePreference.light => 'theme_light',
        ArucadThemePreference.dark => 'theme_dark',
      };

  static ArucadThemePreference fromStorage(String? value) {
    for (final preference in values) {
      if (preference.name == value) return preference;
    }
    return ArucadThemePreference.system;
  }
}

/// Button overlay that ignores hover but keeps pressed feedback.
///
/// Material 3 buttons paint their own overlay, so `ThemeData.hoverColor`
/// never reaches them — without this the buttons would still tint under a
/// desktop cursor after the rest of the app stopped doing so. Returning
/// null for hovered leaves the button untouched; pressed still responds,
/// because that is confirmation of a real tap rather than decoration.
final WidgetStateProperty<Color?> _noHoverOverlay =
    WidgetStateProperty.resolveWith((states) {
  if (states.contains(WidgetState.pressed)) {
    return ArucadColors.ink.withValues(alpha: .10);
  }

  return null;
});

/// WCAG contrast ratio between two colours (1:1 identical, 21:1 max).
double contrastRatio(Color a, Color b) {
  final la = a.computeLuminance();
  final lb = b.computeLuminance();
  final hi = la > lb ? la : lb;
  final lo = la > lb ? lb : la;

  return (hi + 0.05) / (lo + 0.05);
}

/// A solid fill that white text can actually sit on.
///
/// The brand yellow, green and orange are all too light for white text —
/// white on the yellow measures 2.81:1, well under the 4.5:1 minimum and
/// worse than black. That is why badges ended up with black labels, which
/// reads as muddy on a saturated fill.
///
/// Rather than choosing between an illegible white and a muddy black, the
/// fill itself is darkened until white passes. Badges and buttons then look
/// consistent — always white on colour — and are measurably readable. Hue
/// is preserved, so a yellow chip is still recognisably yellow.
Color accentFill(Color color) {
  var fill = color;
  final hsl = HSLColor.fromColor(color);

  // Step lightness down until white text clears AA. Bounded so a colour
  // that can never satisfy it does not loop to black.
  for (var i = 0; i < 24; i++) {
    if (contrastRatio(fill, Colors.white) >= 4.5) return fill;
    final next = hsl.lightness - (i + 1) * 0.02;
    if (next <= 0.12) break;
    fill = hsl.withLightness(next).toColor();
  }

  return fill;
}

/// Text/icon colour for a fill produced by [accentFill].
///
/// Still measured rather than assumed: a caller passing a raw light colour
/// gets black, because silently returning white would be unreadable.
Color onAccent(Color color) {
  return contrastRatio(color, Colors.white) >= 4.5
      ? Colors.white
      : ArucadColors.ink;
}

/// Score level → color, levels 1 through 5+.
Color levelColor(int level) {
  switch (level) {
    case <= 1:
      return ArucadColors.ink;
    case 2:
      return ArucadColors.slate;
    case 3:
      return ArucadColors.blue;
    case 4:
      return ArucadColors.navy;
    default:
      return ArucadColors.blue;
  }
}

/// Brand spacing tokens for consistent mobile-first layout.
class ArucadSpacing {
  static const double xxs = 4.0;
  static const double xs = 6.0;
  static const double xsm = 8.0;
  static const double sm = 10.0;
  static const double smd = 12.0;
  static const double md = 16.0;
  static const double lg = 22.0;
  static const double xl = 32.0;
}

/// Figma Super Set uses a 12 px corner language for cards/inputs; banners
/// and pills stay larger. Named radii keep screens from inventing their own.
class ArucadRadius {
  static const double compact = 12.0;
  static const double card = 12.0;
  static const double feature = 16.0;
  static const double pill = 999.0;
}

class ArucadShadows {
  static const card = [
    BoxShadow(color: Color(0x12000000), blurRadius: 18, offset: Offset(0, 6)),
  ];
}

/// A native Android text face is deliberately used for interface copy. The
/// bundled Montserrat subset did not cover every Turkish glyph consistently,
/// which made accented characters fall back to a visibly different face.
class ArucadFonts {
  static const montserrat = 'Roboto';
  static const oswald = 'Oswald';
}

/// Centralized text styles to follow ARUCAD's real official visual identity
/// (brand guide: Montserrat primary). Oswald's narrow accented glyphs made
/// Turkish text inconsistent on Android, so all user-facing copy shares the
/// same Turkish-capable family.
class ArucadTextStyles {
  static TextTheme textTheme([Color? color]) {
    return TextTheme(
      displaySmall: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w900,
          fontSize: 28,
          color: color ?? ArucadColors.ink,
          height: 1.05),
      headlineSmall: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w800,
          fontSize: 20,
          color: color ?? ArucadColors.ink),
      titleLarge: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w800,
          fontSize: 18,
          color: color ?? ArucadColors.ink),
      titleMedium: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w700,
          fontSize: 16,
          color: color ?? ArucadColors.ink),
      bodyLarge: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w400,
          fontSize: 15,
          color: color ?? ArucadColors.ink),
      bodyMedium: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w400,
          fontSize: 14,
          color: color ?? ArucadColors.ink),
      bodySmall: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w600,
          fontSize: 12,
          color: color ?? ArucadColors.muted),
      labelLarge: TextStyle(
          fontFamily: ArucadFonts.montserrat,
          fontWeight: FontWeight.w800,
          fontSize: 14,
          color: color ?? ArucadColors.ink),
    );
  }

  /// Display labels deliberately use Montserrat too: Turkish characters stay
  /// visually consistent with the rest of the interface.
  static TextStyle display({
    double fontSize = 14,
    FontWeight fontWeight = FontWeight.w700,
    Color? color,
    double? letterSpacing,
  }) {
    return TextStyle(
      fontFamily: ArucadFonts.montserrat,
      fontFamilyFallback: const ['Roboto', 'Arial', 'sans-serif'],
      fontSize: fontSize,
      fontWeight: fontWeight,
      color: color ?? ArucadColors.ink,
      letterSpacing: letterSpacing,
    );
  }
}

class ArucadTheme {
  static ThemeData data() => _data(Brightness.light);

  static ThemeData dark() => _data(Brightness.dark);

  static ThemeData _data(Brightness brightness) {
    final isDark = brightness == Brightness.dark;
    // Soft charcoal dark keeps the ARUCAD blue comfortable at night.
    final surface = isDark ? const Color(0xFF23232B) : Colors.white;
    final canvas = isDark ? const Color(0xFF1B1B22) : ArucadColors.canvas;
    final ink = isDark ? const Color(0xFFF2F2F5) : ArucadColors.ink;
    final muted = isDark ? const Color(0xFFB4B5C0) : ArucadColors.muted;
    final mist = isDark ? const Color(0xFF2E2E38) : ArucadColors.mist;
    final border = isDark ? const Color(0xFF42424E) : ArucadColors.border;
    final scheme = ColorScheme.fromSeed(
      seedColor: ArucadColors.primary,
      brightness: brightness,
    ).copyWith(
      primary: ArucadColors.primary,
      onPrimary: Colors.white,
      secondary: ArucadColors.yellow,
      onSecondary: ArucadColors.ink,
      surface: surface,
      onSurface: ink,
      onSurfaceVariant: muted,
      surfaceContainerHighest: mist,
      outline: border,
      error: ArucadColors.danger,
    );
    final buttonShape = RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(ArucadRadius.compact));
    const buttonPadding = EdgeInsets.symmetric(
        vertical: ArucadSpacing.sm, horizontal: ArucadSpacing.lg);
    const buttonTextStyle = TextStyle(
        fontFamily: ArucadFonts.montserrat, fontWeight: FontWeight.w800);

    return ThemeData(
      useMaterial3: true,
      fontFamily: ArucadFonts.montserrat,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: canvas,

      // No hover tint anywhere.
      //
      // This is a phone app that also runs on the web. Material's hover
      // overlay is drawn for any pointer, so on desktop every card and
      // button under the cursor washed over with a grey film — including
      // cards that are not even tappable. Removing it here rather than at
      // each widget means a new screen inherits the same behaviour instead
      // of reintroducing the effect by default.
      //
      // Focus and pressed states are deliberately left alone: those are
      // feedback for an action the person actually took, and keyboard focus
      // is an accessibility requirement, not decoration.
      hoverColor: Colors.transparent,
      iconTheme: IconThemeData(color: ink),
      primaryIconTheme: const IconThemeData(color: Colors.white),
      textTheme: ArucadTextStyles.textTheme(ink).copyWith(
        bodySmall: ArucadTextStyles.textTheme(muted).bodySmall,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: surface,
        foregroundColor: ink,
        iconTheme: IconThemeData(color: ink),
        actionsIconTheme: IconThemeData(color: ink),
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(
            fontFamily: ArucadFonts.montserrat,
            color: ink,
            fontSize: 20,
            fontWeight: FontWeight.w900),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(ArucadRadius.card)),
      ),
      dividerTheme: DividerThemeData(color: mist, thickness: 1, space: 1),
      listTileTheme: ListTileThemeData(
        iconColor: ink,
        textColor: ink,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: mist,
        contentPadding: const EdgeInsets.symmetric(
            horizontal: ArucadSpacing.md, vertical: ArucadSpacing.sm),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(ArucadRadius.compact),
          borderSide: BorderSide.none,
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(ArucadRadius.compact),
          borderSide: const BorderSide(color: ArucadColors.primary, width: 1.4),
        ),
        prefixIconColor: muted,
        suffixIconColor: muted,
        labelStyle: TextStyle(color: muted),
        hintStyle: TextStyle(color: muted),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: surface,
        elevation: 0,
        indicatorColor: (isDark ? const Color(0xFF9AA7FF) : ArucadColors.primary)
            .withValues(alpha: .18),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(
              color: states.contains(WidgetState.selected)
                  ? (isDark ? const Color(0xFF9AA7FF) : ArucadColors.primary)
                  : ink),
        ),
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
              fontWeight: states.contains(WidgetState.selected)
                  ? FontWeight.w800
                  : FontWeight.w600,
              fontSize: 12,
              color: states.contains(WidgetState.selected)
                  ? (isDark ? const Color(0xFF9AA7FF) : ArucadColors.primary)
                  : ink),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: ArucadColors.primary,
          foregroundColor: Colors.white,
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: buttonTextStyle,
        ).copyWith(overlayColor: _noHoverOverlay),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: ArucadColors.primary,
          foregroundColor: Colors.white,
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: buttonTextStyle,
        ).copyWith(overlayColor: _noHoverOverlay),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: isDark ? const Color(0xFF9AA7FF) : ArucadColors.primary,
          side: BorderSide(
              color: isDark ? const Color(0xFF788BFF) : ArucadColors.primary,
              width: 1.2),
          shape: buttonShape,
          padding: buttonPadding,
          textStyle: buttonTextStyle,
        ).copyWith(overlayColor: _noHoverOverlay),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: isDark ? const Color(0xFF9AA7FF) : ArucadColors.primary,
          textStyle: buttonTextStyle,
        ).copyWith(overlayColor: _noHoverOverlay),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: mist,
        selectedColor: ArucadColors.primary.withValues(alpha: .18),
        disabledColor: mist,
        labelStyle: TextStyle(
            fontFamily: ArucadFonts.montserrat,
            fontWeight: FontWeight.w700,
            color: ink,
            fontSize: 13),
        secondarySelectedColor: ArucadColors.primary,
        // The label that goes *on* secondarySelectedColor. Without this it
        // inherited the black `labelStyle` above and rendered black-on-navy,
        // which is unreadable — the selected tab looked blank.
        secondaryLabelStyle: const TextStyle(
            fontFamily: ArucadFonts.montserrat,
            fontWeight: FontWeight.w700,
            color: Colors.white,
            fontSize: 13),
        checkmarkColor: ArucadColors.primary,
        side: BorderSide.none,
        shape: const StadiumBorder(),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      ),
      progressIndicatorTheme:
          const ProgressIndicatorThemeData(color: ArucadColors.primary),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: ArucadColors.primary,
        foregroundColor: Colors.white,
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? ArucadColors.primary
                : surface),
        trackColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? ArucadColors.primary.withValues(alpha: .45)
                : mist),
      ),
      radioTheme: RadioThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) =>
            states.contains(WidgetState.selected)
                ? ArucadColors.primary
                : muted),
      ),
    );
  }
}
