import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/recurring_repository.dart';
import '../../../domain/entities.dart';

/// Amber has no darkened twin in the palette and neon amber on white is
/// unreadable; this is the light-mode stand-in.
const Color _lightAmber = Color(0xFF8A5300);

/// The light theme is a muted translation of the same hues, so an accent picked
/// for the dark UI has to be swapped rather than dimmed.
Color recurringAccentFor(Color accent, {required bool isDark}) {
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

Color transactionAccent(TransactionType type) => switch (type) {
      TransactionType.income => NeonPalette.income,
      TransactionType.expense => NeonPalette.expense,
      TransactionType.transfer => NeonPalette.transfer,
    };

IconData transactionIcon(TransactionType type) => switch (type) {
      TransactionType.income => Icons.south_west_rounded,
      TransactionType.expense => Icons.north_east_rounded,
      TransactionType.transfer => Icons.swap_horiz_rounded,
    };

/// Reuses the transaction namespace: a recurring expense is an expense, and
/// giving it a second word for the same thing would be its own bug.
String transactionTypeKey(TransactionType type) => switch (type) {
      TransactionType.income => 'tx.income',
      TransactionType.expense => 'tx.expense',
      TransactionType.transfer => 'tx.transfer',
    };

String frequencyKey(RecurringFrequency frequency) => switch (frequency) {
      RecurringFrequency.daily => 'recurring.freqDaily',
      RecurringFrequency.weekly => 'recurring.freqWeekly',
      RecurringFrequency.monthly => 'recurring.freqMonthly',
      RecurringFrequency.yearly => 'recurring.freqYearly',
    };

/// "Every 2 months, on the 5th" — the whole schedule in one line.
///
/// Only says what the server can actually compute. There is no weekday in it,
/// because `occurrenceAfter()` adds whole weeks to the previous occurrence and
/// never pins one to a Tuesday.
String scheduleSummary(
  RecurringFrequency frequency,
  int interval,
  int? dayOfMonth, {
  required Translator t,
  required AppLocale locale,
}) {
  final count = DateFormatter.number(interval < 1 ? 1 : interval, locale.code);

  final every = switch (frequency) {
    RecurringFrequency.daily => t('recurring.everyDays', args: {'count': count}),
    RecurringFrequency.weekly =>
      t('recurring.everyWeeks', args: {'count': count}),
    RecurringFrequency.monthly =>
      t('recurring.everyMonths', args: {'count': count}),
    RecurringFrequency.yearly =>
      t('recurring.everyYears', args: {'count': count}),
  };

  if (!frequency.usesDayOfMonth || dayOfMonth == null) return every;

  // Even the separator is translated: punctuation between two clauses is a
  // typographic choice each language makes for itself, and hard-coding one here
  // would put it on the wrong side of an RTL line.
  return t(
    'recurring.scheduleWithDay',
    args: {
      'every': every,
      'day': t(
        'recurring.onDay',
        args: {'day': DateFormatter.number(dayOfMonth, locale.code)},
      ),
    },
  );
}

/// "in 4 days" / "today" / "9 days late", or null once the rule has run out.
String? nextRunBadge(
  RecurringRule rule, {
  required DateTime now,
  required Translator t,
  required AppLocale locale,
}) {
  final next = rule.nextRunAt;
  if (next == null) return null;

  final days = DateFormatter.daysUntil(next, now);

  if (days == 0) return t('recurring.runsToday');

  return days > 0
      ? t(
          'recurring.runsIn',
          args: {'days': DateFormatter.number(days, locale.code)},
        )
      : t(
          'recurring.overdueBy',
          args: {'days': DateFormatter.number(-days, locale.code)},
        );
}
