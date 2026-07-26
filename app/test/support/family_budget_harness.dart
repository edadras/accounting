import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/budget_repository.dart';
import 'package:finora/data/family_repository.dart';
import 'package:finora/data/ledger_repository.dart' show baseCurrencyProvider;
import 'package:finora/data/modules_repository.dart' show clockProvider;
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/presentation/features/budget/budget_providers.dart';
import 'package:finora/presentation/features/family/family_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fake_server.dart';
import 'membership_harness.dart' show pumpFrames;

/// Everything the budget and family screens need to run against a fake socket.
///
/// The same two rules the membership harness documents apply here, and breaking
/// either one hangs the suite rather than failing it:
///
/// * nothing calls `pumpAndSettle` — these screens open on a
///   `CircularProgressIndicator`, which never goes quiet;
/// * the retry policy is a single attempt, so no backoff timer is ever armed
///   under the fake clock.
///
/// [MockAdapter] answers from a plain function, so every response lands on a
/// microtask that an ordinary [pumpFrames] flushes — no real Dio timer is
/// involved, and therefore no `runAsync` is needed to drive the widget tree.
ApiClient financeClient(MockAdapter adapter) => ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1),
    );

/// A fixed clock, so "this month" is the same month on every run.
final financeClock = DateTime.utc(2026, 7, 25, 12);
const financePeriod = '2026-07';

/// The books' currency in these tests. Two minor units, so an amount typed as
/// `500` has to reach the wire as `50000` — which is the whole point of the
/// integer-money assertions.
const financeCurrency = Currency.try_;

Future<void> pumpFinanceApp(
  WidgetTester tester,
  Widget screen, {
  required AppLocale locale,

  /// Null leaves both repositories unprovided, which is the demo build: the
  /// providers throw and the screens must explain rather than crash.
  MockAdapter? adapter,
  List<Override> extraOverrides = const [],
}) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final client = adapter == null ? null : financeClient(adapter);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        localeProvider.overrideWith((ref) => locale),
        clockProvider.overrideWithValue(financeClock),
        baseCurrencyProvider.overrideWithValue(financeCurrency),
        if (client != null) ...[
          budgetRepositoryProvider.overrideWithValue(
            BudgetRepository(client: client, baseCurrency: financeCurrency),
          ),
          familyRepositoryProvider.overrideWithValue(
            FamilyRepository(client: client, baseCurrency: financeCurrency),
          ),
        ],
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

/// The one money shape the API speaks (docs/05-api-conventions.md §5).
Map<String, Object?> moneyJson(int value, {Currency currency = financeCurrency}) {
  final digits = value.abs().toString().padLeft(currency.minorUnit + 1, '0');
  final sign = value < 0 ? '-' : '';
  final decimal = currency.minorUnit == 0
      ? '$sign$digits'
      : '$sign${digits.substring(0, digits.length - currency.minorUnit)}'
          '.${digits.substring(digits.length - currency.minorUnit)}';

  return {
    'value': value,
    'currency': currency.code,
    'minor_unit': currency.minorUnit,
    'decimal': decimal,
  };
}

/// A row of `GET /budgets/status`.
///
/// [amount] is the ceiling the user set and [effective] is what may actually be
/// spent once a carry-over is added — the server keeps them apart, and so does
/// this fixture.
Map<String, Object?> budgetStatusJson({
  required String id,
  required String name,
  required int amount,
  required int spent,
  int? effective,
  String scope = 'overall',
  String? scopeId,
  String period = 'monthly',
  bool rollover = false,
}) {
  final ceiling = effective ?? amount;

  return {
    'id': id,
    'name': name,
    'scope': scope,
    'scope_id': scopeId,
    'period': period,
    'period_key': financePeriod,
    'period_start': '2026-07-01T00:00:00+00:00',
    'period_end': '2026-07-31T23:59:59+00:00',
    'amount': moneyJson(amount),
    'effective_amount': moneyJson(ceiling),
    'spent': moneyJson(spent),
    'remaining': moneyJson(ceiling - spent),
    'percentage': spent * 100 / (ceiling == 0 ? 1 : ceiling),
    'rollover': rollover,
    'alert_thresholds': const [80, 100],
    'thresholds_crossed': const <int>[],
    'is_over_budget': ceiling - spent < 0,
  };
}

/// A row of `GET /budgets` — the definition, not the consumption.
Map<String, Object?> budgetPlanJson({
  required String id,
  required String name,
  required int amount,
  String scope = 'overall',
  String? scopeId,
  String period = 'monthly',
  bool rollover = false,
}) =>
    {
      'id': id,
      'name': name,
      'scope': scope,
      'scope_id': scopeId,
      'period': period,
      'starts_at': '2026-01-01T00:00:00+00:00',
      'ends_at': null,
      'amount': moneyJson(amount),
      'rollover': rollover,
      'alert_thresholds': const [80, 100],
      'version': 1,
      'created_at': '2026-01-01T00:00:00+00:00',
      'updated_at': '2026-01-01T00:00:00+00:00',
    };

Map<String, Object?> familyMemberJson({
  required String id,
  required String displayName,
  required String role,
  int? monthlyAllowance,
  int? spendingCap,
  String? accountId,
  String? birthDate,
}) =>
    {
      'id': id,
      'user_id': null,
      'display_name': displayName,
      'role': role,
      'birth_date': birthDate,
      'monthly_allowance':
          monthlyAllowance == null ? null : moneyJson(monthlyAllowance),
      'spending_cap': spendingCap == null ? null : moneyJson(spendingCap),
      'currency': financeCurrency.code,
      'account_id': accountId,
      'tag': 'member:$id',
    };

Map<String, Object?> memberSpendingJson({
  required String memberId,
  required String displayName,
  required String role,
  required int spent,
  int? cap,
}) =>
    {
      'member_id': memberId,
      'display_name': displayName,
      'role': role,
      'period': financePeriod,
      'spent': moneyJson(spent),
      'cap': cap == null ? null : moneyJson(cap),
      'remaining': cap == null ? null : moneyJson(cap - spent),
      'percentage': cap == null ? null : spent * 100 / (cap == 0 ? 1 : cap),
      'is_over_cap': cap != null && spent > cap,
    };

Map<String, Object?> allowancePaymentJson({
  required String id,
  required String memberId,
  required String payerMemberId,
  required int amount,
  String period = financePeriod,
  String paidAt = '2026-07-02T09:00:00+00:00',
}) =>
    {
      'id': id,
      'member_id': memberId,
      'payer_member_id': payerMemberId,
      'period': period,
      'amount': moneyJson(amount),
      'transaction_id': 'tx-$id',
      'paid_at': paidAt,
    };

/// The spending endpoint always answers with the window it measured.
Map<String, Object?> spendingEnvelope(List<Map<String, Object?>> rows) => {
      'data': rows,
      'meta': {
        'period': financePeriod,
        'from': '2026-07-01T00:00:00+00:00',
        'to': '2026-07-31T23:59:59+00:00',
      },
    };
