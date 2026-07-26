import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/payroll_repository.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/presentation/features/payroll/payroll_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fake_server.dart';
import 'membership_harness.dart' show loadTestFonts, pumpFrames;

/// Everything the payroll screens need to run against a fake socket.
///
/// The two rules that hang this suite rather than failing it apply here too:
///
/// * anything that awaits Dio directly runs inside `tester.runAsync`, because
///   `testWidgets` drives a fake clock that never completes a real future;
/// * nothing calls `pumpAndSettle` — these screens open on a
///   `CircularProgressIndicator`, which never goes quiet.
ApiClient payrollClient(MockAdapter adapter) => ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1),
    );

/// Pumps a payroll screen with the repository pointed at [adapter].
///
/// Passing no adapter leaves `payrollRepositoryProvider` as it is in a build
/// with no server, which is the state the screens must explain rather than
/// crash on.
Future<void> pumpPayrollApp(
  WidgetTester tester,
  Widget screen, {
  required AppLocale locale,
  MockAdapter? adapter,
  List<Override> extraOverrides = const [],
}) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        localeProvider.overrideWith((ref) => locale),
        if (adapter != null)
          payrollRepositoryProvider.overrideWithValue(
            PayrollRepository(client: payrollClient(adapter)),
          ),
        ...extraOverrides,
      ],
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: NeonTheme.dark(fontFamily: 'Vazirmatn'),
        locale: locale.locale,
        supportedLocales: [for (final l in AppLocale.supported) l.locale],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        builder: (context, child) => Directionality(
          textDirection: locale.textDirection,
          child: child ?? const SizedBox.shrink(),
        ),
        home: screen,
      ),
    ),
  );

  await pumpFrames(tester);
}

// ------------------------------------------------------------------ fixtures

/// The wire shape of an amount: an integer count of minor units plus the two
/// fields that make it interpretable. `decimal` is display only.
Map<String, Object?> moneyJson(
  int value, {
  String currency = 'TRY',
  int minorUnit = 2,
}) {
  final digits = value.abs().toString().padLeft(minorUnit + 1, '0');
  final decimal = minorUnit == 0
      ? digits
      : '${digits.substring(0, digits.length - minorUnit)}.'
          '${digits.substring(digits.length - minorUnit)}';

  return {
    'value': value,
    'currency': currency,
    'minor_unit': minorUnit,
    'decimal': '${value < 0 ? '-' : ''}$decimal',
  };
}

Map<String, Object?> compensationJson({
  required String id,
  required int amount,
  String period = 'monthly',
  String effectiveFrom = '2026-01-01',
  String? effectiveTo,
}) =>
    {
      'id': id,
      'amount': moneyJson(amount),
      'period': period,
      'effective_from': effectiveFrom,
      'effective_to': effectiveTo,
    };

Map<String, Object?> employeeJson({
  required String id,
  required String name,
  String status = 'active',
  String? jobTitle = 'Engineer',
  String? country = 'TR',
  String startedOn = '2025-03-01',
  List<Map<String, Object?>> compensations = const [],
  Map<String, Object?>? compensation,
}) =>
    {
      'id': id,
      'employee_number': 'E-$id',
      'name': name,
      'job_title': jobTitle,
      'email': null,
      'phone': null,
      'country': country,
      'status': status,
      'has_ended': status == 'ended',
      'started_on': startedOn,
      'ended_on': null,
      'has_national_id': true,
      'compensation': compensation ?? _currentOf(compensations),
      'compensations': compensations,
      'notes': null,
      'version': 1,
    };

/// The row a run would use today: the one nothing has superseded.
Map<String, Object?>? _currentOf(List<Map<String, Object?>> compensations) {
  for (final row in compensations) {
    if (row['effective_to'] == null) return row;
  }
  return compensations.isEmpty ? null : compensations.last;
}

Map<String, Object?> payslipLineJson({
  required String id,
  required String kind,
  required int amount,
  String? label,
  String? code,
  double? rate,
  int sortOrder = 0,
}) =>
    {
      'id': id,
      'kind': kind,
      'code': code,
      'label': label,
      'amount': moneyJson(amount),
      'rate': rate,
      'sort_order': sortOrder,
    };

