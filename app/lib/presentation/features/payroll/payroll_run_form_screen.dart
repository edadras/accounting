import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../data/payroll_repository.dart';
import '../../../domain/entities.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_providers.dart';
import 'payroll_run_detail_screen.dart';

/// Create a draft run.
///
/// Creating calculates every payslip and posts nothing — that is the whole
/// value of the draft state, and the screen says so before the button rather
/// than after it.
class PayrollRunFormScreen extends ConsumerStatefulWidget {
  const PayrollRunFormScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const PayrollRunFormScreen(),
      );

  @override
  ConsumerState<PayrollRunFormScreen> createState() =>
      _PayrollRunFormScreenState();
}

class _PayrollRunFormScreenState extends ConsumerState<PayrollRunFormScreen> {
  final _reference = TextEditingController();
  final _periodStart = TextEditingController();
  final _periodEnd = TextEditingController();
  final _payDate = TextEditingController();
  final _notes = TextEditingController();

  String? _accountId;
  String? _categoryId;

  /// Empty means everyone employed during the period, which is what the server
  /// does with an omitted list.
  final Set<String> _employeeIds = {};

  bool _busy = false;
  String? _validationKey;
  String? _failureText;

  @override
  void dispose() {
    for (final controller in [
      _reference,
      _periodStart,
      _periodEnd,
      _payDate,
      _notes,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final start = DateEntryField.parse(_periodStart.text);
    final end = DateEntryField.parse(_periodEnd.text);
    final payDate = _payDate.text.trim().isEmpty
        ? null
        : DateEntryField.parse(_payDate.text);
    final accountId = _accountId;

    final validation = switch (accountId) {
      _ when start == null || end == null => 'payroll.periodRequired',
      _ when end.isBefore(start) => 'payroll.periodEndsBeforeStart',
      _ when _payDate.text.trim().isNotEmpty && payDate == null =>
        'payroll.dateInvalid',
      null => 'payroll.accountRequired',
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
      final run = await ref.read(payrollRepositoryProvider).createRun(
            PayrollRunDraft(
              periodStart: start!,
              periodEnd: end!,
              accountId: accountId!,
              reference: _reference.text.trim(),
              payDate: payDate,
              categoryId: _categoryId,
              employeeIds: _employeeIds.toList(),
              notes: _notes.text.trim(),
            ),
          );

      ref.invalidate(payrollRunsProvider);

      if (!mounted) return;
      unawaited(
        Navigator.of(context)
            .pushReplacement(PayrollRunDetailScreen.route(run.id)),
      );
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
    final accounts = ref.watch(accountsProvider).valueOrNull ?? const <Account>[];
    final categories =
        ref.watch(categoriesProvider).valueOrNull ?? const <Category>[];
    final employees = ref.watch(employeesProvider).valueOrNull ?? const <Employee>[];

    return ModulePage(
      title: t('payroll.newRun'),
      subtitle: t('payroll.newRunSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 40),
        children: [
          if (_failureText != null) PayrollNotice(message: _failureText!),
          if (_validationKey != null) PayrollNotice(message: t(_validationKey!)),
          PayrollNotice(
            message: t('payroll.draftPostsNothing'),
            accent: NeonPalette.cyan,
            icon: Icons.info_outline_rounded,
          ),
          PayrollField(
            label: t('payroll.reference'),
            child: TextField(
              key: const ValueKey('run-reference'),
              controller: _reference,
            ),
          ),
          DateEntryField(
            fieldKey: const ValueKey('run-period-start'),
            controller: _periodStart,
            locale: locale,
            label: t('payroll.periodStart'),
          ),
          DateEntryField(
            fieldKey: const ValueKey('run-period-end'),
            controller: _periodEnd,
            locale: locale,
            label: t('payroll.periodEnd'),
          ),
          DateEntryField(
            fieldKey: const ValueKey('run-pay-date'),
            controller: _payDate,
            locale: locale,
            label: t('payroll.payDate'),
          ),
          PayrollField(
            label: t('payroll.account'),
            hint: t('payroll.accountNote'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final account in accounts)
                  NeonChip(
                    key: ValueKey('run-account-${account.id}'),
                    label: account.name,
                    selected: _accountId == account.id,
                    onTap: () => setState(() => _accountId = account.id),
                  ),
              ],
            ),
          ),
          if (categories.isNotEmpty)
            PayrollField(
              label: t('payroll.category'),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final category in categories)
                    NeonChip(
                      key: ValueKey('run-category-${category.id}'),
                      label: category.name,
                      selected: _categoryId == category.id,
                      onTap: () => setState(
                        () => _categoryId =
                            _categoryId == category.id ? null : category.id,
                      ),
                    ),
                ],
              ),
            ),
          PayrollField(
            label: t('payroll.employees'),
            hint: t('payroll.employeeSelectionNote'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final employee in employees)
                  NeonChip(
                    key: ValueKey('run-employee-${employee.id}'),
                    label: employee.name,
                    selected: _employeeIds.contains(employee.id),
                    onTap: () => setState(() {
                      if (!_employeeIds.remove(employee.id)) {
                        _employeeIds.add(employee.id);
                      }
                    }),
                  ),
              ],
            ),
          ),
          PayrollField(
            label: t('payroll.notes'),
            child: TextField(
              key: const ValueKey('run-notes'),
              controller: _notes,
              maxLines: 3,
            ),
          ),
          const SizedBox(height: 10),
          NeonButton(
            key: const ValueKey('run-save'),
            label: t('payroll.calculateDraft'),
            icon: Icons.calculate_rounded,
            expand: true,
            busy: _busy,
            onPressed: _busy ? null : _submit,
          ),
        ],
      ),
    );
  }
}
