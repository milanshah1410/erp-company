import 'package:flutter/material.dart';

/// Light and dark themes (§27).
///
/// Two constraints shape this and neither is decoration:
///
/// * **Gujarati and Hindi are first-class.** Devanagari and Gujarati glyphs sit
///   taller than Latin ones, so line heights are generous and nothing is packed
///   to a fixed height that a Gujarati label would clip. A layout that only fits
///   English is a layout that breaks for most of this product's users.
/// * **Low-end Android is the target** (§26). No blurs, no elevation overlays,
///   no shadows on scrolling rows — the things that cost frames on a device
///   with an entry-level GPU and no thermal headroom.
///
/// The seed colour is a placeholder. Per-tenant white-label branding arrives
/// from `GET /api/branding`, and [fromSeed] is what applies it at runtime.
///
/// API note: uses the current theme-data classes (`CardThemeData`,
/// `InputDecorationThemeData`) and `withValues` rather than the older
/// `CardTheme` / `withOpacity`. Those older names are not merely deprecated on
/// the SDK this project targets — `ThemeData.cardTheme` no longer accepts a
/// `CardTheme` at all — so the pubspec's `flutter:` constraint is what keeps the
/// two in step. Lower the constraint and this file stops compiling, which is the
/// correct failure: it is louder than a theme that silently stops applying.
class AppTheme {
  const AppTheme._();

  /// Fallback brand colour, matching the platform default
  /// (`config/saas.php` → `branding.primary_color`).
  static const Color defaultSeed = Color(0xFFE8A33D);

  static ThemeData light([Color seed = defaultSeed]) =>
      _build(seed, Brightness.light);

  static ThemeData dark([Color seed = defaultSeed]) =>
      _build(seed, Brightness.dark);

  /// Builds a theme from the tenant's own brand colour.
  ///
  /// White-label means a shop's staff open the app and see their shop, so the
  /// colour is applied at runtime from the API rather than baked into the build
  /// — one APK, every brand.
  static ThemeData fromSeed(Color seed, Brightness brightness) =>
      _build(seed, brightness);

  static ThemeData _build(Color seed, Brightness brightness) {
    final ColorScheme scheme = ColorScheme.fromSeed(
      seedColor: seed,
      brightness: brightness,
    );

    final ThemeData base = ThemeData(useMaterial3: true, colorScheme: scheme);

    return base.copyWith(
      visualDensity: VisualDensity.standard,

      // Line height 1.35 across body text: enough for Devanagari matras and
      // Gujarati ascenders without making English look double-spaced.
      textTheme: base.textTheme.copyWith(
        bodyLarge: base.textTheme.bodyLarge?.copyWith(height: 1.35),
        bodyMedium: base.textTheme.bodyMedium?.copyWith(height: 1.35),
        bodySmall: base.textTheme.bodySmall?.copyWith(height: 1.35),
      ),

      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        foregroundColor: scheme.onSurface,
        centerTitle: false,
        // Flat: a scroll-under elevation overlay repaints the bar on every
        // frame of a list scroll, which is measurable on a low-end handset.
        scrolledUnderElevation: 0,
        elevation: 0,
      ),

      cardTheme: CardThemeData(
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: scheme.outlineVariant),
        ),
      ),

      inputDecorationTheme: InputDecorationThemeData(
        filled: true,
        fillColor: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: scheme.primary, width: 1.6),
        ),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),

      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          // 48dp: the accessibility floor, and the difference between a POS
          // that can be used quickly and one that cannot.
          minimumSize: const Size.fromHeight(48),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),

      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(48),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      ),

      listTileTheme: const ListTileThemeData(minVerticalPadding: 12),

      dividerTheme: DividerThemeData(
        space: 1,
        thickness: 1,
        color: scheme.outlineVariant,
      ),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
    );
  }
}
