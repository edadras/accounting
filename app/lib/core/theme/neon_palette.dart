import 'package:flutter/material.dart';

/// The Finora neon palette.
///
/// The product is dark-first: neon reads as neon only against deep, slightly
/// blue-black surfaces. A light theme exists for accessibility, but it is a
/// *muted* translation of the same hues — never neon-on-white, which is
/// unreadable.
abstract final class NeonPalette {
  // ---------------------------------------------------------------- surfaces
  /// Page background. Near-black with a blue cast so cyan does not look grey.
  static const Color abyss = Color(0xFF05070F);

  /// Elevated surface (cards, sheets).
  static const Color surface = Color(0xFF0C1120);

  /// Surface one step higher (nested cards, selected rows).
  static const Color surfaceHigh = Color(0xFF141B2E);

  /// Hairline borders. Neon at very low alpha, not grey.
  static const Color hairline = Color(0x1A00E5FF);

  // ------------------------------------------------------------------ accents
  /// Primary accent. Used for balance, primary actions, focus rings.
  static const Color cyan = Color(0xFF00E5FF);

  /// Expense / negative / danger.
  static const Color magenta = Color(0xFFFF2E88);

  /// Income / positive / success.
  static const Color lime = Color(0xFF9BFF3D);

  /// Investment, AI, "smart" surfaces.
  static const Color violet = Color(0xFF8B5CFF);

  /// Warning, due dates, budget thresholds.
  static const Color amber = Color(0xFFFFB020);

  // -------------------------------------------------------------------- text
  static const Color textPrimary = Color(0xFFEAF2FF);
  static const Color textSecondary = Color(0xFF8B9BB8);
  static const Color textMuted = Color(0xFF56637D);

  // ------------------------------------------------------- semantic aliases
  static const Color income = lime;
  static const Color expense = magenta;
  static const Color transfer = cyan;

  /// Deterministic accent for a category/account chip, so the same entity keeps
  /// the same colour across the whole app without storing one.
  static Color forSeed(String seed) {
    const wheel = [cyan, magenta, lime, violet, amber];
    var hash = 0;
    for (final unit in seed.codeUnits) {
      hash = (hash * 31 + unit) & 0x7FFFFFFF;
    }
    return wheel[hash % wheel.length];
  }

  // ------------------------------------------------------------- light mode
  // Neon hues, darkened until they pass contrast on a light surface.
  static const Color lightBackground = Color(0xFFF4F6FB);
  static const Color lightSurface = Color(0xFFFFFFFF);
  static const Color lightCyan = Color(0xFF0077A8);
  static const Color lightMagenta = Color(0xFFC4005E);
  static const Color lightLime = Color(0xFF3F7A00);
  static const Color lightViolet = Color(0xFF5B2FCC);
  static const Color lightTextPrimary = Color(0xFF0C1120);
  static const Color lightTextSecondary = Color(0xFF56637D);
}