/// A payslip whose numbers are the server's: net is gross − deductions, and the
/// employer contribution is outside that subtraction entirely.
Map<String, Object?> payslipJson({
  String id = '01JPAYSLIP0000000000000000',
  String runId = '01JRUN00000000000000000000',
  String employeeName = 'Ada Lovelace',
  int gross = 1000000,
  int deductions = 200000,
  int contributions = 150000,
  List<Map<String, Object?>>? lines,
  bool isProrated = false,
}) =>
    {
      'id': id,
      'payroll_run_id': runId,
      'employee_id': '01JEMPLOYEE000000000000000',
      'gross': moneyJson(gross),
      'deductions': moneyJson(deductions),
      'contributions': moneyJson(contributions),
      'net': moneyJson(gross - deductions),
      'employer_cost': moneyJson(gross + contributions),
      'period_days': 31,
      'worked_days': isProrated ? 15 : 31,
      'is_prorated': isProrated,
      'country': 'TR',
      'tax_rules_name': 'TR 2026',
      'employee': {
        'id': '01JEMPLOYEE000000000000000',
        'name': employeeName,
        'job_title': 'Engineer',
      },
      'lines': lines ??
          [
            payslipLineJson(
              id: 'line-base',
              kind: 'earning',
              amount: gross,
              label: 'Base salary',
            ),
            payslipLineJson(
              id: 'line-tax',
              kind: 'deduction',
              amount: deductions,
              label: 'Income tax',
              rate: 20,
              sortOrder: 1,
            ),
            payslipLineJson(
              id: 'line-employer-ni',
              kind: 'contribution',
              amount: contributions,
              label: 'Employer social security',
              rate: 15,
              sortOrder: 2,
            ),
          ],
      'version': 1,
    };

Map<String, Object?> runJson({
  String id = '01JRUN00000000000000000000',
  String status = 'draft',
  String reference = 'PR-2026-07',
  int gross = 1000000,
  int deductions = 200000,
  int contributions = 150000,
  List<Map<String, Object?>>? payslips,
  bool posted = false,
}) =>
    {
      'id': id,
      'reference': reference,
      'status': status,
      'period_start': '2026-07-01',
      'period_end': '2026-07-31',
      'pay_date': '2026-08-01',
      'gross': moneyJson(gross),
      'deductions': moneyJson(deductions),
      'contributions': moneyJson(contributions),
      'net': moneyJson(gross - deductions),
      'employer_cost': moneyJson(gross + contributions),
      'account_id': '01JACCOUNT000000000000000',
      'category_id': null,
      'is_posted': posted || status != 'draft',
      'net_transaction_id': status == 'draft' ? null : '01JTXNET0000000000000000',
      'liability_transaction_id': null,
      'approved_at': status == 'draft' ? null : '2026-08-01T09:00:00Z',
      'approved_by': null,
      'paid_at': status == 'paid' ? '2026-08-05T09:00:00Z' : null,
      'payslip_count': payslips?.length,
      'payslips': payslips ?? [payslipJson()],
      'notes': null,
      'version': 1,
    };

Map<String, Object?> taxRuleJson({
  String id = '01JTAX00000000000000000000',
  String country = 'TR',
  String name = 'TR 2026',
  bool isActive = true,
}) =>
    {
      'id': id,
      'country': country,
      'name': name,
      'currency': 'TRY',
      'effective_from': '2026-01-01',
      'is_active': isActive,
      'rules': {
        'tax_base': 'gross_less_employee_contributions',
        'brackets': [
          {'up_to': 5000000, 'rate': 15},
          {'up_to': null, 'rate': 27},
        ],
        'employee_contributions': [
          {'code': 'sgk', 'label': 'Employee SGK', 'rate': 14, 'cap': null},
        ],
        'employer_contributions': [
          {'code': 'sgk_employer', 'label': 'Employer SGK', 'rate': 20.5, 'cap': null},
        ],
        'fixed_deductions': [
          {'code': 'union', 'label': 'Union dues', 'amount': 25000},
        ],
      },
      'version': 1,
    };

/// Re-exported so a test file needs one import for the whole harness.
Future<void> loadPayrollFonts() => loadTestFonts();

/// Frames, never `pumpAndSettle`: a payroll screen opens on a spinner and a
/// tree with a live progress indicator never goes quiet.
Future<void> pumpPayrollFrames(WidgetTester tester, {int frames = 6}) =>
    pumpFrames(tester, frames: frames);
