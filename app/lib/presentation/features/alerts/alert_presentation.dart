import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/alerts_repository.dart';

/// Amber has no darkened twin in the palette, and neon amber on white is
/// unreadable. This is the light-mode stand-in, kept here rather than in the
/// shared theme so the alerts feature does not reach into it.
const Color _lightAmber = Color(0xFF8A5300);

/// The light theme is a muted translation of the same hues; a neon accent
/// dropped straight onto white fails contrast.
Color alertAccentFor(Color accent, {required bool isDark}) {
  if (isDark) return accent;

  return switch (accent) {
    NeonPalette.cyan => NeonPalette.lightCyan,
    NeonPalette.magenta => NeonPalette.lightMagenta,
    NeonPalette.lime => NeonPalette.lightLime,
    NeonPalette.violet => NeonPalette.lightViolet,
    NeonPalette.amber => _lightAmber,
    _ => accent,
  };
}

/// Icon and colour per kind of alert.
///
/// Amber for the two that are about a date coming at you, magenta for a budget
/// already breached, cyan for a balance. An alert of a kind this build predates
/// still gets a bell rather than nothing.
(IconData, Color) alertIconAndAccent(AlertRuleType? type) => switch (type) {
      AlertRuleType.checkDue => (Icons.receipt_long_rounded, NeonPalette.amber),
      AlertRuleType.installmentDue => (
          Icons.event_repeat_rounded,
          NeonPalette.violet,
        ),
      AlertRuleType.budgetThreshold => (
          Icons.donut_large_rounded,
          NeonPalette.magenta,
        ),
      AlertRuleType.lowBalance => (
          Icons.account_balance_wallet_rounded,
          NeonPalette.cyan,
        ),
      null => (Icons.notifications_active_rounded, NeonPalette.cyan),
    };

String alertTypeKey(AlertRuleType? type) => switch (type) {
      AlertRuleType.checkDue => 'alerts.typeCheckDue',
      AlertRuleType.installmentDue => 'alerts.typeInstallmentDue',
      AlertRuleType.budgetThreshold => 'alerts.typeBudgetThreshold',
      AlertRuleType.lowBalance => 'alerts.typeLowBalance',
      null => 'alerts.typeUnknown',
    };

String alertChannelKey(AlertChannel channel) => switch (channel) {
      AlertChannel.database => 'alerts.channelDatabase',
      AlertChannel.push => 'alerts.channelPush',
      AlertChannel.email => 'alerts.channelEmail',
      AlertChannel.sms => 'alerts.channelSms',
      AlertChannel.telegram => 'alerts.channelTelegram',
      AlertChannel.whatsapp => 'alerts.channelWhatsapp',
    };

/// The headline of one alert, built from the payload its own scanner wrote.
///
/// Every branch goes through the translator; the only untranslated fragments
/// are the workspace's own words — an account name, a cheque number — which are
/// data, not wording.
String alertTitle(AlertItem alert, Translator t) {
  final number = alert.text('check_number') ?? alert.integer('number');

  return switch (alert.type) {
    AlertRuleType.checkDue => number == null
        ? t('alerts.checkTitleAnonymous')
        : t('alerts.checkTitle', args: {'number': '$number'}),
    AlertRuleType.installmentDue => t(
        'alerts.installmentTitle',
        args: {'number': '${alert.integer('number') ?? 0}'},
      ),
    AlertRuleType.budgetThreshold => t(
        'alerts.budgetTitle',
        args: {'name': alert.text('budget_name') ?? t('alerts.typeUnknown')},
      ),
    AlertRuleType.lowBalance => t(
        'alerts.balanceTitle',
        args: {'name': alert.text('account_name') ?? t('alerts.typeUnknown')},
      ),
    null => t('alerts.typeUnknown'),
  };
}

/// One `label … value` line under an alert's headline.
final class AlertDetail {
  const AlertDetail(this.label, this.value, {this.accent});

  final String label;
  final String value;
  final Color? accent;
}

/// The payload, spelled out.
///
/// Money is rendered from the integer minor units the server sent, never from
/// the `decimal` string beside it, and never through a double.
List<AlertDetail> alertDetails(
  AlertItem alert, {
  required Translator t,
  required AppLocale locale,
}) {
  String? amount(String key) {
    final money = alert.money(key);
    return money == null
        ? null
        : MoneyFormatter.format(money, locale: locale.code);
  }

  final rows = <AlertDetail>[];

  void add(String labelKey, String? value, {Color? accent}) {
    if (value != null && value.isNotEmpty) {
      rows.add(AlertDetail(t(labelKey), value, accent: accent));
    }
  }

  switch (alert.type) {
    case AlertRuleType.checkDue:
      add('alerts.party', alert.text('party_name'));
      add('alerts.direction', _directionLabel(alert.text('direction'), t));
      add('tx.amount', amount('amount'));
      add('alerts.dueDate', _dueDate(alert, locale));
    case AlertRuleType.installmentDue:
      add('alerts.remaining', amount('remaining'));
      add('alerts.dueDate', _dueDate(alert, locale));
    case AlertRuleType.budgetThreshold:
      final percent = alert.integer('percent');
      final threshold = alert.integer('threshold');
      add(
        'alerts.usage',
        percent == null
            ? null
            : t(
                'alerts.percentUsed',
                args: {'percent': DateFormatter.number(percent, locale.code)},
              ),
      );
      add(
        'alerts.crossed',
        threshold == null
            ? null
            : t(
                'alerts.percentUsed',
                args: {'percent': DateFormatter.number(threshold, locale.code)},
              ),
      );
      add('alerts.spent', amount('spent'));
      add('alerts.limit', amount('limit'));
      add('alerts.period', alert.text('period_key'));
    case AlertRuleType.lowBalance:
      add('alerts.balance', amount('balance'));
      add('alerts.floor', amount('threshold'));
    case null:
      break;
  }

  return rows;
}

/// "in 4 days" / "today" / "9 days overdue" — the only question a due date is
/// really asked. The count comes from the server's own `days_ahead` so the app
/// and the scanner never disagree by a timezone.
String? alertDueBadge(
  AlertItem alert, {
  required Translator t,
  required AppLocale locale,
}) {
  final days = alert.integer('days_ahead');
  if (days == null) return null;

  if (days == 0) return t('alerts.dueToday');

  return days > 0
      ? t(
          'alerts.dueIn',
          args: {'days': DateFormatter.number(days, locale.code)},
        )
      : t(
          'alerts.overdueBy',
          args: {'days': DateFormatter.number(-days, locale.code)},
        );
}

String? _dueDate(AlertItem alert, AppLocale locale) {
  final due = alert.date('due_date');
  return due == null ? null : DateFormatter.short(due, locale);
}

/// `Check::DIRECTIONS` — a cheque you hold, one you wrote, or one lodged as
/// security. Anything else from a newer server is left off the card rather than
/// printed in English.
String? _directionLabel(String? direction, Translator t) => switch (direction) {
      'received' => t('alerts.directionReceived'),
      'issued' => t('alerts.directionIssued'),
      'guarantee' => t('alerts.directionGuarantee'),
      _ => null,
    };
