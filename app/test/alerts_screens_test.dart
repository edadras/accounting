import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/data/alerts_repository.dart';
import 'package:finora/presentation/features/alerts/alert_preferences_screen.dart';
import 'package:finora/presentation/features/alerts/alert_rule_editor_screen.dart';
import 'package:finora/presentation/features/alerts/alert_rules_screen.dart';
import 'package:finora/presentation/features/alerts/alerts_screen.dart';
import 'package:finora/presentation/widgets/neon_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/alerts_recurring_harness.dart';
import 'support/fake_server.dart';

/// The alerts screens, driven against a mocked socket.
///
/// Nothing here calls `pumpAndSettle`: these screens open on a
/// `CircularProgressIndicator`, and a tree with a live progress indicator never
/// goes quiet, so `pumpAndSettle` waits out its whole budget instead of
/// failing. Frames are pumped explicitly.
void main() {
  setUpAll(loadFeatureFonts);

  // Deliberately out of order: the read one is the newer, so an inbox that
  // sorted only by date would put it on top.
  final unreadCheque = alertJson(
    id: 'a-unread',
    type: 'check_due',
    payload: checkDuePayload(),
    channels: const {'database': 'sent', 'push': 'failed'},
    scheduledAt: '2026-07-24T09:00:00Z',
  );

  final readBalance = alertJson(
    id: 'a-read',
    type: 'low_balance',
    payload: lowBalancePayload(),
    scheduledAt: '2026-07-25T09:00:00Z',
    readAt: '2026-07-25T10:00:00Z',
  );

  MockAdapter alertsServer({
    List<Map<String, Object?>> Function()? alerts,
    List<Map<String, Object?>> Function()? rules,
    Map<String, Object?> Function()? preferences,
    ResponseBody Function(RequestOptions options)? onWrite,
  }) {
    return MockAdapter((options) {
      final method = options.method.toUpperCase();
      final path = options.path;

      if (method == 'GET' && path == '/alerts') {
        return MockAdapter.json({
          'data': alerts?.call() ?? [unreadCheque, readBalance],
        });
      }
      if (method == 'GET' && path == '/alerts/rules') {
        return MockAdapter.json({'data': rules?.call() ?? const []});
      }
      if (method == 'GET' && path == '/alerts/preferences') {
        return MockAdapter.json({
          'data': preferences?.call() ?? preferencesJson(),
        });
      }
      if (onWrite != null) return onWrite(options);
      return MockAdapter.json(const <String, Object?>{}, status: 204);
    });
  }

  Map<String, Object?> bodyOf(RequestOptions options) {
    final data = options.data;
    if (data is Map) return data.cast<String, Object?>();
    if (data is String) {
      return (jsonDecode(data) as Map).cast<String, Object?>();
    }
    return const {};
  }

  // ----------------------------------------------------------------- inbox

  testWidgets('the inbox puts an unread alert above a newer read one',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(),
    );

    final unread = tester.getTopLeft(find.byKey(const ValueKey('alert-a-unread')));
    final read = tester.getTopLeft(find.byKey(const ValueKey('alert-a-read')));

    expect(
      unread.dy,
      lessThan(read.dy),
      reason: 'an inbox is a queue of decisions, not a chronology',
    );

    // Glow is information: only the row still owed an answer carries it.
    expect(
      tester
          .widget<NeonCardShell>(find.byKey(const ValueKey('alert-a-unread')))
          .glow,
      isTrue,
    );
    expect(
      tester
          .widget<NeonCardShell>(find.byKey(const ValueKey('alert-a-read')))
          .glow,
      isFalse,
    );
  });

  testWidgets('a cheque alert spells out its own payload', (tester) async {
    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(alerts: () => [unreadCheque]),
    );

    expect(
      find.text(t(AppLocale.en, 'alerts.checkTitle', args: {'number': '1042'})),
      findsOneWidget,
    );
    expect(find.text('Ali Rezaei'), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'alerts.directionIssued')), findsOneWidget);
    expect(
      find.text(
        t(
          AppLocale.en,
          'alerts.dueIn',
          args: {'days': number(AppLocale.en, 3)},
        ),
      ),
      findsOneWidget,
    );

    // The money came from the integer minor units, not from the `decimal`
    // string beside them — which this fixture deliberately sets to a lie.
    expect(find.textContaining('1,250.00'), findsOneWidget);

    // A channel that failed says so; the alert exists in the app either way.
    expect(find.text(t(AppLocale.en, 'alerts.channelPush')), findsOneWidget);
  });

  testWidgets('a low-balance alert names the account and its floor',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(alerts: () => [readBalance]),
    );

    expect(
      find.text(
        t(AppLocale.en, 'alerts.balanceTitle', args: {'name': 'Bank Mellat'}),
      ),
      findsOneWidget,
    );
    expect(find.text(t(AppLocale.en, 'alerts.floor')), findsOneWidget);
    expect(find.textContaining('120.00'), findsOneWidget);
    expect(find.textContaining('500.00'), findsOneWidget);

    // Already read: no action, and no glow.
    expect(find.byKey(const ValueKey('alert-read-a-read')), findsNothing);
  });

  testWidgets('marking an alert read posts to the server and refetches',
      (tester) async {
    var current = [unreadCheque];
    final adapter = alertsServer(
      alerts: () => current,
      onWrite: (options) {
        if (options.path == '/alerts/a-unread/read') {
          current = [
            alertJson(
              id: 'a-unread',
              type: 'check_due',
              payload: checkDuePayload(),
              scheduledAt: '2026-07-24T09:00:00Z',
              readAt: '2026-07-25T11:00:00Z',
            ),
          ];
        }
        return MockAdapter.json({'data': current.first});
      },
    );

    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'alert-read-a-unread');

    expect(
      adapter.requests.any(
        (request) =>
            request.method == 'POST' &&
            request.path == '/alerts/a-unread/read',
      ),
      isTrue,
    );

    // Refetched rather than patched locally: the row now reads as read.
    expect(find.byKey(const ValueKey('alert-read-a-unread')), findsNothing);
    expect(find.text(t(AppLocale.en, 'alerts.read')), findsOneWidget);
  });

  testWidgets('the unread filter is a server query, not a local hide',
      (tester) async {
    final adapter = alertsServer();

    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'alerts-filter-unread');

    expect(
      adapter.requests.any(
        (request) =>
            request.path == '/alerts' &&
            request.queryParameters['unread'] == true,
      ),
      isTrue,
    );
  });

  testWidgets('an empty inbox explains itself', (tester) async {
    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(alerts: () => const []),
    );

    expect(find.byKey(const ValueKey('alerts-empty')), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'alerts.empty')), findsOneWidget);
    expect(find.byKey(const ValueKey('alerts-list')), findsNothing);
  });

  testWidgets('a refusal by role reads as a refusal, not as a crash',
      (tester) async {
    final adapter = MockAdapter(
      (options) => MockAdapter.error('forbidden', status: 403),
    );

    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    expect(find.text(t(AppLocale.en, 'alerts.noAccessTitle')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a build with no server says so instead of showing an empty list',
      (tester) async {
    await pumpFeature(tester, const AlertsScreen(), locale: AppLocale.en);

    expect(
      find.text(t(AppLocale.en, 'error.alerts_unavailable')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('alerts-list')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the inbox reads in Persian, right to left', (tester) async {
    await pumpFeature(
      tester,
      const AlertsScreen(),
      locale: AppLocale.fa,
      adapter: alertsServer(alerts: () => [unreadCheque]),
    );

    for (final key in [
      'alerts.title',
      'alerts.markRead',
      'alerts.typeCheckDue',
      'alerts.dueIn',
    ]) {
      expectTranslated(AppLocale.fa, key);
    }

    expect(find.text(t(AppLocale.fa, 'alerts.markRead')), findsOneWidget);
    expect(find.text(t(AppLocale.fa, 'alerts.typeCheckDue')), findsWidgets);
    expect(
      Directionality.of(tester.element(find.byType(AlertsScreen))),
      TextDirection.rtl,
    );
    expect(tester.takeException(), isNull);
  });

  // ----------------------------------------------------------------- rules

  testWidgets('a rule row names its kind, its lead time and its channels',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertRulesScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(
        rules: () => [
          alertRuleJson(
            id: 'r1',
            type: 'check_due',
            channels: const ['database', 'email'],
            leadDays: 7,
          ),
        ],
      ),
    );

    expect(find.text(t(AppLocale.en, 'alerts.typeCheckDue')), findsOneWidget);
    expect(
      find.text(
        t(
          AppLocale.en,
          'alerts.leadDaysValue',
          args: {'days': number(AppLocale.en, 7)},
        ),
      ),
      findsOneWidget,
    );
    expect(find.text(t(AppLocale.en, 'alerts.channelEmail')), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'alerts.ruleActive')), findsOneWidget);
  });

  testWidgets('no rules at all is a state with words on it', (tester) async {
    await pumpFeature(
      tester,
      const AlertRulesScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(),
    );

    expect(find.byKey(const ValueKey('alerts-rules-empty')), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'alerts.rulesEmpty')), findsOneWidget);
  });

  testWidgets('deleting a rule asks first, then deletes', (tester) async {
    var rules = [alertRuleJson(id: 'r1', type: 'low_balance')];
    final adapter = alertsServer(
      rules: () => rules,
      onWrite: (options) {
        if (options.method == 'DELETE' && options.path == '/alerts/rules/r1') {
          rules = [];
        }
        return MockAdapter.json(const <String, Object?>{}, status: 204);
      },
    );

    await pumpFeature(
      tester,
      const AlertRulesScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'rule-delete-r1');

    // Nothing has happened yet — the confirmation is a real gate.
    expect(find.text(t(AppLocale.en, 'alerts.deleteRuleTitle')), findsOneWidget);
    expect(rules, hasLength(1));

    await tapKey(tester, 'alerts-confirm-ok');

    expect(rules, isEmpty);
    expect(find.byKey(const ValueKey('alerts-rules-empty')), findsOneWidget);
  });

  testWidgets('turning a rule off is one PATCH carrying one field',
      (tester) async {
    final adapter = alertsServer(
      rules: () => [alertRuleJson(id: 'r1', type: 'check_due', leadDays: 5)],
      onWrite: (options) => MockAdapter.json({
        'data': alertRuleJson(id: 'r1', type: 'check_due', isActive: false),
      }),
    );

    await pumpFeature(
      tester,
      const AlertRulesScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'rule-toggle-r1');

    final writes = adapter.requests
        .where((request) => request.path.startsWith('/alerts/rules'))
        .where((request) => request.method != 'GET')
        .toList();

    // The delete-then-recreate this replaced had a window in which the rule did
    // not exist; a single PATCH has none.
    expect(writes.map((request) => request.method), ['PATCH']);
    expect(writes.single.path, '/alerts/rules/r1');

    // Only the flag that was flipped. Anything else would be this row claiming
    // to know settings it never showed.
    expect(bodyOf(writes.single), {'is_active': false});
  });

  // ---------------------------------------------------------------- editor

  testWidgets('a low-balance rule sends its floor as integer minor units',
      (tester) async {
    final adapter = alertsServer(
      onWrite: (options) => MockAdapter.json(
        {'data': alertRuleJson(id: 'r-new', type: 'low_balance')},
        status: 201,
      ),
    );

    await pumpFeature(
      tester,
      const AlertRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'rule-type-low_balance');

    await tester.enterText(find.byKey(const ValueKey('rule-threshold')), '500.00');
    await tapKey(tester, 'rule-channel-email');

    await tapKey(tester, 'rule-save');

    final post = adapter.requests.lastWhere(
      (request) => request.method == 'POST',
    );
    final body = bodyOf(post);

    expect(body['type'], 'low_balance');
    expect(
      (body['config']! as Map)['threshold'],
      50000,
      reason: 'TRY has two decimals; 500.00 is 50000 minor units, never a float',
    );
    expect(body['channels'], containsAll(<String>['database', 'email']));
  });

  testWidgets('a floor that is not an amount is refused before the round trip',
      (tester) async {
    final adapter = alertsServer();

    await pumpFeature(
      tester,
      const AlertRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'rule-type-low_balance');

    await tester.enterText(find.byKey(const ValueKey('rule-threshold')), 'abc');
    await tapKey(tester, 'rule-save');

    expect(
      find.text(t(AppLocale.en, 'alerts.thresholdInvalid')),
      findsOneWidget,
    );
    expect(
      adapter.requests.any((request) => request.method == 'POST'),
      isFalse,
    );
  });

  testWidgets('editing a rule patches it in place and never sends its type',
      (tester) async {
    final adapter = alertsServer(
      onWrite: (options) => MockAdapter.json({
        'data': alertRuleJson(id: 'r1', type: 'low_balance'),
      }),
    );

    await pumpFeature(
      tester,
      AlertRuleEditorScreen(
        existing: AlertRule.fromJson(
          alertRuleJson(
            id: 'r1',
            type: 'low_balance',
            // A per-account map this screen has no control for. It belongs to
            // the same scanner, so an edit must carry it through untouched.
            config: const {
              'threshold': 50000,
              'thresholds': {'acc-1': 9900},
            },
            channels: const ['database', 'email'],
            leadDays: 5,
          ),
        ),
      ),
      locale: AppLocale.en,
      adapter: adapter,
    );

    // The type decides what `config` means, so it is a fact here, not a choice.
    expect(find.byKey(const ValueKey('rule-type-low_balance')), findsNothing);
    expect(find.text(t(AppLocale.en, 'alerts.typeLowBalance')), findsWidgets);

    await tester.enterText(
      find.byKey(const ValueKey('rule-threshold')),
      '750.00',
    );
    await tapKey(tester, 'rule-save');

    final writes = adapter.requests
        .where((request) => request.method != 'GET')
        .toList();

    expect(writes.map((request) => request.method), ['PATCH']);
    expect(writes.single.path, '/alerts/rules/r1');

    final body = bodyOf(writes.single);
    expect(
      body.containsKey('type'),
      isFalse,
      reason: 'a changed type is a 422, and an unchanged one is noise',
    );
    expect((body['config']! as Map)['threshold'], 75000);
    expect(
      (body['config']! as Map)['thresholds'],
      {'acc-1': 9900},
      reason: 'a setting the editor cannot show is a setting it must not drop',
    );
    expect(body['channels'], containsAll(<String>['database', 'email']));
    expect(body['lead_days'], 5);
  });

  testWidgets('an edit that fails leaves the rule exactly as it was',
      (tester) async {
    // The property the old delete-then-recreate could not offer: there, a
    // refusal on the second request had already destroyed the rule.
    final rules = [
      alertRuleJson(id: 'r1', type: 'check_due', leadDays: 5),
    ];

    final adapter = alertsServer(
      rules: () => rules,
      onWrite: (options) => MockAdapter.error(
        'validation_failed',
        status: 422,
        message: 'This English sentence must never reach the screen.',
      ),
    );

    await pumpFeature(
      tester,
      const AlertRulesScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'rule-toggle-r1');

    expect(
      adapter.requests.any((request) => request.method == 'DELETE'),
      isFalse,
      reason: 'nothing is removed on the way to an edit',
    );

    // Still there, still listed, still active.
    expect(rules, hasLength(1));
    expect(find.byKey(const ValueKey('rule-r1')), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'alerts.ruleActive')), findsOneWidget);

    expect(
      find.text(t(AppLocale.en, 'error.validation_failed')),
      findsOneWidget,
    );
    expect(
      find.text('This English sentence must never reach the screen.'),
      findsNothing,
    );
  });

  testWidgets('the lead-time stepper only exists for the rules that use it',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(),
    );

    expect(find.byKey(const ValueKey('rule-lead-plus')), findsOneWidget);

    await tapKey(tester, 'rule-lead-plus');

    expect(
      find.text(
        t(
          AppLocale.en,
          'alerts.leadDaysValue',
          args: {'days': number(AppLocale.en, 4)},
        ),
      ),
      findsOneWidget,
    );

    await tapKey(tester, 'rule-type-budget_threshold');

    expect(find.byKey(const ValueKey('rule-lead-plus')), findsNothing);
    expect(find.byKey(const ValueKey('rule-threshold')), findsNothing);
    expect(find.text(t(AppLocale.en, 'alerts.budgetRuleNote')), findsOneWidget);
  });

  // ----------------------------------------------------------- preferences

  testWidgets('preferences show what is off and refuse to fake the rest',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertPreferencesScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(
        preferences: () => preferencesJson(
          channels: const {'sms': false},
          quietStart: '22:00',
          quietEnd: '07:00',
          timezone: 'Asia/Tehran',
        ),
      ),
    );

    expect(
      tester.widget<Switch>(find.byKey(const ValueKey('pref-channel-sms'))).value,
      isFalse,
    );

    // The database channel is the alert itself, so it is shown locked rather
    // than as a switch that would do nothing.
    expect(
      tester
          .widget<Switch>(find.byKey(const ValueKey('pref-channel-database')))
          .onChanged,
      isNull,
    );
    expect(find.text(t(AppLocale.en, 'alerts.channelLocked')), findsWidgets);

    expect(find.byKey(const ValueKey('pref-quiet-start-value')), findsOneWidget);
    expect(find.text('22:00'), findsOneWidget);
    expect(find.text('07:00'), findsOneWidget);
  });

  testWidgets('saving sends the whole window as HH:MM the server can parse',
      (tester) async {
    final adapter = alertsServer(
      preferences: () => preferencesJson(
        quietStart: '22:00',
        quietEnd: '07:00',
      ),
      onWrite: (options) => MockAdapter.json({
        'data': preferencesJson(quietStart: '22:15', quietEnd: '07:00'),
      }),
    );

    await pumpFeature(
      tester,
      const AlertPreferencesScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'pref-quiet-start-plus');
    await tapKey(tester, 'pref-channel-push');
    await tapKey(tester, 'pref-tz-Asia/Tehran');

    await tapKey(tester, 'pref-save');

    final put = adapter.requests.lastWhere((request) => request.method == 'PUT');
    final body = bodyOf(put);

    expect(body['quiet_hours_start'], '22:15');
    expect(body['quiet_hours_end'], '07:00');
    expect(body['timezone'], 'Asia/Tehran');
    expect((body['channels']! as Map)['push'], isFalse);

    // The mandatory channel is never sent as a preference the server would
    // have to ignore.
    expect((body['channels']! as Map).containsKey('database'), isFalse);

    expect(find.byKey(const ValueKey('pref-saved')), findsOneWidget);
  });

  testWidgets('turning quiet hours on seeds a real window, not an empty one',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertPreferencesScreen(),
      locale: AppLocale.en,
      adapter: alertsServer(),
    );

    expect(find.byKey(const ValueKey('pref-quiet-start-value')), findsNothing);

    await tapKey(tester, 'pref-quiet-enabled');

    expect(find.text('22:00'), findsOneWidget);
    expect(find.text('07:00'), findsOneWidget);
  });

  testWidgets('a server refusal on save is shown as its code, translated',
      (tester) async {
    final adapter = alertsServer(
      onWrite: (options) => MockAdapter.error(
        'invalid_quiet_window',
        status: 422,
        message: 'This English sentence must never reach the screen.',
      ),
    );

    await pumpFeature(
      tester,
      const AlertPreferencesScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'pref-save');

    expect(
      find.text(t(AppLocale.en, 'error.invalid_quiet_window')),
      findsOneWidget,
    );
    expect(
      find.text('This English sentence must never reach the screen.'),
      findsNothing,
    );
  });

  testWidgets('preferences with no server behind them explain themselves',
      (tester) async {
    await pumpFeature(
      tester,
      const AlertPreferencesScreen(),
      locale: AppLocale.fa,
    );

    expect(
      find.text(t(AppLocale.fa, 'error.alerts_unavailable')),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });
}
