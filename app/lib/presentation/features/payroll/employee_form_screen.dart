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

/// Create an employee, or edit the person — never the pay.
///
/// Pay is absent from the edit form on purpose: the server refuses to change a
/// rate here, because a rate change is an append. The form says so rather than
/// leaving a field that would fail.
class EmployeeFormScreen extends ConsumerStatefulWidget {
  const EmployeeFormScreen({super.key, this.employee});

  final Employee? employee;

  static Route<void> route({Employee? employee}) => MaterialPageRoute<void>(
        builder: (_) => EmployeeFormScreen(employee: employee),
      );

  @override
  ConsumerState<EmployeeFormScreen> createState() => _EmployeeFormScreenState();
}

class _EmployeeFormScreenState extends ConsumerState<EmployeeFormScreen> {
  final _name = TextEditingController();
  final _number = TextEditingController();
  final _jobTitle = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _country = TextEditingController();
  final _startedOn = TextEditingController();
  final _endedOn = TextEditingController();
  final _notes = TextEditingController();
  final _amount = TextEditingController();

  EmploymentStatus _status = EmploymentStatus.active;
  Currency _currency = Currency.try_;
  PayPeriodKind _period = PayPeriodKind.monthly;
  bool _busy = false;

  /// Always a translation key, never a sentence from the server.
  String? _validationKey;
  String? _failureText;

  bool get _isEdit => widget.employee != null;

  @override
  void initState() {
    super.initState();
    final employee = widget.employee;
    if (employee == null) return;

    _name.text = employee.name;
    _number.text = employee.employeeNumber ?? '';
    _jobTitle.text = employee.jobTitle ?? '';
    _email.text = employee.email ?? '';
    _phone.text = employee.phone ?? '';
    _country.text = employee.country ?? '';
    _notes.text = employee.notes ?? '';
    _status = employee.status;
    if (employee.startedOn != null) {
      _startedOn.text = isoDate(employee.startedOn!);
    }
    if (employee.endedOn != null) _endedOn.text = isoDate(employee.endedOn!);
  }

