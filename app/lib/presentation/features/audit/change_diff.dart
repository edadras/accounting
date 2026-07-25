import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/audit_repository.dart';
import 'audit_format.dart';

/// The before/after of one trail entry, as a diff rather than as JSON.
///
/// Old and new are stacked and labelled instead of being joined by an arrow:
/// an arrow has a direction, and this screen has to read correctly in a
/// right-to-left layout where that direction reverses.
class ChangeDiff extends ConsumerWidget {
  const ChangeDiff({super.key, required this.entry});

  final AuditEntry entry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final localeCode = ref.watch(localeProvider).code;
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final changes = entry.changes;

    if (changes.isEmpty) return const SizedBox.shrink();

    return Container(
      margin: const EdgeInsetsDirectional.only(top: 12),
      padding: const EdgeInsetsDirectional.all(12),
      decoration: BoxDecoration(
        color: isDark
            ? NeonPalette.surfaceHigh.withValues(alpha: 0.6)
            : NeonPalette.lightBackground,
        borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            t('audit.changes'),
            style: TextStyle(
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
              letterSpacing: 0.4,
              color: isDark
                  ? NeonPalette.textMuted
                  : NeonPalette.lightTextSecondary,
            ),
          ),
          const SizedBox(height: 8),
          for (final change in changes)
            Padding(
              padding: const EdgeInsetsDirectional.only(bottom: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    AuditFormat.field(t, change.field),
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: isDark
                          ? NeonPalette.textSecondary
                          : NeonPalette.lightTextSecondary,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Wrap(
                    spacing: 10,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      _Side(
                        label: t('audit.before'),
                        value: AuditFormat.value(
                          t,
                          change.field,
                          change.before,
                          localeCode,
                        ),
                        struck: true,
                      ),
                      _Side(
                        label: t('audit.after'),
                        value: AuditFormat.value(
                          t,
                          change.field,
                          change.after,
                          localeCode,
                        ),
                        struck: false,
                      ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Side extends StatelessWidget {
  const _Side({
    required this.label,
    required this.value,
    required this.struck,
  });

  final String label;
  final String value;
  final bool struck;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final muted =
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary;

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label,
          style: TextStyle(fontSize: 10.5, color: muted),
        ),
        const SizedBox(width: 5),
        Text(
          value,
          style: TextStyle(
            fontSize: 12.5,
            fontWeight: struck ? FontWeight.w500 : FontWeight.w700,
            decoration: struck ? TextDecoration.lineThrough : null,
            decorationColor: muted,
            color: struck
                ? muted
                : (isDark
                    ? NeonPalette.textPrimary
                    : NeonPalette.lightTextPrimary),
          ),
        ),
      ],
    );
  }
}
