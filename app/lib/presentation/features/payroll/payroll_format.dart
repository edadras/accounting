import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/money/money.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';

/// Wording and colour for the payroll vocabulary.
///
/// Every label here is a translation key rather than a string: the file that
/// holds words is `translations.dart`, and this one only decides which key.
abstract final class PayrollFormat {
  /// Amounts are formatted from the integer the server sent. There is no path
  /// through this file that adds two amounts together.
  static String money(Money amount, AppLocale locale) =>
      MoneyFormatter.format(amount, locale: locale.code);

  static String date(DateTime? value, AppLocale locale) =>
      value == null ? '—' : DateFormatter.short(value, locale);

  static String count(int value, AppLocale locale) =>
      DateFormatter.number(value, locale.code);

  /// `20%` / `٪۲۰` — a rate, not an amount, so no currency is involved.
  static String percent(double rate, AppLocale locale) {
    final rounded = rate.roundToDouble();
    final text = rate == rounded
        ? rounded.toStringAsFixed(0)
        : rate.toStringAsFixed(2).replaceAll(RegExp(r'0+$'), '');
    final digits = _localizeDigits(text, locale.code);
    return locale.code == 'en' || locale.code == 'tr' ? '$digits%' : '٪$digits';
  }

  static String runStatusKey(PayrollRunStatus status) =>
      'payroll.status.${status.name}';

  static String employmentStatusKey(EmploymentStatus status) =>
      'payroll.employment.${status.name}';

  static String periodKey(PayPeriodKind period) =>
      'payroll.period.${period.name}';

  static String lineKindKey(PayslipLineKind kind) =>
      'payroll.lineKind.${kind.name}';

  static String taxBaseKey(TaxBase base) =>
      base == TaxBase.gross ? 'payroll.taxBaseGross' : 'payroll.taxBaseNetOfNi';

  /// Colour carries the state, and it is the same in every list: a draft is
  /// provisional (cyan), an approved run has reached the ledger (amber, it owes
  /// money), a paid one is settled (lime).
  static Color runAccent(PayrollRunStatus status, {required bool isDark}) =>
      switch (status) {
        PayrollRunStatus.draft => isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
        PayrollRunStatus.approved => NeonPalette.amber,
        PayrollRunStatus.paid => isDark ? NeonPalette.lime : NeonPalette.lightLime,
      };

  static Color employmentAccent(EmploymentStatus status, {required bool isDark}) =>
      switch (status) {
        EmploymentStatus.active => isDark ? NeonPalette.lime : NeonPalette.lightLime,
        EmploymentStatus.onLeave => NeonPalette.amber,
        EmploymentStatus.ended =>
          isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
      };

  /// Earnings and deductions are the employee's two directions; a contribution
  /// is the employer's, and gets a colour of its own so it never reads as one
  /// of the other two.
  static Color lineAccent(PayslipLineKind kind, {required bool isDark}) =>
      switch (kind) {
        PayslipLineKind.earning => isDark ? NeonPalette.lime : NeonPalette.lightLime,
        PayslipLineKind.deduction =>
          isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
        PayslipLineKind.contribution =>
          isDark ? NeonPalette.violet : NeonPalette.lightViolet,
      };

  /// A run that owes real money but has not been approved is the one thing on
  /// these screens worth glowing about. A paid run never glows: it is settled,
  /// and glow here is information, not decoration.
  static bool shouldGlow(PayrollRun run) =>
      run.status.isDraft && run.employerCost.minorUnits > 0;

  static String _localizeDigits(String input, String localeCode) {
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    const arabic = '٠١٢٣٤٥٦٧٨٩';
    final target = switch (localeCode) {
      'fa' => persian,
      'ar' => arabic,
      _ => null,
    };
    if (target == null) return input;

    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final code = rune - 0x30;
      buffer.write(
        code >= 0 && code <= 9 ? target[code] : String.fromCharCode(rune),
      );
    }
    return buffer.toString();
  }
}
