import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_card.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';

/// One payslip, itemised.
///
/// The layout carries one distinction above all others: a deduction comes out
/// of the employee's pay and a contribution does not. Contributions are drawn
/// *below* net, in their own section, with the arithmetic spelled out — putting
/// them in the deductions list is the standard way a payslip gets misread, and
/// it understates take-home by exactly the employer's own charges.
class PayslipScreen extends ConsumerWidget {
  const PayslipScreen({super.key, required this.payslipId});

  final String payslipId;

  static Route<void> route(String payslipId) => MaterialPageRoute<void>(
        builder: (_) => PayslipScreen(payslipId: payslipId),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final payslip = ref.watch(payslipProvider(payslipId));

    return ModulePage(
      title: payslip.valueOrNull?.employeeName ?? t('payroll.payslip'),
      subtitle: t('payroll.payslipSubtitle'),
      child: payslip.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => payrollFailurePanel(error, t),
        data: (data) => _PayslipBody(payslip: data),
      ),
    );
  }
}

class _PayslipBody extends ConsumerWidget {
  const _PayslipBody({required this.payslip});

  final Payslip payslip;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final earnings = payslip.linesOf(PayslipLineKind.earning);
    final deductions = payslip.linesOf(PayslipLineKind.deduction);
    final contributions = payslip.linesOf(PayslipLineKind.contribution);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonCard(
          accent: isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                payslip.employeeName,
                style: TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w800,
                  color: isDark
                      ? NeonPalette.textPrimary
                      : NeonPalette.lightTextPrimary,
                ),
              ),
              if (payslip.employeeJobTitle != null) ...[
                const SizedBox(height: 3),
                Text(
                  payslip.employeeJobTitle!,
                  style: TextStyle(
                    fontSize: 12,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ],
              const SizedBox(height: 12),
              DetailRow(
                label: t('payroll.workedDays'),
                value: t('payroll.daysOfDays', args: {
                  'worked': PayrollFormat.count(payslip.workedDays, locale),
                  'period': PayrollFormat.count(payslip.periodDays, locale),
                },),
              ),
              if (payslip.isProrated)
                DetailRow(
                  label: t('payroll.prorated'),
                  value: t('payroll.proratedNote'),
                  accent: NeonPalette.amber,
                ),
              if (payslip.taxRulesName != null)
                DetailRow(
                  label: t('payroll.taxRules'),
                  value: payslip.taxRulesName!,
                ),
              if (payslip.country != null)
                DetailRow(
                  label: t('payroll.country'),
                  value: payslip.country!,
                ),
            ],
          ),
        ),
        const SizedBox(height: 20),

        // 1 — what was earned.
        _LineSection(
          key: const ValueKey('payslip-earnings'),
          title: t('payroll.earnings'),
          note: t('payroll.earningsNote'),
          kind: PayslipLineKind.earning,
          lines: earnings,
          total: payslip.gross,
          totalLabel: t('payroll.gross'),
          locale: locale,
        ),
        const SizedBox(height: 16),

        // 2 — what came out of it.
        _LineSection(
          key: const ValueKey('payslip-deductions'),
          title: t('payroll.deductions'),
          note: t('payroll.deductionsNote'),
          kind: PayslipLineKind.deduction,
          lines: deductions,
          total: payslip.deductions,
          totalLabel: t('payroll.totalDeductions'),
          locale: locale,
        ),
        const SizedBox(height: 16),

        // 3 — net, which is the two above and nothing else.
        NeonCard(
          accent: isDark ? NeonPalette.lime : NeonPalette.lightLime,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              DetailRow(
                key: const ValueKey('payslip-net'),
                label: t('payroll.netPay'),
                value: PayrollFormat.money(payslip.net, locale),
                strong: true,
                accent: isDark ? NeonPalette.lime : NeonPalette.lightLime,
              ),
              const SizedBox(height: 4),
              Text(
                t('payroll.netFormula'),
                key: const ValueKey('payslip-net-formula'),
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.5,
                  color: isDark
                      ? NeonPalette.textSecondary
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),

        // 4 — the employer's own charges, below net and outside it.
        _LineSection(
          key: const ValueKey('payslip-contributions'),
          title: t('payroll.employerContributions'),
          note: t('payroll.contributionsNotInNet'),
          kind: PayslipLineKind.contribution,
          lines: contributions,
          total: payslip.contributions,
          totalLabel: t('payroll.totalContributions'),
          locale: locale,
        ),
        const SizedBox(height: 16),
        NeonCard(
          accent: isDark ? NeonPalette.violet : NeonPalette.lightViolet,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              DetailRow(
                key: const ValueKey('payslip-employer-cost'),
                label: t('payroll.employerCost'),
                value: PayrollFormat.money(payslip.employerCost, locale),
                strong: true,
                accent: isDark ? NeonPalette.violet : NeonPalette.lightViolet,
              ),
              const SizedBox(height: 4),
              Text(
                t('payroll.employerCostFormula'),
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.5,
                  color: isDark
                      ? NeonPalette.textSecondary
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// One itemised block: its lines, a sentence saying what the block does to the
/// employee's pay, and the server's total for it.
class _LineSection extends ConsumerWidget {
  const _LineSection({
    super.key,
    required this.title,
    required this.note,
    required this.kind,
    required this.lines,
    required this.total,
    required this.totalLabel,
    required this.locale,
  });

  final String title;
  final String note;
  final PayslipLineKind kind;
  final List<PayslipLine> lines;
  final Money total;
  final String totalLabel;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = PayrollFormat.lineAccent(kind, isDark: isDark);

    return NeonCard(
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          GroupHeading(title: title, accent: accent),
          Text(
            note,
            style: TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: isDark
                  ? NeonPalette.textSecondary
                  : NeonPalette.lightTextSecondary,
            ),
          ),
          const SizedBox(height: 10),
          if (lines.isEmpty)
            Text(
              t('payroll.noLines'),
              style:
                  const TextStyle(fontSize: 12, color: NeonPalette.textMuted),
            )
          else
            for (final line in lines)
              DetailRow(
                key: ValueKey('payslip-line-${line.id}'),
                label: _label(line, t),
                value: PayrollFormat.money(line.amount, locale),
                accent: accent,
              ),
          const Divider(height: 22),
          DetailRow(
            label: totalLabel,
            value: PayrollFormat.money(total, locale),
            strong: true,
            accent: accent,
          ),
        ],
      ),
    );
  }

  /// The server labels most lines; a bare code still reads better than nothing,
  /// and the rate is appended when the line came from one.
  String _label(PayslipLine line, Translator t) {
    final base = line.label ?? line.code ?? t(PayrollFormat.lineKindKey(kind));
    if (line.rate == null) return base;
    return '$base · ${PayrollFormat.percent(line.rate!, locale)}';
  }
}
