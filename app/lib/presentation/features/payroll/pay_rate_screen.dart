import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/currency.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';

/// Record a new pay rate.
///
/// The screen is built around one sentence: this appends, it does not
/// overwrite. The rate being superseded is shown beside the new one so it is
/// clear that the old figure survives, which is what keeps a run that already
/// happened from being restated by a raise.
class PayRateScreen extends ConsumerStatefulWidget {
  const PayRateScreen({super.key, required this.employee});

  final Employee employee;

  static Route<void> route({required Employee employee}) =>
      MaterialPageRoute<void>(
        builder: (_) => PayRateScreen(employee: employee),
      );

  @override
  ConsumerState<PayRateScreen> createState() => _PayRateScreenState();
}

class _PayRateScreenState extends ConsumerState<PayRateScreen> {
  final _amount = TextEditingController();
  final _effectiveFrom = TextEditingController();

  late Currency _currency =
      widget.employee.compensation?.amount.currency ?? Currency.try_;
  late PayPeriodKind _period =
      widget.employee.compensation?.period ?? PayPeriodKind.monthly;

  bool _busy = false;
  String? _validationKey;
  String? _failureText;

  @override
  void dispose() {
    _amount.dispose();
    _effectiveFrom.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final minorUnits = AmountEntry.minorUnits(_amount.text, _currency);
    final effectiveText = _effectiveFrom.text.trim();
    final effectiveFrom =
        effectiveText.isEmpty ? null : DateEntryField.parse(effectiveText);

    final validation = switch (minorUnits) {
      null => 'payroll.amountInvalid',
      _ when effectiveText.isNotEmpty && effectiveFrom == null =>
        'payroll.dateInvalid',
      _ => null,
    };

    if (validation != null) {
      setState(() {
        _validationKey = validation;
        _failureText = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _validationKey = null;
      _failureText = null;
    });

    try {
      await ref.read(payrollRepositoryProvider).setCompensation(
            widget.employee.id,
            CompensationDraft(
              amountMinorUnits: minorUnits!,
              currency: _currency,
              period: _period,
              effectiveFrom: effectiveFrom,
            ),
          );

      ref.invalidate(employeeProvider(widget.employee.id));
      ref.invalidate(employeesProvider);

      if (!mounted) return;
      Navigator.of(context).pop();
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _failureText = payrollFailureText(ref.read(translatorProvider), error);
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final current = widget.employee.compensation;

    return ModulePage(
      title: t('payroll.newRate'),
      subtitle: widget.employee.name,
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 40),
        children: [
          if (_failureText != null) PayrollNotice(message: _failureText!),
          if (_validationKey != null) PayrollNotice(message: t(_validationKey!)),
          NeonCard(
            accent: isDark ? NeonPalette.violet : NeonPalette.lightViolet,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  t('payroll.appendsNotReplaces'),
                  key: const ValueKey('rate-append-note'),
                  style: TextStyle(
                    fontSize: 12.5,
                    height: 1.6,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
                if (current != null) ...[
                  const SizedBox(height: 12),
                  DetailRow(
                    label: t('payroll.currentRate'),
                    value: PayrollFormat.money(current.amount, locale),
                    strong: true,
                  ),
                  DetailRow(
                    label: t('payroll.ratePeriod'),
                    value: t(PayrollFormat.periodKey(current.period)),
                  ),
                  DetailRow(
                    label: t('payroll.effectiveFrom'),
                    value: PayrollFormat.date(current.effectiveFrom, locale),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 20),
          PayrollField(
            label: t('payroll.amount'),
            hint: t('payroll.amountNote'),
            child: TextField(
              key: const ValueKey('rate-amount'),
              controller: _amount,
              keyboardType: TextInputType.number,
            ),
          ),
          PayrollField(
            label: t('payroll.currency'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final currency in Currency.all)
                  NeonChip(
                    key: ValueKey('rate-currency-${currency.code}'),
                    label: currency.code,
                    selected: _currency == currency,
                    onTap: () => setState(() => _currency = currency),
                  ),
              ],
            ),
          ),
          PayrollField(
            label: t('payroll.ratePeriod'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final period in PayPeriodKind.values)
                  NeonChip(
                    key: ValueKey('rate-period-${period.name}'),
                    label: t(PayrollFormat.periodKey(period)),
                    selected: _period == period,
                    onTap: () => setState(() => _period = period),
                  ),
              ],
            ),
          ),
          DateEntryField(
            fieldKey: const ValueKey('rate-effective-from'),
            controller: _effectiveFrom,
            locale: locale,
            label: t('payroll.effectiveFrom'),
          ),
          const SizedBox(height: 10),
          NeonButton(
            key: const ValueKey('rate-save'),
            label: t('payroll.recordRate'),
            icon: Icons.trending_up_rounded,
            expand: true,
            busy: _busy,
            onPressed: _busy ? null : _submit,
          ),
        ],
      ),
    );
  }
}
