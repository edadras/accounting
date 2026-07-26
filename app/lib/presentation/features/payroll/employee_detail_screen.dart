import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'employee_form_screen.dart';
import 'pay_rate_screen.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';

/// One employee, and — the point of the screen — every rate they have ever
/// been on.
///
/// The history is not an audit curiosity. A run uses the rate in force during
/// its period, so the only way a payslip from March stays explicable after a
/// raise in June is if June's raise was an append and March's rate is still
/// here to read.
class EmployeeDetailScreen extends ConsumerWidget {
  const EmployeeDetailScreen({super.key, required this.employeeId});

  final String employeeId;

  static Route<void> route(String employeeId) => MaterialPageRoute<void>(
        builder: (_) => EmployeeDetailScreen(employeeId: employeeId),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final employee = ref.watch(employeeProvider(employeeId));

    return ModulePage(
      title: employee.valueOrNull?.name ?? t('payroll.employee'),
      subtitle: t('payroll.employeeSubtitle'),
      child: employee.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => payrollFailurePanel(error, t),
        data: (data) => _EmployeeBody(employee: data),
      ),
    );
  }
}

class _EmployeeBody extends ConsumerWidget {
  const _EmployeeBody({required this.employee});

  final Employee employee;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = PayrollFormat.employmentAccent(
      employee.status,
      isDark: isDark,
    );
    final rate = employee.compensation;

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonCard(
          accent: accent,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      employee.name,
                      style: TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                  ),
                  NeonChip(
                    label:
                        t(PayrollFormat.employmentStatusKey(employee.status)),
                    accent: accent,
                    selected: true,
                  ),
                ],
              ),
              const SizedBox(height: 12),
              if (employee.jobTitle != null)
                DetailRow(
                  label: t('payroll.jobTitle'),
                  value: employee.jobTitle!,
                ),
              if (employee.employeeNumber != null)
                DetailRow(
                  label: t('payroll.employeeNumber'),
                  value: employee.employeeNumber!,
                ),
              if (employee.email != null)
                DetailRow(label: t('payroll.email'), value: employee.email!),
              if (employee.phone != null)
                DetailRow(label: t('payroll.phone'), value: employee.phone!),
              if (employee.country != null)
                DetailRow(
                  label: t('payroll.country'),
                  value: employee.country!,
                ),
              DetailRow(
                label: t('payroll.startedOn'),
                value: PayrollFormat.date(employee.startedOn, locale),
              ),
              if (employee.endedOn != null)
                DetailRow(
                  label: t('payroll.endedOn'),
                  value: PayrollFormat.date(employee.endedOn, locale),
                ),
              DetailRow(
                label: t('payroll.nationalId'),
                value: employee.hasNationalId
                    ? t('payroll.nationalIdStored')
                    : t('payroll.nationalIdNone'),
              ),
              if (employee.notes != null) ...[
                const SizedBox(height: 8),
                Text(
                  employee.notes!,
                  style: TextStyle(
                    fontSize: 12,
                    height: 1.6,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 18),
        Row(
          children: [
            Expanded(
              child: NeonButton(
                key: const ValueKey('employee-edit'),
                label: t('payroll.editEmployee'),
                icon: Icons.edit_rounded,
                variant: NeonButtonVariant.outline,
                expand: true,
                onPressed: () => Navigator.of(context)
                    .push(EmployeeFormScreen.route(employee: employee)),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: NeonButton(
                key: const ValueKey('employee-new-rate'),
                label: t('payroll.newRate'),
                icon: Icons.trending_up_rounded,
                expand: true,
                onPressed: () => Navigator.of(context)
                    .push(PayRateScreen.route(employee: employee)),
              ),
            ),
          ],
        ),
        const SizedBox(height: 22),
        SectionHeader(title: t('payroll.currentRate')),
        if (rate == null)
          _Muted(text: t('payroll.noRateYet'))
        else
          StatTile(
            key: const ValueKey('employee-current-rate'),
            label: t(PayrollFormat.periodKey(rate.period)),
            value: PayrollFormat.money(rate.amount, locale),
            accent: isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
            caption: t(
              'payroll.effectiveFromOn',
              args: {'date': PayrollFormat.date(rate.effectiveFrom, locale)},
            ),
          ),
        const SizedBox(height: 22),
        SectionHeader(title: t('payroll.rateHistory')),
        Text(
          t('payroll.rateHistoryNote'),
          key: const ValueKey('employee-history-note'),
          style: TextStyle(
            fontSize: 12,
            height: 1.6,
            color: isDark
                ? NeonPalette.textSecondary
                : NeonPalette.lightTextSecondary,
          ),
        ),
        const SizedBox(height: 12),
        if (employee.compensations.isEmpty)
          _Muted(text: t('payroll.noRateHistory'))
        else
          for (final record in employee.compensations) ...[
            _RateRow(
              record: record,
              locale: locale,
              isCurrent: record.id == rate?.id,
            ),
            const SizedBox(height: 10),
          ],
      ],
    );
  }
}

/// One row of the rate history: what it was, from when, and whether it is the
/// one a run would use today.
class _RateRow extends ConsumerWidget {
  const _RateRow({
    required this.record,
    required this.locale,
    required this.isCurrent,
  });

  final CompensationRecord record;
  final AppLocale locale;
  final bool isCurrent;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = isCurrent
        ? (isDark ? NeonPalette.cyan : NeonPalette.lightCyan)
        : (isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary);

    return NeonCardShell(
      key: ValueKey('rate-${record.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  PayrollFormat.money(record.amount, locale),
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              Text(
                t(PayrollFormat.periodKey(record.period)),
                style: TextStyle(
                  fontSize: 11.5,
                  color: isDark
                      ? NeonPalette.textMuted
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: Text(
                  record.effectiveTo == null
                      ? t(
                          'payroll.effectiveFromOn',
                          args: {
                            'date':
                                PayrollFormat.date(record.effectiveFrom, locale),
                          },
                        )
                      : t(
                          'payroll.effectiveBetween',
                          args: {
                            'from':
                                PayrollFormat.date(record.effectiveFrom, locale),
                            'to': PayrollFormat.date(record.effectiveTo, locale),
                          },
                        ),
                  style: TextStyle(
                    fontSize: 11.5,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              if (isCurrent)
                NeonChip(
                  label: t('payroll.rateInForce'),
                  accent: accent,
                  selected: true,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Muted extends StatelessWidget {
  const _Muted({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.symmetric(vertical: 10),
        child: Text(
          text,
          style: const TextStyle(fontSize: 12.5, color: NeonPalette.textMuted),
        ),
      );
}
