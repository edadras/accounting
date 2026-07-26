import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'employee_detail_screen.dart';
import 'employee_form_screen.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';

/// Everyone on the payroll, with the rate in force beside each name.
///
/// The rate is on the row rather than one tap away because "what are we paying
/// this person" is the question this list is opened to answer.
class EmployeesScreen extends ConsumerWidget {
  const EmployeesScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const EmployeesScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final employees = ref.watch(employeesProvider);

    return ModulePage(
      title: t('payroll.employees'),
      subtitle: t('payroll.employeesSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          NeonButton(
            key: const ValueKey('employee-new'),
            label: t('payroll.newEmployee'),
            icon: Icons.person_add_alt_1_rounded,
            expand: true,
            onPressed: () =>
                Navigator.of(context).push(EmployeeFormScreen.route()),
          ),
          const SizedBox(height: 18),
          const _StatusFilter(),
          const SizedBox(height: 8),
          employees.when(
            loading: () => const ModuleLoading(),
            error: (error, _) => payrollFailurePanel(error, t),
            data: (list) => _EmployeeList(employees: list),
          ),
        ],
      ),
    );
  }
}

class _StatusFilter extends ConsumerWidget {
  const _StatusFilter();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final selected = ref.watch(employeeFilterProvider);

    void select(EmploymentStatus? status) =>
        ref.read(employeeFilterProvider.notifier).state = status;

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        NeonChip(
          key: const ValueKey('employee-filter-all'),
          label: t('payroll.filterAll'),
          selected: selected == null,
          onTap: () => select(null),
        ),
        for (final status in EmploymentStatus.values)
          NeonChip(
            key: ValueKey('employee-filter-${status.name}'),
            label: t(PayrollFormat.employmentStatusKey(status)),
            accent: PayrollFormat.employmentAccent(
              status,
              isDark: Theme.of(context).brightness == Brightness.dark,
            ),
            selected: selected == status,
            onTap: () => select(status),
          ),
      ],
    );
  }
}

class _EmployeeList extends ConsumerWidget {
  const _EmployeeList({required this.employees});

  final List<Employee> employees;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    if (employees.isEmpty) {
      return Padding(
        padding: const EdgeInsetsDirectional.only(top: 28),
        child: Text(
          t('payroll.noEmployees'),
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 12.5, color: NeonPalette.textMuted),
        ),
      );
    }

    return Column(
      children: [
        const SizedBox(height: 8),
        for (final employee in employees) ...[
          _EmployeeRow(employee: employee, locale: locale),
          const SizedBox(height: 10),
        ],
      ],
    );
  }
}

class _EmployeeRow extends ConsumerWidget {
  const _EmployeeRow({required this.employee, required this.locale});

  final Employee employee;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = PayrollFormat.employmentAccent(
      employee.status,
      isDark: isDark,
    );
    final rate = employee.compensation;

    return NeonCardShell(
      key: ValueKey('employee-${employee.id}'),
      accent: accent,
      onTap: () => Navigator.of(context)
          .push(EmployeeDetailScreen.route(employee.id)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      employee.name,
                      style: TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                    if (employee.jobTitle != null) ...[
                      const SizedBox(height: 3),
                      Text(
                        employee.jobTitle!,
                        style: TextStyle(
                          fontSize: 12,
                          color: isDark
                              ? NeonPalette.textSecondary
                              : NeonPalette.lightTextSecondary,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 10),
              NeonChip(
                label: t(PayrollFormat.employmentStatusKey(employee.status)),
                accent: accent,
                selected: true,
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (rate == null)
            Text(
              t('payroll.noRateYet'),
              key: ValueKey('employee-norate-${employee.id}'),
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: NeonPalette.amber,
              ),
            )
          else
            Row(
              children: [
                Expanded(
                  child: Text(
                    PayrollFormat.money(rate.amount, locale),
                    key: ValueKey('employee-rate-${employee.id}'),
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
                  t(PayrollFormat.periodKey(rate.period)),
                  style: TextStyle(
                    fontSize: 11.5,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
