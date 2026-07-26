import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/currency.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';

/// Create a tax rule set for one country.
///
/// Everything here is data: brackets, the two kinds of contribution and the
/// fixed deductions. Nothing in the calculation path knows which country it is
/// serving, which is why a new one needs a form rather than a release.
class TaxRuleFormScreen extends ConsumerStatefulWidget {
  const TaxRuleFormScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const TaxRuleFormScreen(),
      );

  @override
  ConsumerState<TaxRuleFormScreen> createState() => _TaxRuleFormScreenState();
}

/// One editable bracket row. `upTo` empty means the top bracket.
class _BracketRow {
  final upTo = TextEditingController();
  final rate = TextEditingController();

  void dispose() {
    upTo.dispose();
    rate.dispose();
  }
}

/// A percentage charge, on either side of the payslip.
class _ContributionRow {
  final code = TextEditingController();
  final label = TextEditingController();
  final rate = TextEditingController();
  final cap = TextEditingController();

  void dispose() {
    code.dispose();
    label.dispose();
    rate.dispose();
    cap.dispose();
  }
}

class _FixedRow {
  final code = TextEditingController();
  final label = TextEditingController();
  final amount = TextEditingController();

  void dispose() {
    code.dispose();
    label.dispose();
    amount.dispose();
  }
}

class _TaxRuleFormScreenState extends ConsumerState<TaxRuleFormScreen> {
  final _country = TextEditingController();
  final _name = TextEditingController();
  final _effectiveFrom = TextEditingController();

  final List<_BracketRow> _brackets = [_BracketRow()];
  final List<_ContributionRow> _employee = [];
  final List<_ContributionRow> _employer = [];
  final List<_FixedRow> _fixed = [];

  Currency _currency = Currency.try_;
  TaxBase _taxBase = TaxBase.gross;
  bool _isActive = true;
  bool _busy = false;
  String? _validationKey;
  String? _failureText;

  @override
  void dispose() {
    _country.dispose();
    _name.dispose();
    _effectiveFrom.dispose();
    for (final row in _brackets) {
      row.dispose();
    }
    for (final row in [..._employee, ..._employer]) {
      row.dispose();
    }
    for (final row in _fixed) {
      row.dispose();
    }
    super.dispose();
  }

  /// A percentage, not an amount — so it is a plain number and never money.
  static double? _percent(String raw) {
    final value = double.tryParse(raw.trim());
    if (value == null || value < 0 || value > 100) return null;
    return value;
  }

