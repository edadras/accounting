import 'package:flutter/material.dart';

import '../../core/theme/neon_effects.dart';
import '../../core/theme/neon_palette.dart';

enum NeonButtonVariant { solid, outline, ghost }

/// Primary action button. The solid variant is the only element on a screen
/// allowed to glow at full intensity, and there should be at most one.
class NeonButton extends StatefulWidget {
  const NeonButton({
    super.key,
    required this.label,
    this.onPressed,
    this.icon,
    this.accent = NeonPalette.cyan,
    this.variant = NeonButtonVariant.solid,
    this.expand = false,
    this.busy = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final Color accent;
  final NeonButtonVariant variant;
  final bool expand;
  final bool busy;

  @override
  State<NeonButton> createState() => _NeonButtonState();
}

class _NeonButtonState extends State<NeonButton> {
  bool _pressed = false;

  bool get _enabled => widget.onPressed != null && !widget.busy;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = widget.accent;
    final radius = BorderRadius.circular(NeonEffects.radiusMd);

    final (Color bg, Color fg, Border? border) = switch (widget.variant) {
      NeonButtonVariant.solid => (accent, const Color(0xFF04070E), null),
      NeonButtonVariant.outline => (
          Colors.transparent,
          isDark ? accent : accent,
          NeonEffects.border(accent, alpha: 0.55, width: 1.4),
        ),
      NeonButtonVariant.ghost => (
          accent.withValues(alpha: 0.10),
          isDark ? accent : accent,
          null,
        ),
    };

    final content = Row(
      mainAxisSize: widget.expand ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (widget.busy)
          SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(strokeWidth: 2, color: fg),
          )
        else if (widget.icon != null)
          Icon(widget.icon, size: 20, color: fg),
        if (widget.busy || widget.icon != null) const SizedBox(width: 10),
        Flexible(
          child: Text(
            widget.label,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: fg,
              fontWeight: FontWeight.w700,
              fontSize: 15,
              letterSpacing: 0.2,
            ),
          ),
        ),
      ],
    );

    return Semantics(
      button: true,
      enabled: _enabled,
      label: widget.label,
      child: GestureDetector(
        onTapDown: _enabled ? (_) => setState(() => _pressed = true) : null,
        onTapUp: _enabled ? (_) => setState(() => _pressed = false) : null,
        onTapCancel: _enabled ? () => setState(() => _pressed = false) : null,
        onTap: _enabled ? widget.onPressed : null,
        child: AnimatedScale(
          scale: _pressed ? 0.97 : 1.0,
          duration: NeonEffects.fast,
          curve: NeonEffects.curve,
          child: AnimatedOpacity(
            opacity: _enabled ? 1 : 0.45,
            duration: NeonEffects.fast,
            child: Container(
              padding: const EdgeInsetsDirectional.symmetric(
                horizontal: 22,
                vertical: 15,
              ),
              decoration: BoxDecoration(
                color: bg,
                borderRadius: radius,
                border: border,
                boxShadow: widget.variant == NeonButtonVariant.solid &&
                        _enabled &&
                        isDark
                    ? NeonEffects.glow(accent, intensity: _pressed ? 0.5 : 1.0)
                    : null,
              ),
              child: content,
            ),
          ),
        ),
      ),
    );
  }
}
