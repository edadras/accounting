import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/alerts_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'alert_preferences_screen.dart';
import 'alert_presentation.dart';
import 'alert_rules_screen.dart';
import 'alerts_providers.dart';

/// The alert inbox: what the workspace noticed, newest owed decision first.
///
/// Unread rows sort above read ones regardless of date, because this is a queue
/// of things still owed an answer rather than a chronology. They are also the
/// only rows that glow — an unread warning is information, a settled one is
/// history.
class AlertsScreen extends ConsumerWidget {
  const AlertsScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const AlertsScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final inbox = ref.watch(alertInboxProvider);

    return ModulePage(
      title: t('alerts.title'),
      subtitle: t('alerts.subtitle'),
      child: Column(
        children: [
          const _InboxToolbar(),
          Expanded(
            child: inbox.when(
              loading: () => const ModuleLoading(),
              error: (error, _) => noticeForFailure(
                error,
                t: t,
                permissionTitle: t('alerts.noAccessTitle'),
                permissionBody: t('alerts.noAccessBody'),
              ),
              data: (alerts) => _InboxList(alerts: alerts),
            ),
          ),
        ],
      ),
    );
  }
}

/// The two doors out of the inbox, and the filters the API itself supports.
class _InboxToolbar extends ConsumerWidget {
  const _InboxToolbar();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final unreadOnly = ref.watch(alertUnreadFilterProvider);
    final type = ref.watch(alertTypeFilterProvider);

    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: NeonButton(
                  key: const ValueKey('alerts-open-rules'),
                  label: t('alerts.rules'),
                  icon: Icons.rule_rounded,
                  variant: NeonButtonVariant.outline,
                  expand: true,
                  onPressed: () =>
                      Navigator.of(context).push(AlertRulesScreen.route()),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: NeonButton(
                  key: const ValueKey('alerts-open-preferences'),
                  label: t('alerts.preferences'),
                  icon: Icons.tune_rounded,
                  variant: NeonButtonVariant.outline,
                  accent: NeonPalette.violet,
                  expand: true,
                  onPressed: () => Navigator.of(context)
                      .push(AlertPreferencesScreen.route()),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                NeonChip(
                  key: const ValueKey('alerts-filter-unread'),
                  label: t('alerts.filterUnread'),
                  icon: Icons.mark_email_unread_rounded,
                  accent: alertAccentFor(NeonPalette.amber, isDark: isDark),
                  selected: unreadOnly,
                  onTap: () => ref
                      .read(alertUnreadFilterProvider.notifier)
                      .state = !unreadOnly,
                ),
                const SizedBox(width: 8),
                NeonChip(
                  key: const ValueKey('alerts-filter-all-types'),
                  label: t('alerts.filterAllTypes'),
                  accent: alertAccentFor(NeonPalette.cyan, isDark: isDark),
                  selected: type == null,
                  onTap: () =>
                      ref.read(alertTypeFilterProvider.notifier).state = null,
                ),
                for (final option in AlertRuleType.values) ...[
                  const SizedBox(width: 8),
                  NeonChip(
                    key: ValueKey('alerts-filter-${option.wire}'),
                    label: t(alertTypeKey(option)),
                    accent: alertAccentFor(
                      alertIconAndAccent(option).$2,
                      isDark: isDark,
                    ),
                    selected: type == option,
                    onTap: () => ref
                        .read(alertTypeFilterProvider.notifier)
                        .state = option,
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

class _InboxList extends ConsumerWidget {
  const _InboxList({required this.alerts});

  final List<AlertItem> alerts;

  Future<void> _markRead(
    BuildContext context,
    WidgetRef ref,
    AlertItem alert,
  ) async {
    final t = ref.read(translatorProvider);

    try {
      await ref.read(alertsRepositoryProvider).markRead(alert.id);

      // Refetched rather than patched in place: the same alert may have been
      // read on another device, and the server is the authority on that.
      ref.invalidate(alertInboxProvider);
    } on AlertsException catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text(t(error.translationKey))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    if (alerts.isEmpty) {
      return _InboxEmpty(
        unreadOnly: ref.watch(alertUnreadFilterProvider),
        message: ref.watch(alertUnreadFilterProvider)
            ? t('alerts.emptyUnread')
            : t('alerts.empty'),
        hint: t('alerts.emptyHint'),
      );
    }

    return ListView.builder(
      key: const ValueKey('alerts-list'),
      padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 40),
      itemCount: alerts.length,
      itemBuilder: (context, index) {
        final alert = alerts[index];

        return Padding(
          padding: const EdgeInsetsDirectional.only(bottom: 12),
          child: _AlertCard(
            alert: alert,
            onMarkRead: () => _markRead(context, ref, alert),
          ),
        );
      },
    );
  }
}

class _InboxEmpty extends StatelessWidget {
  const _InboxEmpty({
    required this.unreadOnly,
    required this.message,
    required this.hint,
  });

  final bool unreadOnly;
  final String message;
  final String hint;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsetsDirectional.fromSTEB(24, 24, 24, 24),
        child: Column(
          key: const ValueKey('alerts-empty'),
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              unreadOnly
                  ? Icons.mark_email_read_rounded
                  : Icons.notifications_none_rounded,
              size: 40,
              color: isDark
                  ? NeonPalette.textMuted
                  : NeonPalette.lightTextSecondary,
            ),
            const SizedBox(height: 14),
            Text(
              message,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: isDark
                    ? NeonPalette.textPrimary
                    : NeonPalette.lightTextPrimary,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              hint,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 12,
                height: 1.6,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AlertCard extends ConsumerWidget {
  const _AlertCard({required this.alert, required this.onMarkRead});

  final AlertItem alert;
  final VoidCallback onMarkRead;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final (icon, rawAccent) = alertIconAndAccent(alert.type);
    final accent = alertAccentFor(rawAccent, isDark: isDark);
    final muted =
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary;

    final details = alertDetails(alert, t: t, locale: locale);
    final due = alertDueBadge(alert, t: t, locale: locale);

    return NeonCardShell(
      key: ValueKey('alert-${alert.id}'),
      accent: alert.isUnread ? accent : muted,
      // Only an unread alert glows. A read one is a record, and a screen where
      // everything glows says nothing.
      glow: alert.isUnread,
      onTap: alert.isUnread ? onMarkRead : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 18, color: alert.isUnread ? accent : muted),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      alertTitle(alert, t),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 14,
                        fontWeight:
                            alert.isUnread ? FontWeight.w800 : FontWeight.w600,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      t(alertTypeKey(alert.type)),
                      style: TextStyle(fontSize: 11, color: muted),
                    ),
                  ],
                ),
              ),
              if (due != null) ...[
                const SizedBox(width: 8),
                NeonChip(
                  label: due,
                  accent: accent,
                  selected: alert.isUnread,
                ),
              ],
            ],
          ),
          if (details.isNotEmpty) ...[
            const SizedBox(height: 8),
            for (final detail in details)
              DetailRow(
                label: detail.label,
                value: detail.value,
                accent: detail.accent,
              ),
          ],
          const SizedBox(height: 10),
          _DeliveryStrip(alert: alert),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  alert.isDeferred
                      ? t(
                          'alerts.deferredUntil',
                          args: {
                            'date':
                                DateFormatter.short(alert.scheduledAt, locale),
                          },
                        )
                      : t(
                          'alerts.raisedOn',
                          args: {
                            'date':
                                DateFormatter.short(alert.scheduledAt, locale),
                          },
                        ),
                  style: TextStyle(fontSize: 11, color: muted),
                ),
              ),
              if (alert.isUnread)
                Semantics(
                  button: true,
                  label: t('alerts.markRead'),
                  child: GestureDetector(
                    key: ValueKey('alert-read-${alert.id}'),
                    onTap: onMarkRead,
                    child: Container(
                      padding: const EdgeInsetsDirectional.symmetric(
                        horizontal: 12,
                        vertical: 7,
                      ),
                      decoration: BoxDecoration(
                        color: accent.withValues(alpha: 0.12),
                        borderRadius:
                            BorderRadius.circular(NeonEffects.radiusSm),
                        border: NeonEffects.border(accent, alpha: 0.35),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.done_rounded,
                            size: 14,
                            color: accent,
                          ),
                          const SizedBox(width: 6),
                          Text(
                            t('alerts.markRead'),
                            style: TextStyle(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              color: accent,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                )
              else
                Text(
                  t('alerts.read'),
                  style: TextStyle(fontSize: 11, color: muted),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Where this alert did and did not arrive.
///
/// A failed channel is worth saying out loud: the alert exists in the app
/// either way, but "we could not text you" is the difference between a user who
/// knows and one who thinks they were never told.
class _DeliveryStrip extends ConsumerWidget {
  const _DeliveryStrip({required this.alert});

  final AlertItem alert;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (alert.channels.isEmpty) return const SizedBox.shrink();

    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final entry in alert.channels.entries)
          _DeliveryChip(
            label: t(alertChannelKey(entry.key)),
            status: entry.value,
            isDark: isDark,
          ),
      ],
    );
  }
}

class _DeliveryChip extends StatelessWidget {
  const _DeliveryChip({
    required this.label,
    required this.status,
    required this.isDark,
  });

  final String label;
  final AlertDelivery status;
  final bool isDark;

  @override
  Widget build(BuildContext context) {
    final accent = switch (status) {
      AlertDelivery.sent => alertAccentFor(NeonPalette.lime, isDark: isDark),
      AlertDelivery.failed =>
        alertAccentFor(NeonPalette.magenta, isDark: isDark),
      AlertDelivery.pending =>
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
    };

    final icon = switch (status) {
      AlertDelivery.sent => Icons.check_circle_outline_rounded,
      AlertDelivery.failed => Icons.error_outline_rounded,
      AlertDelivery.pending => Icons.schedule_rounded,
    };

    return Container(
      padding: const EdgeInsetsDirectional.symmetric(
        horizontal: 9,
        vertical: 5,
      ),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        border: NeonEffects.border(accent, alpha: 0.30),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: accent),
          const SizedBox(width: 5),
          Text(
            label,
            style: TextStyle(
              fontSize: 10.5,
              fontWeight: FontWeight.w600,
              color: accent,
            ),
          ),
        ],
      ),
    );
  }
}