  @override
  void dispose() {
    for (final controller in [
      _name,
      _number,
      _jobTitle,
      _email,
      _phone,
      _country,
      _startedOn,
      _endedOn,
      _notes,
      _amount,
    ]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    final name = _name.text.trim();
    final started = DateEntryField.parse(_startedOn.text);
    final ended = _endedOn.text.trim().isEmpty
        ? null
        : DateEntryField.parse(_endedOn.text);

    final validation = switch (name) {
      '' => 'payroll.nameRequired',
      _ when !_isEdit && started == null => 'payroll.startDateRequired',
      _ when _endedOn.text.trim().isNotEmpty && ended == null =>
        'payroll.dateInvalid',
      _ when started != null && ended != null && ended.isBefore(started) =>
        'payroll.endsBeforeStart',
      _ => null,
    };

    if (validation != null) {
      setState(() {
        _validationKey = validation;
        _failureText = null;
      });
      return;
    }

    final rateText = _amount.text.trim();
    final rateMinorUnits =
        rateText.isEmpty ? null : AmountEntry.minorUnits(rateText, _currency);

    if (!_isEdit && rateText.isNotEmpty && rateMinorUnits == null) {
      setState(() {
        _validationKey = 'payroll.amountInvalid';
        _failureText = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _validationKey = null;
      _failureText = null;
    });

    final draft = EmployeeDraft(
      name: name,
      employeeNumber: _optional(_number),
      jobTitle: _optional(_jobTitle),
      email: _optional(_email),
      phone: _optional(_phone),
      country: _optional(_country),
      status: _status,
      startedOn: started,
      endedOn: ended,
      notes: _optional(_notes),
    );

    try {
      final repository = ref.read(payrollRepositoryProvider);
      final employee = widget.employee;

      if (employee == null) {
        await repository.createEmployee(
          draft,
          compensation: rateMinorUnits == null
              ? null
              : CompensationDraft(
                  amountMinorUnits: rateMinorUnits,
                  currency: _currency,
                  period: _period,
                ),
        );
      } else {
        await repository.updateEmployee(employee.id, draft);
        ref.invalidate(employeeProvider(employee.id));
      }

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

  String? _optional(TextEditingController controller) {
    final text = controller.text.trim();
    return text.isEmpty ? null : text;
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    return ModulePage(
      title: _isEdit ? t('payroll.editEmployee') : t('payroll.newEmployee'),
      subtitle:
          _isEdit ? t('payroll.editEmployeeNote') : t('payroll.newEmployeeNote'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 12, 16, 40),
        children: [
          if (_failureText != null) PayrollNotice(message: _failureText!),
          if (_validationKey != null) PayrollNotice(message: t(_validationKey!)),
          PayrollField(
            label: t('payroll.name'),
            child: TextField(
              key: const ValueKey('employee-name'),
              controller: _name,
              decoration: InputDecoration(hintText: t('payroll.nameHint')),
            ),
          ),
          PayrollField(
            label: t('payroll.jobTitle'),
            child: TextField(
              key: const ValueKey('employee-job-title'),
              controller: _jobTitle,
            ),
          ),
          PayrollField(
            label: t('payroll.employeeNumber'),
            child: TextField(
              key: const ValueKey('employee-number'),
              controller: _number,
            ),
          ),
          PayrollField(
            label: t('payroll.email'),
            child: TextField(
              key: const ValueKey('employee-email'),
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              autocorrect: false,
            ),
          ),
          PayrollField(
            label: t('payroll.phone'),
            child: TextField(
              key: const ValueKey('employee-phone'),
              controller: _phone,
              keyboardType: TextInputType.phone,
            ),
          ),
          PayrollField(
            label: t('payroll.country'),
            hint: t('payroll.countryNote'),
            child: TextField(
              key: const ValueKey('employee-country'),
              controller: _country,
              autocorrect: false,
              maxLength: 2,
              decoration: const InputDecoration(counterText: ''),
            ),
          ),
          PayrollField(
            label: t('payroll.status'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final status in EmploymentStatus.values)
                  NeonChip(
                    key: ValueKey('employee-status-${status.name}'),
                    label: t(PayrollFormat.employmentStatusKey(status)),
                    accent: PayrollFormat.employmentAccent(
                      status,
                      isDark: Theme.of(context).brightness == Brightness.dark,
                    ),
                    selected: _status == status,
                    onTap: () => setState(() => _status = status),
                  ),
              ],
            ),
          ),
          DateEntryField(
            fieldKey: const ValueKey('employee-started-on'),
            controller: _startedOn,
            locale: locale,
            label: t('payroll.startedOn'),
          ),
          DateEntryField(
            fieldKey: const ValueKey('employee-ended-on'),
            controller: _endedOn,
            locale: locale,
            label: t('payroll.endedOn'),
          ),
          PayrollField(
            label: t('payroll.notes'),
            child: TextField(
              key: const ValueKey('employee-notes'),
              controller: _notes,
              maxLines: 3,
            ),
          ),
          if (_isEdit)
            PayrollNotice(
              message: t('payroll.payNotEditable'),
              accent: NeonPalette.amber,
              icon: Icons.info_outline_rounded,
            )
          else ...[
            const SizedBox(height: 6),
            SectionHeader(title: t('payroll.openingRate')),
            Text(
              t('payroll.openingRateNote'),
              style: const TextStyle(fontSize: 11.5, height: 1.5),
            ),
            const SizedBox(height: 12),
            PayrollField(
              label: t('payroll.amount'),
              hint: t('payroll.amountNote'),
              child: TextField(
                key: const ValueKey('employee-amount'),
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
                      key: ValueKey('employee-currency-${currency.code}'),
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
                      key: ValueKey('employee-period-${period.name}'),
                      label: t(PayrollFormat.periodKey(period)),
                      selected: _period == period,
                      onTap: () => setState(() => _period = period),
                    ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 10),
          NeonButton(
            key: const ValueKey('employee-save'),
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
