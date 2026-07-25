import 'dart:ui';

import 'package:flutter/material.dart';

import '../../core/theme/neon_effects.dart';
import '../../core/theme/neon_palette.dart';

/// The base surface of the app: a dark glass panel with a hairline neon edge.
///
/// [glow] is off by default. Turn it on only for surfaces that carry the most
/// important number on the screen — if everything glows, nothing reads.
class NeonCard extends StatelessWidget {
  const NeonCard({
    super.key,
    required this.child,
    this.accent = NeonPalette.cyan,
    this.glow = false,
    this.glowIntensity = 1.0,
    this.padding = const EdgeInsetsDirectional.all(18),
    this.radius = NeonEffects.radiusLg,
    this.onTap,
    this.blur = true,
  });

  final Widget child;
  final Color accent;
  final bool glow;
  final double glowIntensity;
  final EdgeInsetsGeometry padding;
  final double radius;
  final VoidCallback? onTap;
  final bool blur;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final borderRadius = BorderRadius.circular(radius);

    Widget surface = Container(
      padding: padding,
      decoration: BoxDecoration(
        borderRadius: borderRadius,
        gradient: isDark ? NeonEffects.glass(tint: accent) : null,
        color: isDark
            ? NeonPalette.surface.withValues(alpha: 0.72)
            : Colors.white,
        border: NeonEffects.border(
          isDark ? accent : accent.withValues(alpha: 0.5),
          alpha: isDark ? 0.22 : 0.18,
        ),
      ),
      child: child,
    );

    if (blur && isDark) {
      surface = ClipRRect(
        borderRadius: borderRadius,
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
          child: surface,
        ),
      );
    }

    if (onTap != null) {
      surface = Material(
        color: Colors.transparent,
        borderRadius: borderRadius,
        child: InkWell(
          onTap: onTap,
          borderRadius: borderRadius,
          splashColor: accent.withValues(alpha: 0.10),
          highlightColor: accent.withValues(alpha: 0.05),
          child: surface,
        ),
      );
    }

    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: borderRadius,
        boxShadow: glow && isDark
            ? NeonEffects.glow(accent, intensity: glowIntensity)
            : NeonEffects.lift,
      ),
      child: surface,
    );
  }
}
