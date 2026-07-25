import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/theme/neon_effects.dart';
import '../../core/theme/neon_palette.dart';

/// Page background: a near-black field with two soft neon blooms.
///
/// The blooms are what stop a dark UI from reading as a flat grey slab, and
/// they are painted rather than blurred images so they cost nothing.
class NeonBackdrop extends StatelessWidget {
  const NeonBackdrop({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (!isDark) return child;

    return Stack(
      children: [
        const Positioned.fill(child: ColoredBox(color: NeonPalette.abyss)),
        const Positioned(
          top: -140,
          right: -100,
          child: _Bloom(color: NeonPalette.cyan, size: 340),
        ),
        const Positioned(
          bottom: -160,
          left: -120,
          child: _Bloom(color: NeonPalette.violet, size: 380),
        ),
        child,
      ],
    );
  }
}

class _Bloom extends StatelessWidget {
  const _Bloom({required this.color, required this.size});

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(
            colors: [color.withValues(alpha: 0.16), color.withValues(alpha: 0)],
          ),
        ),
      ),
    );
  }
}

/// A number with its label. The workhorse of the dashboard.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.accent = NeonPalette.cyan,
    this.caption,
    this.icon,
    this.glow = false,
  });

  final String label;
  final String value;
  final Color accent;
  final String? caption;
  final IconData? icon;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCardShell(
      accent: accent,
      glow: glow,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              if (icon != null) ...[
                Icon(icon, size: 15, color: accent),
                const SizedBox(width: 6),
              ],
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.3,
                    color: isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(
              value,
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.w800,
                height: 1.1,
                color: isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
                shadows: glow && isDark ? NeonEffects.textGlow(accent, intensity: 0.7) : null,
              ),
            ),
          ),
          if (caption != null) ...[
            const SizedBox(height: 6),
            Text(
              caption!,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 11,
                color: isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Lightweight card shell used by the small tiles — no backdrop blur, because
/// a grid of blurred surfaces is where a neon UI starts dropping frames.
class NeonCardShell extends StatelessWidget {
  const NeonCardShell({
    super.key,
    required this.child,
    this.accent = NeonPalette.cyan,
    this.glow = false,
    this.padding = const EdgeInsetsDirectional.all(16),
    this.onTap,
  });

  final Widget child;
  final Color accent;
  final bool glow;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final radius = BorderRadius.circular(NeonEffects.radiusMd);

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: radius,
        child: Ink(
          padding: padding,
          decoration: BoxDecoration(
            // Gradient only in dark mode: a BoxDecoration ignores `color`
            // whenever a gradient is set, so passing both would drop the fill.
            color: isDark ? null : Colors.white,
            gradient: isDark ? NeonEffects.glass(tint: accent) : null,
            borderRadius: radius,
            border: NeonEffects.border(accent, alpha: isDark ? 0.20 : 0.14),
            boxShadow: glow && isDark
                ? NeonEffects.glow(accent, intensity: 0.55)
                : NeonEffects.lift,
          ),
          child: child,
        ),
      ),
    );
  }
}

/// Small pill: category chips, filters, the offline badge.
class NeonChip extends StatelessWidget {
  const NeonChip({
    super.key,
    required this.label,
    this.accent = NeonPalette.cyan,
    this.selected = false,
    this.icon,
    this.onTap,
  });

  final String label;
  final Color accent;
  final bool selected;
  final IconData? icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: NeonEffects.fast,
        curve: NeonEffects.curve,
        padding: const EdgeInsetsDirectional.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected
              ? accent.withValues(alpha: isDark ? 0.18 : 0.12)
              : Colors.transparent,
          borderRadius: BorderRadius.circular(999),
          border: NeonEffects.border(accent, alpha: selected ? 0.65 : 0.20),
          boxShadow: selected && isDark ? NeonEffects.glowTight(accent, intensity: 0.5) : null,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (icon != null) ...[
              Icon(icon, size: 14, color: accent),
              const SizedBox(width: 6),
            ],
            Text(
              label,
              style: TextStyle(
                fontSize: 13,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                color: selected
                    ? accent
                    : (isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Horizontal bars for "spending by category".
///
/// A bar chart rather than a donut: comparing lengths against a shared baseline
/// is far easier than comparing angles, and it labels cleanly in both RTL and
/// LTR without rotating any text.
class NeonBarChart extends StatelessWidget {
  const NeonBarChart({super.key, required this.rows, this.maxRows = 5});

  final List<NeonBarRow> rows;
  final int maxRows;

  @override
  Widget build(BuildContext context) {
    if (rows.isEmpty) return const SizedBox.shrink();

    final visible = rows.take(maxRows).toList();
    final peak = visible.map((r) => r.value).reduce(math.max);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      children: [
        for (final row in visible)
          Padding(
            padding: const EdgeInsetsDirectional.only(bottom: 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        row.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: isDark
                              ? NeonPalette.textPrimary
                              : NeonPalette.lightTextPrimary,
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Text(
                      row.formattedValue,
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: row.accent,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 7),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final fraction = peak == 0 ? 0.0 : row.value / peak;
                    return Stack(
                      children: [
                        Container(
                          height: 8,
                          decoration: BoxDecoration(
                            color: row.accent.withValues(alpha: 0.10),
                            borderRadius: BorderRadius.circular(999),
                          ),
                        ),
                        TweenAnimationBuilder<double>(
                          tween: Tween(begin: 0, end: fraction),
                          duration: NeonEffects.medium,
                          curve: NeonEffects.curve,
                          builder: (context, value, _) => Container(
                            height: 8,
                            width: math.max(6, constraints.maxWidth * value),
                            decoration: BoxDecoration(
                              gradient: NeonEffects.hero(
                                row.accent.withValues(alpha: 0.55),
                                row.accent,
                              ),
                              borderRadius: BorderRadius.circular(999),
                              boxShadow: isDark
                                  ? NeonEffects.glowTight(row.accent, intensity: 0.6)
                                  : null,
                            ),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ],
            ),
          ),
      ],
    );
  }
}

final class NeonBarRow {
  const NeonBarRow({
    required this.label,
    required this.value,
    required this.formattedValue,
    required this.accent,
  });

  final String label;
  final double value;
  final String formattedValue;
  final Color accent;
}

/// Section heading with an optional trailing action.
class SectionHeader extends StatelessWidget {
  const SectionHeader({
    super.key,
    required this.title,
    this.actionLabel,
    this.onAction,
    this.accent = NeonPalette.cyan,
  });

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 12, top: 4),
      child: Row(
        children: [
          Container(
            width: 3,
            height: 16,
            decoration: BoxDecoration(
              color: accent,
              borderRadius: BorderRadius.circular(999),
              boxShadow: isDark ? NeonEffects.glowTight(accent, intensity: 0.8) : null,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w700,
                color: isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
              ),
            ),
          ),
          if (actionLabel != null)
            GestureDetector(
              onTap: onAction,
              child: Text(
                actionLabel!,
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: accent,
                ),
              ),
            ),
        ],
      ),
    );
  }
}
