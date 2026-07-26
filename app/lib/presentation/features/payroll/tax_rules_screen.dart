import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';
import 'tax_rule_form_screen.dart';

/// Tax rule sets, per country.
///
/// Brackets, contributions and fixed deductions are data rather than code,
/// which is what makes the calculation pluggable instead of one country's law
/// written into it. A payslip records which set produced it, so amending a set
/// here cannot restate a run that has already been approved.
class TaxRulesScreen extends ConsumerWidget {
  const TaxRulesScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const TaxRulesScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final rules = ref.watch(taxRulesProvider);
    final locale = ref.watch(localeProvider);

    return ModulePage(
      title: t('payroll.taxRules'),
      subtitle: t('payroll.taxRulesSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          NeonButton(
            key: const ValueKey('tax-rule-new'),
            label: t('payroll.newTaxRule'),
            icon: Icons.rule_rounded,
            expand: true,
            onPressed: () =>
                Navigator.of(context).push(TaxRuleFormScreen.route()),
          ),
          const SizedBox(height: 18),
          rules.when(
            loading: () => const ModuleLoading(),
            error: (error, _) => payrollFailurePanel(error, t),
            data: (list) => list.isEmpty
                ? Padding(
                    padding: const EdgeInsetsDirectional.only(top: 28),
                    child: Text(
                      t('payroll.noTaxRules'),
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: NeonPalette.textMuted,
                      ),
                    ),
                  )
                : Column(
                    children: [
                      for (final set in list) ...[
                        _TaxRuleCard(ruleSet: set, locale: locale),
                        const SizedBox(height: 12),
                      ],
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

class _TaxRuleCard extends ConsumerWidget {
  const _TaxRuleCard({required this.ruleSet, required this.locale});

  final TaxRuleSet ruleSet;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = ruleSet.isActive
        ? (isDark ? NeonPalette.cyan : NeonPalette.lightCyan)
        : (isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary);
    final currency = ruleSet.currency;

    String amount(int minorUnits) => currency == null
        ? PayrollFormat.count(minorUnits, locale)
        : PayrollFormat.money(Money(minorUnits, currency), locale);

    return NeonCard(
      key: ValueKey('tax-rule-${ruleSet.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  ruleSet.name ?? ruleSet.country,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              NeonChip(
                label: ruleSet.country,
                accent: accent,
                selected: true,
              ),
            ],
          ),
          const SizedBox(height: 10),
          DetailRow(
            label: t('payroll.effectiveFrom'),
            value: PayrollFormat.date(ruleSet.effectiveFrom, locale),
          ),
          DetailRow(
            label: t('payroll.active'),
            value: ruleSet.isActive ? t('payroll.yes') : t('payroll.no'),
          ),
          DetailRow(
            label: t('payroll.taxBase'),
            value: t(PayrollFormat.taxBaseKey(ruleSet.taxBase)),
          ),
          if (ruleSet.brackets.isNotEmpty) ...[
            const SizedBox(height: 8),
            GroupHeading(title: t('payroll.brackets'), accent: accent),
            for (final bracket in ruleSet.brackets)
              DetailRow(
                label: bracket.upTo == null
                    ? t('payroll.bracketTop')
                    : t('payroll.bracketUpTo',
                        args: {'amount': amount(bracket.upTo!)},),
                value: PayrollFormat.percent(bracket.rate, locale),
              ),
          ],
          if (ruleSet.employeeContributions.isNotEmpty) ...[
            const SizedBox(height: 8),
            GroupHeading(
              title: t('payroll.employeeContributions'),
              accent: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
            ),
            for (final rule in ruleSet.employeeContributions)
              DetailRow(
                label: rule.label ?? rule.code,
                value: PayrollFormat.percent(rule.rate, locale),
              ),
          ],
          if (ruleSet.employerContributions.isNotEmpty) ...[
            const SizedBox(height: 8),
            GroupHeading(
              title: t('payroll.employerContributions'),
              accent: isDark ? NeonPalette.violet : NeonPalette.lightViolet,
            ),
            Text(
              t('payroll.contributionsNotInNet'),
              style: TextStyle(
                fontSize: 11.5,
                height: 1.5,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
            for (final rule in ruleSet.employerContributions)
              DetailRow(
                label: rule.label ?? rule.code,
                value: PayrollFormat.percent(rule.rate, locale),
              ),
          ],
          if (ruleSet.fixedDeductions.isNotEmpty) ...[
            const SizedBox(height: 8),
            GroupHeading(title: t('payroll.fixedDeductions'), accent: accent),
            for (final rule in ruleSet.fixedDeductions)
              DetailRow(
                label: rule.label ?? rule.code,
                value: amount(rule.amountMinorUnits),
              ),
          ],
        ],
      ),
    );
  }
}
