import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';
import 'payroll_run_detail_screen.dart';
import 'payroll_run_form_screen.dart';

/// Payroll runs, filtered by where they are in their life.
///
/// The state is the first thing on every row, because it decides everything
/// else: a draft has posted nothing, an approved run is already in the ledger,
/// and a paid one is history.
class PayrollRunsScreen extends ConsumerWidget {
  const PayrollRunsScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const PayrollRunsScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final runs = ref.watch(payrollRunsProvider);

    return ModulePage(
      title: t('payroll.runs'),
      subtitle: t('payroll.runsSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          NeonButton(
            key: const ValueKey('run-new'),
            label: t('payroll.newRun'),
            icon: Icons.add_rounded,
            expand: true,
            onPressed: () =>
                Navigator.of(context).push(PayrollRunFormScreen.route()),
          ),
          const SizedBox(height: 18),
          const _RunFilter(),
          const SizedBox(height: 8),
          runs.when(
            loading: () => const ModuleLoading(),
            error: (error, _) => payrollFailurePanel(error, t),
            data: (list) => _RunList(runs: list),
          ),
        ],
      ),
    );
  }
}

class _RunFilter extends ConsumerWidget {
  const _RunFilter();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final selected = ref.watch(runFilterProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    void select(PayrollRunStatus? status) =>
        ref.read(runFilterProvider.notifier).state = status;

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        NeonChip(
          key: const ValueKey('run-filter-all'),
          label: t('payroll.filterAll'),
          selected: selected == null,
          onTap: () => select(null),
        ),
        for (final status in PayrollRunStatus.values)
          NeonChip(
            key: ValueKey('run-filter-${status.name}'),
            label: t(PayrollFormat.runStatusKey(status)),
            accent: PayrollFormat.runAccent(status, isDark: isDark),
            selected: selected == status,
            onTap: () => select(status),
          ),
      ],
    );
  }
}

class _RunList extends ConsumerWidget {
  const _RunList({required this.runs});

  final List<PayrollRun> runs;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    if (runs.isEmpty) {
      return Padding(
        padding: const EdgeInsetsDirectional.only(top: 28),
        child: Text(
          t('payroll.noRuns'),
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 12.5, color: NeonPalette.textMuted),
        ),
      );
    }

    return Column(
      children: [
        const SizedBox(height: 8),
        for (final run in runs) ...[
          PayrollRunRow(run: run, locale: locale),
          const SizedBox(height: 10),
        ],
      ],
    );
  }
}

/// One run in a list. Shared with the detail screen's sibling lists so a run
/// reads the same wherever it appears.
class PayrollRunRow extends ConsumerWidget {
  const PayrollRunRow({super.key, required this.run, required this.locale});

  final PayrollRun run;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = PayrollFormat.runAccent(run.status, isDark: isDark);

    return NeonCardShell(
      key: ValueKey('run-${run.id}'),
      accent: accent,
      // Glow is information: a draft that already carries a real cost is the
      // one row worth pulling the eye. A paid run never glows.
      glow: PayrollFormat.shouldGlow(run),
      onTap: () =>
          Navigator.of(context).push(PayrollRunDetailScreen.route(run.id)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  run.reference ?? t('payroll.runNoReference'),
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              NeonChip(
                key: ValueKey('run-status-${run.id}'),
                label: t(PayrollFormat.runStatusKey(run.status)),
                accent: accent,
                selected: true,
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            t('payroll.periodBetween', args: {
              'from': PayrollFormat.date(run.periodStart, locale),
              'to': PayrollFormat.date(run.periodEnd, locale),
            },),
            style: TextStyle(
              fontSize: 11.5,
              color: isDark
                  ? NeonPalette.textSecondary
                  : NeonPalette.lightTextSecondary,
            ),
          ),
          const SizedBox(height: 12),
          DetailRow(
            label: t('payroll.net'),
            value: PayrollFormat.money(run.net, locale),
            strong: true,
          ),
          DetailRow(
            label: t('payroll.employerCost'),
            value: PayrollFormat.money(run.employerCost, locale),
            accent: accent,
          ),
          if (!run.status.isDraft)
            DetailRow(
              label: t('payroll.posted'),
              value: run.isPosted ? t('payroll.yes') : t('payroll.no'),
            ),
        ],
      ),
    );
  }
}
