import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';

/// The page shell every module screen sits in.
///
/// Module screens are pushed on top of the shell rather than added to the
/// bottom bar — five tabs is already the most a thumb can aim at, and these
/// verticals are visited deliberately, not constantly.
class ModulePage extends StatelessWidget {
  const ModulePage({
    super.key,
    required this.title,
    required this.child,
    this.subtitle,
  });

  final String title;
  final String? subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonPageBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(title),
              if (subtitle != null)
                Text(
                  subtitle!,
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w500,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
            ],
          ),
        ),
        body: SafeArea(top: false, child: child),
      ),
    );
  }
}

/// Same idea as the shell's backdrop, kept local so the shared widget library
/// stays untouched.
class NeonPageBackdrop extends StatelessWidget {
  const NeonPageBackdrop({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    if (Theme.of(context).brightness != Brightness.dark) return child;

    return Stack(
      children: [
        const Positioned.fill(child: ColoredBox(color: NeonPalette.abyss)),
        Positioned(
          top: -150,
          right: -110,
          child: IgnorePointer(
            child: Container(
              width: 320,
              height: 320,
              decoration: const BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [Color(0x2200E5FF), Color(0x0000E5FF)],
                ),
              ),
            ),
          ),
        ),
        child,
      ],
    );
  }
}

/// A labelled horizontal meter — loan repayment, charge collection, book value.
///
/// The bar glows because the fraction *is* the information on these screens;
/// the track behind it does not.
class NeonMeter extends StatelessWidget {
  const NeonMeter({
    super.key,
    required this.fraction,
    required this.accent,
    this.height = 9,
  });

  final double fraction;
  final Color accent;
  final double height;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return LayoutBuilder(
      builder: (context, constraints) => Stack(
        children: [
          Container(
            height: height,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(999),
            ),
          ),
          TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: fraction.clamp(0.0, 1.0)),
            duration: NeonEffects.medium,
            curve: NeonEffects.curve,
            builder: (context, value, _) => Container(
              height: height,
              width: math.max(6, constraints.maxWidth * value),
              decoration: BoxDecoration(
                gradient: NeonEffects.hero(
                  accent.withValues(alpha: 0.55),
                  accent,
                ),
                borderRadius: BorderRadius.circular(999),
                boxShadow:
                    isDark ? NeonEffects.glowTight(accent, intensity: 0.6) : null,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One `label … value` line. Used by every detail screen, so the alignment and
/// the muted-label / bright-value contrast stay identical across modules.
class DetailRow extends StatelessWidget {
  const DetailRow({
    super.key,
    required this.label,
    required this.value,
    this.accent,
    this.strong = false,
  });

  final String label;
  final String value;
  final Color? accent;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                fontSize: 12.5,
                color: isDark
                    ? NeonPalette.textSecondary
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Text(
            value,
            textAlign: TextAlign.end,
            style: TextStyle(
              fontSize: strong ? 15 : 13,
              fontWeight: strong ? FontWeight.w800 : FontWeight.w600,
              color: accent ??
                  (isDark
                      ? NeonPalette.textPrimary
                      : NeonPalette.lightTextPrimary),
            ),
          ),
        ],
      ),
    );
  }
}

/// Title + optional trailing value, used above grouped lists.
class GroupHeading extends StatelessWidget {
  const GroupHeading({
    super.key,
    required this.title,
    required this.accent,
    this.trailing,
    this.glow = false,
  });

  final String title;
  final Color accent;
  final String? trailing;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 10, top: 6),
      child: Row(
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: accent,
              boxShadow: glow && isDark
                  ? NeonEffects.glowTight(accent, intensity: 0.9)
                  : null,
            ),
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Text(
              title,
              style: TextStyle(
                fontSize: 13.5,
                fontWeight: FontWeight.w700,
                color: isDark
                    ? NeonPalette.textPrimary
                    : NeonPalette.lightTextPrimary,
              ),
            ),
          ),
          if (trailing != null)
            Text(
              trailing!,
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: accent,
              ),
            ),
        ],
      ),
    );
  }
}

/// Loading and error states, shaped like the cards they stand in for so the
/// page does not jump when the data lands.
class ModuleLoading extends StatelessWidget {
  const ModuleLoading({super.key});

  @override
  Widget build(BuildContext context) => const Center(
        child: Padding(
          padding: EdgeInsetsDirectional.all(48),
          child: CircularProgressIndicator(),
        ),
      );
}

class ModuleError extends StatelessWidget {
  const ModuleError({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsetsDirectional.all(48),
          child: Text(
            message,
            style: const TextStyle(color: NeonPalette.magenta),
          ),
        ),
      );
}
