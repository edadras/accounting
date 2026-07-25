import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../domain/analytics.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../data/report_export_screen.dart';

class ReportsScreen extends ConsumerWidget {
  const ReportsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final cashFlow = ref.watch(cashFlowProvider);
    final budgets = ref.watch(budgetsProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 120),
      children: [
        SectionHeader(title: t('reports.cashFlow'), accent: NeonPalette.cyan),
        cashFlow.when(
          loading: () => const _CardSpinner(),
          error: (error, _) => _CardError(message: t('common.error')),
          data: (report) => NeonCard(
            accent: NeonPalette.cyan,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: _Total(
                        label: t('dashboard.income'),
                        value: MoneyFormatter.format(report.totalIncome,
                            locale: locale, compact: true,),
                        accent: NeonPalette.income,
                      ),
                    ),
                    Expanded(
                      child: _Total(
                        label: t('dashboard.expense'),
                        value: MoneyFormatter.format(report.totalExpense,
                            locale: locale, compact: true,),
                        accent: NeonPalette.expense,
                      ),
                    ),
                    Expanded(
                      child: _Total(
                        label: t('reports.net'),
                        value: MoneyFormatter.format(
                          report.net,
                          locale: locale,
                          compact: true,
                        ),
                        accent: report.net.isNegative
                            ? NeonPalette.expense
                            : NeonPalette.cyan,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
                _CashFlowChart(report: report),
              ],
            ),
          ),
        ),
        const SizedBox(height: 24),
        SectionHeader(title: t('budget.title'), accent: NeonPalette.violet),
        budgets.when(
          loading: () => const _CardSpinner(),
          error: (error, _) => _CardError(message: t('common.error')),
          data: (list) => Column(
            children: [
              for (final budget in list) ...[
                _BudgetCard(budget: budget, locale: locale, t: t),
                const SizedBox(height: 10),
              ],
            ],
          ),
        ),
        const SizedBox(height: 24),
        // The export screen was built and reachable from nowhere. It belongs
        // here rather than in settings: you decide to export the report you are
        // currently looking at, not by going to look for a settings row.
        NeonCardShell(
          key: const ValueKey('reports-export'),
          accent: NeonPalette.lime,
          padding: const EdgeInsetsDirectional.all(16),
          onTap: () => Navigator.of(context).push(
            ReportExportScreen.route(
              reportType: 'cash-flow',
              reportLabelKey: 'reports.cashFlow',
            ),
          ),
          child: Row(
            children: [
              const Icon(Icons.download_rounded, size: 19, color: NeonPalette.lime),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  t('data.report.title'),
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
              ),
              Icon(
                Directionality.of(context) == TextDirection.rtl
                    ? Icons.chevron_left_rounded
                    : Icons.chevron_right_rounded,
                size: 19,
                color: NeonPalette.lime,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Paired income/expense bars per month.
///
/// Both directions share one scale so the comparison is honest — drawing each
/// series against its own maximum would make a small income look equal to a
/// large expense.
class _CashFlowChart extends StatelessWidget {
  const _CashFlowChart({required this.report});

  final CashFlowReport report;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final peak = report.peak;

    return SizedBox(
      height: 150,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          for (final point in report.points)
            Expanded(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  Expanded(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        _Bar(
                          fraction: peak == 0 ? 0 : point.income.minorUnits / peak,
                          color: NeonPalette.income,
                          glow: isDark,
                        ),
                        const SizedBox(width: 3),
                        _Bar(
                          fraction: peak == 0 ? 0 : point.expense.minorUnits / peak,
                          color: NeonPalette.expense,
                          glow: isDark,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    point.label,
                    style: const TextStyle(
                      fontSize: 10,
                      color: NeonPalette.textMuted,
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

class _Bar extends StatelessWidget {
  const _Bar({required this.fraction, required this.color, required this.glow});

  final double fraction;
  final Color color;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final available = constraints.maxHeight;
        return TweenAnimationBuilder<double>(
          tween: Tween(begin: 0, end: fraction.clamp(0.0, 1.0)),
          duration: NeonEffects.medium,
          curve: NeonEffects.curve,
          builder: (context, value, _) => Container(
            width: 9,
            // A zero-height bar is invisible and reads as missing data rather
            // than as a real zero, so keep a 3px stub.
            height: math.max(3, available * value),
            decoration: BoxDecoration(
              gradient: NeonEffects.hero(color.withValues(alpha: 0.5), color),
              borderRadius: BorderRadius.circular(3),
              boxShadow: glow && value > 0.05
                  ? NeonEffects.glowTight(color, intensity: 0.5)
                  : null,
            ),
          ),
        );
      },
    );
  }
}

class _BudgetCard extends StatelessWidget {
  const _BudgetCard({
    required this.budget,
    required this.locale,
    required this.t,
  });

  final Budget budget;
  final String locale;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = budget.isBreached
        ? NeonPalette.magenta
        : budget.isNearLimit
            ? NeonPalette.amber
            : NeonPalette.lime;

    return NeonCardShell(
      accent: accent,
      glow: budget.isBreached,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  budget.name,
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
              ),
              Text(
                '${(budget.progress * 100).round()}٪',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: accent,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          LayoutBuilder(
            builder: (context, constraints) => Stack(
              children: [
                Container(
                  height: 9,
                  decoration: BoxDecoration(
                    color: accent.withValues(alpha: 0.10),
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
                TweenAnimationBuilder<double>(
                  tween: Tween(begin: 0, end: budget.progress.clamp(0.0, 1.0)),
                  duration: NeonEffects.medium,
                  curve: NeonEffects.curve,
                  builder: (context, value, _) => Container(
                    height: 9,
                    width: math.max(6, constraints.maxWidth * value),
                    decoration: BoxDecoration(
                      gradient: NeonEffects.hero(accent.withValues(alpha: 0.55), accent),
                      borderRadius: BorderRadius.circular(999),
                      boxShadow: isDark
                          ? NeonEffects.glowTight(accent, intensity: 0.6)
                          : null,
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${MoneyFormatter.format(budget.spent, locale: locale, compact: true)}'
                  ' / '
                  '${MoneyFormatter.format(budget.effectiveAmount, locale: locale, compact: true)}',
                  style: const TextStyle(fontSize: 11.5, color: NeonPalette.textMuted),
                ),
              ),
              Text(
                budget.isBreached
                    ? t('budget.over')
                    : '${t('budget.remaining')} '
                        '${MoneyFormatter.format(budget.remaining, locale: locale, compact: true)}',
                style: TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: accent,
                ),
              ),
            ],
          ),
          if (budget.carriedOver != null) ...[
            const SizedBox(height: 8),
            NeonChip(
              label: '${t('budget.rollover')} '
                  '${MoneyFormatter.format(budget.carriedOver!, locale: locale, compact: true)}',
              accent: NeonPalette.violet,
              icon: Icons.subdirectory_arrow_right_rounded,
            ),
          ],
        ],
      ),
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({required this.label, required this.value, required this.accent});

  final String label;
  final String value;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontSize: 11, color: NeonPalette.textSecondary),
        ),
        const SizedBox(height: 5),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: AlignmentDirectional.centerStart,
          child: Text(
            value,
            style: TextStyle(
              fontSize: 17,
              fontWeight: FontWeight.w800,
              color: accent,
            ),
          ),
        ),
      ],
    );
  }
}

class _CardSpinner extends StatelessWidget {
  const _CardSpinner();

  @override
  Widget build(BuildContext context) => const NeonCard(
        child: SizedBox(
          height: 120,
          child: Center(child: CircularProgressIndicator()),
        ),
      );
}

class _CardError extends StatelessWidget {
  const _CardError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) => NeonCard(
        accent: NeonPalette.magenta,
        child: SizedBox(height: 80, child: Center(child: Text(message))),
      );
}
