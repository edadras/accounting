import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../app_state.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../audit/audit_screen.dart';
import '../audit/security_log_screen.dart';
import '../auth/sign_out_button.dart';
import '../billing/billing_screen.dart';
import '../data/account_deletion_screen.dart';
import '../data/data_export_screen.dart';
import '../members/accept_invitation_screen.dart';
import '../members/members_screen.dart';
import '../security/two_factor_screen.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final themeMode = ref.watch(themeModeProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 120),
      children: [
        SectionHeader(title: t('settings.language')),
        NeonCard(
          child: Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final option in AppLocale.supported)
                NeonChip(
                  // Each language is shown in its own script — someone looking
                  // for فارسی should not have to recognise the word "Persian".
                  label: option.nativeName,
                  selected: option.code == locale.code,
                  accent: NeonPalette.cyan,
                  onTap: () => ref.read(localeProvider.notifier).state = option,
                ),
            ],
          ),
        ),
        const SizedBox(height: 22),
        SectionHeader(title: t('settings.theme'), accent: NeonPalette.violet),
        NeonCard(
          accent: NeonPalette.violet,
          child: Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final entry in {
                ThemeMode.dark: t('settings.themeDark'),
                ThemeMode.light: t('settings.themeLight'),
                ThemeMode.system: t('settings.themeSystem'),
              }.entries)
                NeonChip(
                  label: entry.value,
                  selected: themeMode == entry.key,
                  accent: NeonPalette.violet,
                  onTap: () => ref.read(themeModeProvider.notifier).state = entry.key,
                ),
            ],
          ),
        ),
        const SizedBox(height: 22),
        SectionHeader(title: t('settings.account'), accent: NeonPalette.magenta),
        // Everything the backend has always been able to do but the app could
        // not reach: a second factor, who else is in here, what has been done,
        // and the two data rights docs/07-security.md §8 promises.
        for (final entry in const [
          (
            'two-factor',
            'security.title',
            'settings.securityHint',
            Icons.shield_rounded,
            NeonPalette.magenta,
          ),
          (
            'members',
            'members.title',
            'members.subtitle',
            Icons.group_rounded,
            NeonPalette.cyan,
          ),
          // The invitee is the one person who cannot reach the members list,
          // so accepting needs its own door or an invitation is unusable
          // without deep linking.
          (
            'accept-invitation',
            'members.acceptTitle',
            'members.acceptSubtitle',
            Icons.mail_outline_rounded,
            NeonPalette.cyan,
          ),
          (
            'audit',
            'audit.title',
            'audit.subtitle',
            Icons.receipt_long_rounded,
            NeonPalette.violet,
          ),
          (
            'security-log',
            'audit.securityTitle',
            'audit.securitySubtitle',
            Icons.fingerprint_rounded,
            NeonPalette.amber,
          ),
          (
            'billing',
            'billing.title',
            'billing.subtitle',
            Icons.workspace_premium_rounded,
            NeonPalette.amber,
          ),
          (
            'export',
            'data.export.title',
            'settings.exportHint',
            Icons.download_rounded,
            NeonPalette.lime,
          ),
          (
            'delete-account',
            'data.delete.title',
            'settings.deleteHint',
            Icons.person_off_rounded,
            NeonPalette.magenta,
          ),
        ]) ...[
          SettingsTile(
            id: entry.$1,
            titleKey: entry.$2,
            hintKey: entry.$3,
            icon: entry.$4,
            accent: entry.$5,
          ),
          const SizedBox(height: 10),
        ],
        const SizedBox(height: 12),
        SectionHeader(title: t('settings.about'), accent: NeonPalette.lime),
        NeonCard(
          accent: NeonPalette.lime,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('app.name'),
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: NeonPalette.textPrimary,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                t('app.tagline'),
                style: const TextStyle(fontSize: 13, color: NeonPalette.textSecondary),
              ),
              const SizedBox(height: 12),
              const Text(
                'v0.1.0 · M0–M2',
                style: TextStyle(fontSize: 12, color: NeonPalette.textMuted),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        // Renders nothing when no backend is configured, so the demo build is
        // untouched and this can sit here unconditionally.
        const SignOutButton(),
      ],
    );
  }
}

/// One row in the account section.
///
/// Deliberately flat: none of these carry a number worth glowing about, and
/// glow here would compete with the screens themselves, where it marks a
/// failed sign-in or a token you get one chance to copy.
class SettingsTile extends ConsumerWidget {
  const SettingsTile({
    super.key,
    required this.id,
    required this.titleKey,
    required this.hintKey,
    required this.icon,
    required this.accent,
  });

  final String id;
  final String titleKey;
  final String hintKey;
  final IconData icon;
  final Color accent;

  void _open(BuildContext context) {
    final route = switch (id) {
      'two-factor' => TwoFactorScreen.route(),
      'export' => DataExportScreen.route(),
      'delete-account' => AccountDeletionScreen.route(),
      'billing' => BillingScreen.route(),
      'members' => MaterialPageRoute<void>(builder: (_) => const MembersScreen()),
      'accept-invitation' =>
        MaterialPageRoute<void>(builder: (_) => const AcceptInvitationScreen()),
      'audit' => MaterialPageRoute<void>(builder: (_) => const AuditTrailScreen()),
      _ => MaterialPageRoute<void>(builder: (_) => const SecurityLogScreen()),
    };

    Navigator.of(context).push(route);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCardShell(
      key: ValueKey('settings-$id'),
      accent: accent,
      padding: const EdgeInsetsDirectional.all(16),
      onTap: () => _open(context),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(12),
              border: NeonEffects.border(accent, alpha: 0.3),
            ),
            child: Icon(icon, size: 19, color: accent),
          ),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  t(titleKey),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 14.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  t(hintKey),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 11.5,
                    height: 1.35,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          Icon(
            Directionality.of(context) == TextDirection.rtl
                ? Icons.chevron_left_rounded
                : Icons.chevron_right_rounded,
            size: 19,
            color: accent,
          ),
        ],
      ),
    );
  }
}
