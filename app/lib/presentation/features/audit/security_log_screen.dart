import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/audit_repository.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'audit_format.dart';
import 'audit_providers.dart';

/// The caller's own sign-in history.
///
/// Readable by everyone — it carries no workspace and describes only the person
/// asking, which is exactly why it is the screen someone opens when they think
/// their account has been touched.
class SecurityLogScreen extends ConsumerWidget {
  const SecurityLogScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final log = ref.watch(securityLogProvider);

    return ModulePage(
      title: t('audit.securityTitle'),
      subtitle: t('audit.securitySubtitle'),
      child: log.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('audit.noAccessTitle'),
          permissionBody: t('audit.noAccessBody'),
        ),
        data: (entries) => ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
          children: [
            if (entries.isEmpty)
              Padding(
                padding: const EdgeInsetsDirectional.symmetric(vertical: 20),
                child: Text(
                  t('audit.securityEmpty'),
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              )
            else
              for (final entry in entries) ...[
                SecurityRow(entry: entry, locale: locale),
                const SizedBox(height: 10),
              ],
          ],
        ),
      ),
    );
  }
}

/// One security event.
///
/// A failed sign-in is the row this whole screen exists for, so it is the only
/// one that carries the danger hue and a glow; a successful one stays flat.
class SecurityRow extends ConsumerWidget {
  const SecurityRow({super.key, required this.entry, required this.locale});

  final AuditEntry entry;
  final AppLocale locale;

  static IconData iconFor(AuditEntry entry) {
    if (entry.isFailure) return Icons.gpp_bad_rounded;

    return switch (entry.action) {
      'auth.logout' => Icons.logout_rounded,
      'auth.registered' => Icons.person_add_alt_1_rounded,
      'auth.password_reset' => Icons.password_rounded,
      _ => Icons.login_rounded,
    };
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final accent = entry.isFailure
        ? (isDark ? NeonPalette.magenta : NeonPalette.lightMagenta)
        : (isDark ? NeonPalette.lime : NeonPalette.lightLime);

    return NeonCardShell(
      key: ValueKey('security-${entry.id}'),
      accent: accent,
      glow: entry.isFailure,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
              border: NeonEffects.border(accent, alpha: 0.32),
              boxShadow: entry.isFailure && isDark
                  ? NeonEffects.glowTight(accent, intensity: 0.7)
                  : null,
            ),
            child: Icon(iconFor(entry), size: 18, color: accent),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        AuditFormat.action(t, entry.action),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                          color: entry.isFailure
                              ? accent
                              : (isDark
                                  ? NeonPalette.textPrimary
                                  : NeonPalette.lightTextPrimary),
                        ),
                      ),
                    ),
                    if (entry.isFailure) ...[
                      const SizedBox(width: 8),
                      NeonChip(
                        label: t('audit.failed'),
                        accent: accent,
                        selected: true,
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 4),
                Text(
                  AuditFormat.timestamp(entry.createdAt, locale),
                  style: TextStyle(
                    fontSize: 11,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
                if (entry.ip != null) ...[
                  const SizedBox(height: 3),
                  Row(
                    children: [
                      Text(
                        t('audit.ip'),
                        style: TextStyle(
                          fontSize: 10.5,
                          color: isDark
                              ? NeonPalette.textMuted
                              : NeonPalette.lightTextSecondary,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Text(
                        entry.ip!,
                        // An address is ASCII and must not be reordered by an
                        // RTL paragraph direction.
                        textDirection: TextDirection.ltr,
                        style: TextStyle(
                          fontSize: 11,
                          color: isDark
                              ? NeonPalette.textSecondary
                              : NeonPalette.lightTextSecondary,
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