  Future<void> _submit() async {
    final country = _country.text.trim().toUpperCase();
    final effectiveText = _effectiveFrom.text.trim();
    final effectiveFrom =
        effectiveText.isEmpty ? null : DateEntryField.parse(effectiveText);

    if (country.length != 2) {
      setState(() {
        _validationKey = 'payroll.countryRequired';
        _failureText = null;
      });
      return;
    }

    if (effectiveText.isNotEmpty && effectiveFrom == null) {
      setState(() {
        _validationKey = 'payroll.dateInvalid';
        _failureText = null;
      });
      return;
    }

    final brackets = <TaxBracket>[];
    for (final row in _brackets) {
      if (row.rate.text.trim().isEmpty && row.upTo.text.trim().isEmpty) continue;
      final rate = _percent(row.rate.text);
      if (rate == null) {
        setState(() {
          _validationKey = 'payroll.rateInvalid';
          _failureText = null;
        });
        return;
      }
      final upToText = row.upTo.text.trim();
      final upTo =
          upToText.isEmpty ? null : AmountEntry.minorUnits(upToText, _currency);
      if (upToText.isNotEmpty && upTo == null) {
        setState(() {
          _validationKey = 'payroll.amountInvalid';
          _failureText = null;
        });
        return;
      }
      brackets.add(TaxBracket(rate: rate, upTo: upTo));
    }

    List<ContributionRule>? contributions(List<_ContributionRow> rows) {
      final parsed = <ContributionRule>[];
      for (final row in rows) {
        final code = row.code.text.trim();
        if (code.isEmpty) continue;
        final rate = _percent(row.rate.text);
        if (rate == null) return null;
        final capText = row.cap.text.trim();
        final cap =
            capText.isEmpty ? null : AmountEntry.minorUnits(capText, _currency);
        if (capText.isNotEmpty && cap == null) return null;
        parsed.add(
          ContributionRule(
            code: code,
            rate: rate,
            label: row.label.text.trim(),
            cap: cap,
          ),
        );
      }
      return parsed;
    }

    final employee = contributions(_employee);
    final employer = contributions(_employer);

    if (employee == null || employer == null) {
      setState(() {
        _validationKey = 'payroll.rateInvalid';
        _failureText = null;
      });
      return;
    }

    final fixed = <FixedDeductionRule>[];
    for (final row in _fixed) {
      final code = row.code.text.trim();
      if (code.isEmpty) continue;
      final amount = AmountEntry.minorUnits(row.amount.text, _currency);
      if (amount == null) {
        setState(() {
          _validationKey = 'payroll.amountInvalid';
          _failureText = null;
        });
        return;
      }
      fixed.add(
        FixedDeductionRule(
          code: code,
          amountMinorUnits: amount,
          label: row.label.text.trim(),
        ),
      );
    }

    setState(() {
      _busy = true;
      _validationKey = null;
      _failureText = null;
    });

    try {
      await ref.read(payrollRepositoryProvider).createTaxRuleSet(
            TaxRuleDraft(
              country: country,
              name: _name.text.trim(),
              currency: _currency,
              effectiveFrom: effectiveFrom,
              isActive: _isActive,
              taxBase: _taxBase,
              brackets: brackets,
              employeeContributions: employee,
              employerContributions: employer,
              fixedDeductions: fixed,
            ),
          );

      ref.invalidate(taxRulesProvider);

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

    return ModulePage(
      title: t('payroll.newTaxRule'),
      subtitle: t('payroll.newTaxRuleSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 40),
        children: [
          if (_failureText != null) PayrollNotice(message: _failureText!),
          if (_validationKey != null) PayrollNotice(message: t(_validationKey!)),
          PayrollField(
            label: t('payroll.country'),
            hint: t('payroll.countryNote'),
            child: TextField(
              key: const ValueKey('tax-country'),
              controller: _country,
              autocorrect: false,
              maxLength: 2,
              decoration: const InputDecoration(counterText: ''),
            ),
          ),
          PayrollField(
            label: t('payroll.name'),
            child: TextField(
              key: const ValueKey('tax-name'),
              controller: _name,
            ),
          ),
          PayrollField(
            label: t('payroll.currency'),
            hint: t('payroll.taxCurrencyNote'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final currency in Currency.all)
                  NeonChip(
                    key: ValueKey('tax-currency-${currency.code}'),
                    label: currency.code,
                    selected: _currency == currency,
                    onTap: () => setState(() => _currency = currency),
                  ),
              ],
            ),
          ),
          DateEntryField(
            fieldKey: const ValueKey('tax-effective-from'),
            controller: _effectiveFrom,
            locale: locale,
            label: t('payroll.effectiveFrom'),
          ),
          PayrollField(
            label: t('payroll.active'),
            hint: t('payroll.activeNote'),
            child: Wrap(
              spacing: 8,
              children: [
                NeonChip(
                  key: const ValueKey('tax-active-yes'),
                  label: t('payroll.yes'),
                  selected: _isActive,
                  onTap: () => setState(() => _isActive = true),
                ),
                NeonChip(
                  key: const ValueKey('tax-active-no'),
                  label: t('payroll.no'),
                  selected: !_isActive,
                  onTap: () => setState(() => _isActive = false),
                ),
              ],
            ),
          ),
          PayrollField(
            label: t('payroll.taxBase'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final base in TaxBase.values)
                  NeonChip(
                    key: ValueKey('tax-base-${base.name}'),
                    label: t(PayrollFormat.taxBaseKey(base)),
                    selected: _taxBase == base,
                    onTap: () => setState(() => _taxBase = base),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 6),
          SectionHeader(title: t('payroll.brackets')),
          Text(
            t('payroll.bracketsNote'),
            style: const TextStyle(fontSize: 11.5, height: 1.5),
          ),
          const SizedBox(height: 10),
          for (final (index, row) in _brackets.indexed) ...[
            Row(
              children: [
                Expanded(
                  child: PayrollField(
                    label: t('payroll.bracketCeiling'),
                    child: TextField(
                      key: ValueKey('tax-bracket-up-to-$index'),
                      controller: row.upTo,
                      keyboardType: TextInputType.number,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: PayrollField(
                    label: t('payroll.rate'),
                    child: TextField(
                      key: ValueKey('tax-bracket-rate-$index'),
                      controller: row.rate,
                      keyboardType: TextInputType.number,
                    ),
                  ),
                ),
              ],
            ),
          ],
          _AddRow(
            fieldKey: const ValueKey('tax-add-bracket'),
            label: t('payroll.addBracket'),
            onPressed: () => setState(() => _brackets.add(_BracketRow())),
          ),
          const SizedBox(height: 16),
          SectionHeader(
            title: t('payroll.employeeContributions'),
            accent: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
          ),
          Text(
            t('payroll.employeeContributionsNote'),
            style: const TextStyle(fontSize: 11.5, height: 1.5),
          ),
          const SizedBox(height: 10),
          for (final (index, row) in _employee.indexed)
            _ContributionFields(row: row, prefix: 'tax-employee', index: index),
          _AddRow(
            fieldKey: const ValueKey('tax-add-employee'),
            label: t('payroll.addContribution'),
            onPressed: () => setState(() => _employee.add(_ContributionRow())),
          ),
          const SizedBox(height: 16),
          SectionHeader(
            title: t('payroll.employerContributions'),
            accent: isDark ? NeonPalette.violet : NeonPalette.lightViolet,
          ),
          Text(
            t('payroll.contributionsNotInNet'),
            style: const TextStyle(fontSize: 11.5, height: 1.5),
          ),
          const SizedBox(height: 10),
          for (final (index, row) in _employer.indexed)
            _ContributionFields(row: row, prefix: 'tax-employer', index: index),
          _AddRow(
            fieldKey: const ValueKey('tax-add-employer'),
            label: t('payroll.addContribution'),
            onPressed: () => setState(() => _employer.add(_ContributionRow())),
          ),
          const SizedBox(height: 16),
          SectionHeader(title: t('payroll.fixedDeductions')),
          const SizedBox(height: 10),
          for (final (index, row) in _fixed.indexed) ...[
            PayrollField(
              label: t('payroll.code'),
              child: TextField(
                key: ValueKey('tax-fixed-code-$index'),
                controller: row.code,
                autocorrect: false,
              ),
            ),
            PayrollField(
              label: t('payroll.label'),
              child: TextField(
                key: ValueKey('tax-fixed-label-$index'),
                controller: row.label,
              ),
            ),
            PayrollField(
              label: t('payroll.amount'),
              hint: t('payroll.amountNote'),
              child: TextField(
                key: ValueKey('tax-fixed-amount-$index'),
                controller: row.amount,
                keyboardType: TextInputType.number,
              ),
            ),
          ],
          _AddRow(
            fieldKey: const ValueKey('tax-add-fixed'),
            label: t('payroll.addFixedDeduction'),
            onPressed: () => setState(() => _fixed.add(_FixedRow())),
          ),
          const SizedBox(height: 18),
          NeonButton(
            key: const ValueKey('tax-save'),
            label: t('payroll.save'),
            icon: Icons.check_rounded,
            expand: true,
            busy: _busy,
            onPressed: _busy ? null : _submit,
          ),
        ],
      ),
    );
  }
}

class _ContributionFields extends ConsumerWidget {
  const _ContributionFields({
    required this.row,
    required this.prefix,
    required this.index,
  });

  final _ContributionRow row;
  final String prefix;
  final int index;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: PayrollField(
                label: t('payroll.code'),
                child: TextField(
                  key: ValueKey('$prefix-code-$index'),
                  controller: row.code,
                  autocorrect: false,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: PayrollField(
                label: t('payroll.rate'),
                child: TextField(
                  key: ValueKey('$prefix-rate-$index'),
                  controller: row.rate,
                  keyboardType: TextInputType.number,
                ),
              ),
            ),
          ],
        ),
        Row(
          children: [
            Expanded(
              child: PayrollField(
                label: t('payroll.label'),
                child: TextField(
                  key: ValueKey('$prefix-label-$index'),
                  controller: row.label,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: PayrollField(
                label: t('payroll.cap'),
                child: TextField(
                  key: ValueKey('$prefix-cap-$index'),
                  controller: row.cap,
                  keyboardType: TextInputType.number,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _AddRow extends StatelessWidget {
  const _AddRow({
    required this.fieldKey,
    required this.label,
    required this.onPressed,
  });

  final Key fieldKey;
  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => Align(
        alignment: AlignmentDirectional.centerStart,
        child: NeonButton(
          key: fieldKey,
          label: label,
          icon: Icons.add_rounded,
          variant: NeonButtonVariant.ghost,
          onPressed: onPressed,
        ),
      );
}
