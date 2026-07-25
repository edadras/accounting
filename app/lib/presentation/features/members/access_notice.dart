import 'package:flutter/material.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/remote/coded_failure.dart';
import '../../widgets/neon_card.dart';

/// A full-width explanation where a list would be.
///
/// Shared with the audit screens because a 403 reads the same wherever it lands:
/// the server did not fail, it declined, and the difference is the whole point
/// of not printing a stack trace here.
class NoticePanel extends StatelessWidget {
  const NoticePanel({
    super.key,
    required this.icon,
    required this.title,
    this.body,
    this.accent = NeonPalette.amber,
  });

  final IconData icon;
  final String title;
  final String? body;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsetsDirectional.fromSTEB(20, 32, 20, 32),
        child: NeonCard(
          accent: accent,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(
                      color: accent.withValues(alpha: 0.12),
                      borderRadius:
                          BorderRadius.circular(NeonEffects.radiusSm + 2),
                      border: NeonEffects.border(accent, alpha: 0.35),
                    ),
                    child: Icon(icon, size: 20, color: accent),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Text(
                      title,
                      style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                  ),
                ],
              ),
              if (body != null) ...[
                const SizedBox(height: 12),
                Text(
                  body!,
                  style: TextStyle(
                    fontSize: 12.5,
                    height: 1.6,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// Turns whatever a repository threw into something a person can act on.
///
/// A refusal by role gets its own wording and an amber (warning) frame; a real
/// failure keeps magenta. Anything that is not a [CodedFailure] cannot have a
/// translated explanation, so it falls back to the generic one rather than
/// leaking an exception's `toString`.
Widget noticeForFailure(
  Object error, {
  required Translator t,
  required String permissionTitle,
  required String permissionBody,
}) {
  if (error is CodedFailure && error.isPermissionDenied) {
    return NoticePanel(
      icon: Icons.lock_person_rounded,
      title: permissionTitle,
      body: permissionBody,
    );
  }

  return NoticePanel(
    icon: Icons.error_outline_rounded,
    accent: NeonPalette.magenta,
    title: t('common.error'),
    body: error is CodedFailure ? t(error.translationKey) : null,
  );
}
