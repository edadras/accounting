import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/core/money/money_formatter.dart';
import 'package:finora/data/payroll_repository.dart';
import 'package:finora/presentation/features/more/module_scaffold.dart';
import 'package:finora/presentation/features/payroll/employee_detail_screen.dart';
import 'package:finora/presentation/features/payroll/employees_screen.dart';
import 'package:finora/presentation/features/payroll/payroll_run_detail_screen.dart';
import 'package:finora/presentation/features/payroll/payroll_runs_screen.dart';
import 'package:finora/presentation/features/payroll/payslip_screen.dart';
import 'package:finora/presentation/features/payroll/tax_rules_screen.dart';
import 'package:finora/presentation/widgets/neon_button.dart';
import 'package:finora/presentation/widgets/neon_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/payroll_harness.dart';

/// The payroll screens and their repository, driven against a mocked socket.
///
/// Nothing here calls `pumpAndSettle`: every one of these screens opens on a
/// `CircularProgressIndicator`, and a tree with a live spinner never goes
/// quiet. Anything that awaits Dio directly is wrapped in `tester.runAsync`,
/// because `testWidgets` drives a fake clock that never completes a real
/// future.
void main() {
  setUpAll(loadPayrollFonts);

  const runId = '01JRUN00000000000000000000';
  const payslipId = '01JPAYSLIP0000000000000000';

  /// TRY has two decimal places, so 1,000,000 minor units is ₺10,000.00.
  String formatted(int minorUnits, {String locale = 'en'}) =>
      MoneyFormatter.format(Money(minorUnits, Currency.try_), locale: locale);

  MockAdapter server(
    Map<String, Object? Function()> routes, {
    ResponseBody Function(RequestOptions options)? onWrite,
  }) =>
      MockAdapter((options) {
        if (options.method == 'GET') {
          final route = routes[options.path];
          if (route != null) return MockAdapter.json(route());
        }
        if (onWrite != null) return onWrite(options);
        return MockAdapter.json(const <String, Object?>{}, status: 204);
      });

  // ------------------------------------------------------------- repository

  test('a payslip carries the server totals, with contributions outside net',
      () async {
    final adapter = MockAdapter(
      (options) => MockAdapter.json({'data': payslipJson()}),
    );

    final payslip =
        await PayrollRepository(client: payrollClient(adapter)).payslip('x');

    // gross − deductions, exactly. The contribution is not in that subtraction.
    expect(payslip.gross.minorUnits, 1000000);
    expect(payslip.deductions.minorUnits, 200000);
    expect(payslip.net.minorUnits, 800000);
    expect(payslip.contributions.minorUnits, 150000);
    expect(payslip.employerCost.minorUnits, 1150000);

    expect(payslip.linesOf(PayslipLineKind.deduction).length, 1);
    expect(payslip.linesOf(PayslipLineKind.contribution).single.label,
        'Employer social security',);
    expect(PayslipLineKind.contribution.reducesNet, isFalse);
    expect(PayslipLineKind.deduction.reducesNet, isTrue);
  });

  test('a rate history arrives newest first and keeps the superseded rows',
      () async {
    final adapter = MockAdapter(
      (options) => MockAdapter.json({
        'data': employeeJson(
          id: 'e1',
          name: 'Ada Lovelace',
          compensations: [
            compensationJson(
              id: 'c-old',
              amount: 800000,
              effectiveFrom: '2025-03-01',
              effectiveTo: '2026-05-31',
            ),
            compensationJson(
              id: 'c-new',
              amount: 1000000,
              effectiveFrom: '2026-06-01',
            ),
          ],
        ),
      }),
    );

    final employee =
        await PayrollRepository(client: payrollClient(adapter)).employee('e1');

    expect([for (final row in employee.compensations) row.id],
        ['c-new', 'c-old'],);
    expect(employee.compensations.first.isCurrent, isTrue);
    expect(employee.compensations.last.isCurrent, isFalse);
  });

  test('paying a run sends the date under both field names the API uses',
      () async {
    final adapter = MockAdapter(
      (options) => MockAdapter.json({'data': runJson(status: 'paid')}),
    );

    await PayrollRepository(client: payrollClient(adapter))
        .payRun(runId, payDate: DateTime(2026, 8, 5));

    final body = (adapter.requests.single.data! as Map).cast<String, Object?>();

    // openapi.yaml documents `pay_date`; PayrollRunController::pay reads
    // `paid_at`. Sending one would work against exactly one of them.
    expect(body['pay_date'], '2026-08-05');
    expect(body['paid_at'], '2026-08-05');
  });

  test('a refusal arrives as a code, never as the English message', () async {
    final adapter = MockAdapter(
      (options) => MockAdapter.error(
        'payroll_run_not_a_draft',
        message: 'Payroll run [PR-1] is paid; only a draft may be discarded.',
      ),
    );

    await expectLater(
      PayrollRepository(client: payrollClient(adapter)).deleteRun(runId),
      throwsA(
        isA<PayrollException>()
            .having((e) => e.code, 'code', PayrollException.runNotADraft),
      ),
    );
  });

  // -------------------------------------------------------------- employees

  testWidgets('the employee list names the rate, and says when there is none',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const EmployeesScreen(),
      locale: AppLocale.en,
      adapter: server({
        '/employees': () => {
              'data': [
                employeeJson(
                  id: 'e1',
                  name: 'Ada Lovelace',
                  compensations: [
                    compensationJson(id: 'c1', amount: 1000000),
                  ],
                ),
                employeeJson(id: 'e2', name: 'Grace Hopper'),
              ],
              'meta': {'page': 1, 'per_page': 50, 'total': 2},
            },
      }),
    );

    expect(find.text('Ada Lovelace'), findsOneWidget);
    expect(find.text(formatted(1000000)), findsOneWidget);

    // Somebody with no rate is skipped by a run, so the row says so instead of
    // showing a blank where an amount belongs.
    expect(find.byKey(const ValueKey('employee-norate-e2')), findsOneWidget);
    expect(find.byKey(const ValueKey('employee-rate-e2')), findsNothing);
  });

  testWidgets('a rate change reads as an append, in Persian too',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const EmployeeDetailScreen(employeeId: 'e1'),
      locale: AppLocale.fa,
      adapter: server({
        '/employees/e1': () => {
              'data': employeeJson(
                id: 'e1',
                name: 'زهرا کریمی',
                jobTitle: 'مهندس',
                compensations: [
                  compensationJson(
                    id: 'c-old',
                    amount: 800000,
                    effectiveFrom: '2025-03-01',
                    effectiveTo: '2026-05-31',
                  ),
                  compensationJson(
                    id: 'c-new',
                    amount: 1000000,
                    effectiveFrom: '2026-06-01',
                  ),
                ],
              ),
            },
      }),
    );

    // Both rates are on screen: the superseded one is what makes a run from
    // last quarter explicable.
    expect(find.byKey(const ValueKey('rate-c-old')), findsOneWidget);
    expect(find.byKey(const ValueKey('rate-c-new')), findsOneWidget);
    expect(find.byKey(const ValueKey('employee-history-note')), findsOneWidget);
    expect(find.text(formatted(800000, locale: 'fa')), findsOneWidget);

    // RTL: the whole screen is laid out right-to-left, and nothing overflowed.
    expect(Directionality.of(tester.element(find.byType(ListView))),
        TextDirection.rtl,);
    expect(tester.takeException(), isNull);
  });

  // ------------------------------------------------------------------- runs

  testWidgets('an unapproved run with a real cost glows; a paid one does not',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const PayrollRunsScreen(),
      locale: AppLocale.en,
      adapter: server({
        '/payroll/runs': () => {
              'data': [
                runJson(id: 'draft-run', reference: 'PR-DRAFT'),
                runJson(id: 'paid-run', reference: 'PR-PAID', status: 'paid'),
              ],
              'meta': {'page': 1, 'per_page': 50, 'total': 2},
            },
      }),
    );

    NeonCardShell shellOf(String id) =>
        tester.widget<NeonCardShell>(find.byKey(ValueKey('run-$id')));

    expect(shellOf('draft-run').glow, isTrue);
    expect(shellOf('paid-run').glow, isFalse);
    expect(find.text('Draft'), findsWidgets);
    expect(find.text('Paid'), findsWidgets);
  });

  testWidgets('a draft offers approval and discard, but not payment',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const PayrollRunDetailScreen(runId: runId),
      locale: AppLocale.en,
      adapter: server({'/payroll/runs/$runId': () => {'data': runJson()}}),
    );

    expect(find.byKey(const ValueKey('run-approve')), findsOneWidget);
    expect(find.byKey(const ValueKey('run-delete')), findsOneWidget);
    expect(find.byKey(const ValueKey('run-pay')), findsNothing);

    // The machine is drawn, not implied.
    for (final step in ['draft', 'approved', 'paid']) {
      expect(find.byKey(ValueKey('run-step-$step')), findsOneWidget);
    }
  });

  testWidgets('an approved run offers payment, and no longer a discard',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const PayrollRunDetailScreen(runId: runId),
      locale: AppLocale.en,
      adapter: server({
        '/payroll/runs/$runId': () => {'data': runJson(status: 'approved')},
      }),
    );

    expect(find.byKey(const ValueKey('run-pay')), findsOneWidget);
    expect(find.byKey(const ValueKey('run-approve')), findsNothing);
    expect(find.byKey(const ValueKey('run-delete')), findsNothing);
  });

  testWidgets('a paid run offers no mutating action at all', (tester) async {
    await pumpPayrollApp(
      tester,
      const PayrollRunDetailScreen(runId: runId),
      locale: AppLocale.en,
      adapter: server({
        '/payroll/runs/$runId': () => {'data': runJson(status: 'paid')},
      }),
    );

    // Not disabled, not refused on tap — absent. A button that exists only to
    // be rejected is a lie about what the screen can do.
    expect(find.byKey(const ValueKey('run-approve')), findsNothing);
    expect(find.byKey(const ValueKey('run-pay')), findsNothing);
    expect(find.byKey(const ValueKey('run-delete')), findsNothing);
    expect(find.byType(NeonButton), findsNothing);

    expect(find.byKey(const ValueKey('run-immutable-note')), findsOneWidget);
    expect(
      find.text('This run has been paid. It is history, and no action on it '
          'exists.'),
      findsOneWidget,
    );
  });

  testWidgets('approving asks first, then posts to the approve endpoint',
      (tester) async {
    var status = 'draft';
    final adapter = MockAdapter((options) {
      if (options.method == 'POST' && options.path.endsWith('/approve')) {
        status = 'approved';
        return MockAdapter.json({'data': runJson(status: status)});
      }
      return MockAdapter.json({'data': runJson(status: status)});
    });

    await pumpPayrollApp(
      tester,
      const PayrollRunDetailScreen(runId: runId),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tester.tap(find.byKey(const ValueKey('run-approve')));
    await pumpPayrollFrames(tester);

    // The confirmation says what approving does before it does it.
    expect(find.text('Approve this run?'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('run-confirm-ok')));
    await pumpPayrollFrames(tester);

    expect(
      adapter.requests.where(
        (r) => r.method == 'POST' && r.path == '/payroll/runs/$runId/approve',
      ),
      hasLength(1),
    );
    expect(find.byKey(const ValueKey('run-pay')), findsOneWidget);
  });

  testWidgets('the run totals keep contributions out of net', (tester) async {
    await pumpPayrollApp(
      tester,
      const PayrollRunDetailScreen(runId: runId),
      locale: AppLocale.en,
      adapter: server({'/payroll/runs/$runId': () => {'data': runJson()}}),
    );

    String valueOf(String key) =>
        tester.widget<DetailRow>(find.byKey(ValueKey(key))).value;

    expect(valueOf('run-net'), formatted(800000));
    expect(valueOf('run-contributions'), formatted(150000));
    expect(valueOf('run-employer-cost'), formatted(1150000));

    // The number a reader would get by treating the contribution as a
    // deduction. It is nowhere on the screen.
    expect(find.text(formatted(650000)), findsNothing);
    expect(
      find.byKey(const ValueKey('run-contributions-note')),
      findsOneWidget,
    );
  });

  // --------------------------------------------------------------- payslips

  testWidgets('a contribution is itemised outside the deductions, not inside',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const PayslipScreen(payslipId: payslipId),
      locale: AppLocale.en,
      adapter: server({
        '/payroll/payslips/$payslipId': () => {'data': payslipJson()},
      }),
    );

    Finder within(String section, String line) => find.descendant(
          of: find.byKey(ValueKey(section)),
          matching: find.byKey(ValueKey(line)),
        );

    // The employer's charge is in its own block, below net — never among the
    // lines that come out of the employee's pay.
    expect(within('payslip-deductions', 'payslip-line-line-employer-ni'),
        findsNothing,);
    expect(within('payslip-contributions', 'payslip-line-line-employer-ni'),
        findsOneWidget,);
    expect(within('payslip-deductions', 'payslip-line-line-tax'),
        findsOneWidget,);

    // Net is the server's integer, and it is gross − deductions only.
    final net = tester
        .widget<DetailRow>(find.byKey(const ValueKey('payslip-net')))
        .value;
    expect(net, formatted(800000));
    expect(net, isNot(formatted(650000)));
    expect(find.text(formatted(650000)), findsNothing);

    expect(
      find.text('A contribution is paid by the employer and is not subtracted '
          'from net. It shows up in the employer cost.'),
      findsWidgets,
    );
  });

  testWidgets('the payslip keeps that distinction in Persian', (tester) async {
    await pumpPayrollApp(
      tester,
      const PayslipScreen(payslipId: payslipId),
      locale: AppLocale.fa,
      adapter: server({
        '/payroll/payslips/$payslipId': () => {'data': payslipJson()},
      }),
    );

    final net = tester
        .widget<DetailRow>(find.byKey(const ValueKey('payslip-net')))
        .value;
    expect(net, formatted(800000, locale: 'fa'));
    expect(find.text(formatted(650000, locale: 'fa')), findsNothing);

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('payslip-contributions')),
        matching: find.byKey(const ValueKey('payslip-line-line-employer-ni')),
      ),
      findsOneWidget,
    );
    expect(Directionality.of(tester.element(find.byType(ListView))),
        TextDirection.rtl,);
    expect(tester.takeException(), isNull);
  });

  // -------------------------------------------------------------- tax rules

  testWidgets('a rule set shows its brackets and both kinds of contribution',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const TaxRulesScreen(),
      locale: AppLocale.en,
      adapter: server({
        '/payroll/tax-rules': () => {'data': [taxRuleJson()]},
      }),
    );

    expect(find.byKey(const ValueKey('tax-rule-01JTAX00000000000000000000')),
        findsOneWidget,);
    expect(find.text('Employee SGK'), findsOneWidget);
    expect(find.text('Employer SGK'), findsOneWidget);
    expect(find.text('Union dues'), findsOneWidget);
    expect(find.text('Gross less employee contributions'), findsOneWidget);
    expect(find.text('Above the last ceiling'), findsOneWidget);
  });

  // ------------------------------------------------------------- no backend

  testWidgets('with no backend the screen explains itself instead of crashing',
      (tester) async {
    await pumpPayrollApp(
      tester,
      const EmployeesScreen(),
      locale: AppLocale.en,
    );

    expect(
      find.text('Payroll needs a connection to the server.'),
      findsOneWidget,
    );
    // The raw code never reaches a person.
    expect(find.text('error.payroll_unavailable'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
