import 'package:flutter/material.dart';

import '../../../../core/theme/neon_effects.dart';
import '../../../../core/theme/neon_palette.dart';

/// Something the user must look at before trusting the screen.
///
/// Amber, bordered and glowing — the one place in the AI flows where a glow is
/// earned, because an unchecked low-confidence number is the failure this
/// product cannot afford.
class ReviewBanner extends StatelessWidget {
  const ReviewBanner({
    super.key,
    required this.message,
    this.accent = NeonPalette.amber,
    this.icon = Icons.warning_amber_rounded,
    this.glow = true,
  });

  final String message;
  final Color accent;
  final IconData icon;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsetsDirectional.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: isDark ? 0.10 : 0.08),
        borderRadius: BorderRadius.circular(NeonEffects.radiusMd),
        border: NeonEffects.border(accent, alpha: glow ? 0.55 : 0.25),
        boxShadow:
            glow && isDark ? NeonEffects.glowTight(accent, intensity: 0.5) : null,
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: accent),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                fontSize: 12.5,
                height: 1.5,
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
