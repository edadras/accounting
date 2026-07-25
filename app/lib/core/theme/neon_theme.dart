import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'neon_palette.dart';

/// Builds the Material theme from the neon palette.
///
/// Note on fonts: no font asset is bundled by default so a fresh clone builds
/// without extra steps. [fontFamily] lets the app inject Vazirmatn once
/// `scripts/fetch_fonts.sh` has been run.
abstract final class NeonTheme {
  static ThemeData dark({String? fontFamily}) {
    const scheme = ColorScheme.dark(
      primary: NeonPalette.cyan,
      onPrimary: Color(0xFF00131A),
      secondary: NeonPalette.violet,
      onSecondary: Colors.white,
      tertiary: NeonPalette.magenta,
      onTertiary: Colors.white,
      error: NeonPalette.magenta,
      onError: Colors.white,
      surface: NeonPalette.surface,
      onSurface: NeonPalette.textPrimary,
      surfaceContainerHighest: NeonPalette.surfaceHigh,
      outline: NeonPalette.hairline,
    );
    return _build(
      scheme: scheme,
      background: NeonPalette.abyss,
      textPrimary: NeonPalette.textPrimary,
      textSecondary: NeonPalette.textSecondary,
      fontFamily: fontFamily,
      brightness: Brightness.dark,
    );
  }

  static ThemeData light({String? fontFamily}) {
    const scheme = ColorScheme.light(
      primary: NeonPalette.lightCyan,
      onPrimary: Colors.white,
      secondary: NeonPalette.lightViolet,
      onSecondary: Colors.white,
      tertiary: NeonPalette.lightMagenta,
      onTertiary: Colors.white,
      error: NeonPalette.lightMagenta,
      onError: Colors.white,
      surface: NeonPalette.lightSurface,
      onSurface: NeonPalette.lightTextPrimary,
    );
    return _build(
      scheme: scheme,
      background: NeonPalette.lightBackground,
      textPrimary: NeonPalette.lightTextPrimary,
      textSecondary: NeonPalette.lightTextSecondary,
      fontFamily: fontFamily,
      brightness: Brightness.light,
    );
  }

  static ThemeData _build({
    required ColorScheme scheme,
    required Color background,
    required Color textPrimary,
    required Color textSecondary,
    required Brightness brightness,
    String? fontFamily,
  }) {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      brightness: brightness,
      scaffoldBackgroundColor: background,
      fontFamily: fontFamily,
    );

    return base.copyWith(
      textTheme: base.textTheme.apply(
        bodyColor: textPrimary,
        displayColor: textPrimary,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        foregroundColor: textPrimary,
        systemOverlayStyle: brightness == Brightness.dark
            ? SystemUiOverlayStyle.light
            : SystemUiOverlayStyle.dark,
        titleTextStyle: base.textTheme.titleLarge?.copyWith(
          color: textPrimary,
          fontWeight: FontWeight.w700,
          fontFamily: fontFamily,
        ),
      ),
      dividerTheme: DividerThemeData(
        color: scheme.primary.withValues(alpha: 0.10),
        thickness: 1,
        space: 1,
      ),
      cardTheme: CardThemeData(
        color: scheme.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: brightness == Brightness.dark
            ? NeonPalette.surfaceHigh
            : Colors.white,
        hintStyle: TextStyle(color: textSecondary),
        labelStyle: TextStyle(color: textSecondary),
        contentPadding:
            const EdgeInsetsDirectional.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: scheme.primary.withValues(alpha: 0.15)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: scheme.primary.withValues(alpha: 0.15)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: scheme.primary, width: 1.6),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: scheme.error.withValues(alpha: 0.6)),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: brightness == Brightness.dark
            ? NeonPalette.surface
            : Colors.white,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
        showDragHandle: true,
        dragHandleColor: textSecondary,
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: NeonPalette.surfaceHigh,
        contentTextStyle: TextStyle(color: textPrimary, fontFamily: fontFamily),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      splashFactory: InkSparkle.splashFactory,
    );
  }
}
