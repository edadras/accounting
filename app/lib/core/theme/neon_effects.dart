import 'package:flutter/material.dart';

import 'neon_palette.dart';

/// Reusable neon effects.
///
/// The rule that keeps this from looking like a toy: **glow is information,
/// not decoration.** Only elements that carry meaning (the primary balance,
/// the active tab, a breached budget, a focused field) glow. Everything else
/// is a flat dark surface with a hairline border.
abstract final class NeonEffects {
  /// Outer glow around an element. [intensity] 0..1 scales the whole effect so
  /// callers can dim a glow without hand-tuning three numbers.
  static List<BoxShadow> glow(Color color, {double intensity = 1.0}) {
    if (intensity <= 0) return const [];
    return [
      BoxShadow(
        color: color.withValues(alpha: 0.35 * intensity),
        blurRadius: 24 * intensity,
        spreadRadius: -4,
      ),
      BoxShadow(
        color: color.withValues(alpha: 0.18 * intensity),
        blurRadius: 48 * intensity,
        spreadRadius: -8,
      ),
    ];
  }

  /// Tight glow for small elements — chips, icons, dots.
  static List<BoxShadow> glowTight(Color color, {double intensity = 1.0}) {
    if (intensity <= 0) return const [];
    return [
      BoxShadow(
        color: color.withValues(alpha: 0.45 * intensity),
        blurRadius: 12 * intensity,
        spreadRadius: -2,
      ),
    ];
  }

  /// Soft ambient shadow for cards that should sit above the page without
  /// glowing. Dark shadow, not neon.
  static const List<BoxShadow> lift = [
    BoxShadow(
      color: Color(0x66000000),
      blurRadius: 20,
      offset: Offset(0, 8),
      spreadRadius: -6,
    ),
  ];

  /// Glass fill for cards: a barely-there gradient so large surfaces are not
  /// dead flat.
  static LinearGradient glass({Color tint = NeonPalette.cyan}) {
    return LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [
        tint.withValues(alpha: 0.06),
        tint.withValues(alpha: 0.01),
      ],
    );
  }

  /// Strong gradient for hero surfaces (the balance card, primary buttons).
  static LinearGradient hero(Color from, Color to) {
    return LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [from, to],
    );
  }

  /// Border for a neon-outlined surface.
  static Border border(Color color, {double alpha = 0.28, double width = 1}) {
    return Border.all(color: color.withValues(alpha: alpha), width: width);
  }

  /// Text style addition that makes a numeral look lit rather than painted.
  static List<Shadow> textGlow(Color color, {double intensity = 1.0}) {
    return [
      Shadow(color: color.withValues(alpha: 0.55 * intensity), blurRadius: 18 * intensity),
    ];
  }

  static const Duration fast = Duration(milliseconds: 150);
  static const Duration medium = Duration(milliseconds: 280);
  static const Curve curve = Curves.easeOutCubic;

  static const double radiusSm = 10;
  static const double radiusMd = 16;
  static const double radiusLg = 24;
  static const double radiusXl = 32;
}
