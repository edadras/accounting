import 'package:flutter/material.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/currency.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/remote/coded_failure.dart';
import '../members/access_notice.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';

/// Whatever a payroll call threw, turned into something a person can act on.
///
/// A refusal by role gets the shared lock panel; everything else is explained
/// through [payrollFailureText], which resolves the code and never renders the
/// server's English prose. A build with no server at all lands here too, and
/// gets a sentence rather than a crash.
Widget payrollFailurePanel(Object error, Translator t) {
  if (error is CodedFailure && error.isPermissionDenied) {
    return NoticePanel(
      icon: Icons.lock_person_rounded,
      title: t('payroll.noAccessTitle'),
      body: t('payroll.noAccessBody'),
    );
  }

  return NoticePanel(
    icon: Icons.error_outline_rounded,
    accent: NeonPalette.magenta,
    title: t('common.error'),
    body: payrollFailureText(t, error),
  );
}

/// Label above a control. Every payroll form uses this, so the spacing and the
/// muted-label colour stay identical across four screens.
class PayrollField extends StatelessWidget {
  const PayrollField({
    super.key,
    required this.label,
    required this.child,
    this.hint,
  });

  final String label;
  final Widget child;
  final String? hint;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: isDark
                  ? NeonPalette.textSecondary
                  : NeonPalette.lightTextSecondary,
            ),
          ),
          const SizedBox(height: 7),
          child,
          if (hint != null) ...[
            const SizedBox(height: 6),
            Text(
              hint!,
              style: TextStyle(
                fontSize: 11.5,
                height: 1.5,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// A refusal or a validation complaint, always already translated.
class PayrollNotice extends StatelessWidget {
  const PayrollNotice({
    super.key,
    required this.message,
    this.accent = NeonPalette.magenta,
    this.icon = Icons.error_outline_rounded,
  });

  final String message;
  final Color accent;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.only(bottom: 12),
        child: Container(
          padding:
              const EdgeInsetsDirectional.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            color: accent.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: accent.withValues(alpha: 0.35)),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 16, color: accent),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  message,
                  style: TextStyle(fontSize: 12, height: 1.5, color: accent),
                ),
              ),
            ],
          ),
        ),
      );
}

/// An ISO date typed in, previewed in the reader's own calendar.
///
/// A Gregorian date picker would show a Persian user a calendar they do not
/// keep appointments in, so the field takes `2026-07-01` and renders
/// `۱۴۰۵/۰۴/۱۰` underneath it — the machine format goes to the server, the
/// human one is what gets checked by eye.
class DateEntryField extends StatelessWidget {
  const DateEntryField({
    super.key,
    required this.controller,
    required this.locale,
    required this.label,
    this.fieldKey,
  });

  final TextEditingController controller;
  final AppLocale locale;
  final String label;
  final Key? fieldKey;

  static DateTime? parse(String raw) {
    final text = raw.trim();
    if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(text)) return null;
    return DateTime.tryParse(text);
  }

  @override
  Widget build(BuildContext context) => PayrollField(
        label: label,
        hint: switch (parse(controller.text)) {
          final DateTime value => PayrollFormat.date(value, locale),
          _ => null,
        },
        child: TextField(
          key: fieldKey,
          controller: controller,
          keyboardType: TextInputType.datetime,
          autocorrect: false,
          decoration: const InputDecoration(hintText: '2026-07-01'),
        ),
      );
}

/// An amount typed in major units and carried as an integer.
///
/// `Money.tryParse` refuses anything with more precision than the currency has,
/// which is why nothing here ever sees a `double`.
abstract final class AmountEntry {
  static int? minorUnits(String raw, Currency currency) =>
      Money.tryParse(raw, currency)?.minorUnits;
}
