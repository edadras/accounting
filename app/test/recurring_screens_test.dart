import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/data/recurring_repository.dart';
import 'package:finora/presentation/features/recurring/recurring_rule_editor_screen.dart';
import 'package:finora/presentation/features/recurring/recurring_screen.dart';
import 'package:finora/presentation/widgets/neon_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/alerts_recurring_harness.dart';
import 'support/fake_server.dart';

/// The recurring screens, driven against a mocked socket.
///
/// No `pumpAndSettle` anywhere: the list opens on a `CircularProgressIndicator`
/// and the cards animate their meters, so a settle would wait out its whole
/// budget instead of failing. Frames are pumped explicitly.
void main() {
  setUpAll(loadFeatureFonts);

  final rent = recurringJson(id: 'r-rent');

  final overdueSalary = recurringJson(
    id: 'r-salary',
    name: 'Salary',
    type: 'income',
    amount: 3200000,
    frequency: 'monthly',
    dayOfMonth: 1,
    nextRunAt: '2026-07-23T00:00:00Z',
    lastRunAt: '2026-06-23T00:00:00Z',
  );

  final pausedSubscription = recurringJson(
    id: 'r-sub',
    name: 'Streaming',
    frequency: 'daily',
    interval: 30,
    dayOfMonth: null,
    autoPost: false,
    isPaused: true,
    nextRunAt: '2026-08-20T00:00:00Z',
  );

  MockAdapter recurringServer({
    List<Map<String, Object?>> Function()? rules,
    ResponseBody Function(RequestOptions options)? onWrite,
  }) {
    return MockAdapter((options) {
      if (options.method.toUpperCase() == 'GET' &&
          options.path == '/recurring-rules') {
        return MockAdapter.json({'data': rules?.call() ?? [rent]});
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

  // ------------------------------------------------------------------- list

  testWidgets('a rule says what it posts, how often and when it next runs',
      (tester) async {
    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.en,
      adapter: recurringServer(),
    );

    expect(find.text('Rent'), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'recurring.nextRun')), findsOneWidget);

    // The schedule reads as one line, and only says what the server computes.
    expect(
      find.text(
        t(
          AppLocale.en,
          'recurring.scheduleWithDay',
          args: {
            'every': t(
              AppLocale.en,
              'recurring.everyMonths',
              args: {'count': number(AppLocale.en, 1)},
            ),
            'day': t(
              AppLocale.en,
              'recurring.onDay',
              args: {'day': number(AppLocale.en, 5)},
            ),
          },
        ),
      ),
      findsOneWidget,
    );

    // 250000 minor units of a two-decimal currency, rendered from the integer.
    expect(find.textContaining('2,500.00'), findsOneWidget);
  });

  testWidgets('a rule already owed glows; a paused one does not',
      (tester) async {
    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.en,
      adapter: recurringServer(
        rules: () => [overdueSalary, pausedSubscription],
      ),
    );

    expect(
      tester
          .widget<NeonCardShell>(find.byKey(const ValueKey('recurring-r-salary')))
          .glow,
      isTrue,
      reason: 'it is about to move money on its own',
    );
    expect(
      tester
          .widget<NeonCardShell>(find.byKey(const ValueKey('recurring-r-sub')))
          .glow,
      isFalse,
    );

    expect(
      find.text(
        t(
          AppLocale.en,
          'recurring.overdueBy',
          args: {'days': number(AppLocale.en, 2)},
        ),
      ),
      findsOneWidget,
    );

    // A paused, reminder-only rule says both things out loud.
    expect(find.text(t(AppLocale.en, 'recurring.paused')), findsOneWidget);
    expect(
      find.text(t(AppLocale.en, 'recurring.reminderOnly')),
      findsOneWidget,
    );
  });

  testWidgets('pausing patches only the field the API accepts', (tester) async {
    var rules = [rent];
    final adapter = recurringServer(
      rules: () => rules,
      onWrite: (options) {
        rules = [recurringJson(id: 'r-rent', isPaused: true)];
        return MockAdapter.json({'data': rules.first});
      },
    );

    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'recurring-toggle-r-rent');

    final patch = adapter.requests.lastWhere(
      (request) => request.method == 'PATCH',
    );

    expect(patch.path, '/recurring-rules/r-rent');
    expect(bodyOf(patch), {'is_paused': true});

    expect(
      find.byKey(const ValueKey('recurring-paused-r-rent')),
      findsOneWidget,
    );
  });

  testWidgets('deleting a rule asks first, then deletes', (tester) async {
    var rules = [rent];
    final adapter = recurringServer(
      rules: () => rules,
      onWrite: (options) {
        if (options.method == 'DELETE') rules = [];
        return MockAdapter.json(const <String, Object?>{}, status: 204);
      },
    );

    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'recurring-delete-r-rent');

    expect(
      find.text(t(AppLocale.en, 'recurring.deleteTitle')),
      findsOneWidget,
    );
    expect(rules, hasLength(1));

    await tapKey(tester, 'recurring-confirm-ok');

    expect(rules, isEmpty);
    expect(find.byKey(const ValueKey('recurring-empty')), findsOneWidget);
  });

  testWidgets('an empty list explains what a standing instruction is',
      (tester) async {
    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.en,
      adapter: recurringServer(rules: () => const []),
    );

    expect(find.byKey(const ValueKey('recurring-empty')), findsOneWidget);
    expect(find.text(t(AppLocale.en, 'recurring.emptyHint')), findsOneWidget);
  });

  testWidgets('a read-only role reads as a refusal, not as a crash',
      (tester) async {
    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(
        (options) => MockAdapter.error('forbidden', status: 403),
      ),
    );

    expect(
      find.text(t(AppLocale.en, 'recurring.noAccessTitle')),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('a build with no server says so instead of showing an empty list',
      (tester) async {
    await pumpFeature(tester, const RecurringScreen(), locale: AppLocale.en);

    expect(
      find.text(t(AppLocale.en, 'error.recurring_unavailable')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('recurring-empty')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the list reads in Persian, right to left', (tester) async {
    await pumpFeature(
      tester,
      const RecurringScreen(),
      locale: AppLocale.fa,
      adapter: recurringServer(),
    );

    for (final key in [
      'recurring.title',
      'recurring.nextRun',
      'recurring.everyMonths',
      'recurring.pause',
    ]) {
      expectTranslated(AppLocale.fa, key);
    }

    expect(find.text(t(AppLocale.fa, 'recurring.nextRun')), findsOneWidget);
    expect(
      Directionality.of(tester.element(find.byType(RecurringScreen))),
      TextDirection.rtl,
    );
    expect(tester.takeException(), isNull);
  });

  // ----------------------------------------------------------------- create

  testWidgets('a new rule sends integer minor units and a bare start date',
      (tester) async {
    final adapter = recurringServer(
      onWrite: (options) =>
          MockAdapter.json({'data': recurringJson(id: 'r-new')}, status: 201),
    );

    await pumpFeature(
      tester,
      const RecurringRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tester.enterText(
      find.byKey(const ValueKey('recurring-name')),
      'Gym',
    );
    await tester.enterText(
      find.byKey(const ValueKey('recurring-amount')),
      '250.00',
    );
    await tapKey(tester, 'recurring-freq-monthly');
    await tapKey(tester, 'recurring-day-plus');
    await tapKey(tester, 'recurring-save');

    final post = adapter.requests.lastWhere(
      (request) => request.method == 'POST',
    );
    final body = bodyOf(post);
    final template = body['template']! as Map;

    expect(body['name'], 'Gym');
    expect(body['frequency'], 'monthly');
    expect(body['interval'], 1);
    expect(body['day_of_month'], 1);
    expect(template['type'], 'expense');
    expect(
      template['amount'],
      25000,
      reason: 'TRY has two decimals; 250.00 is 25000 minor units, never a float',
    );
    expect(template['currency'], 'TRY');

    // A schedule is a calendar day, not an instant: sending midnight local time
    // as a UTC timestamp would move half the world by a day.
    expect(body['starts_at'], matches(r'^\d{4}-\d{2}-\d{2}$'));
  });

  testWidgets('an amount that is not an amount never reaches the server',
      (tester) async {
    final adapter = recurringServer();

    await pumpFeature(
      tester,
      const RecurringRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tester.enterText(
      find.byKey(const ValueKey('recurring-amount')),
      'lots',
    );
    await tapKey(tester, 'recurring-save');

    expect(find.text(t(AppLocale.en, 'tx.amountInvalid')), findsOneWidget);
    expect(
      adapter.requests.any((request) => request.method == 'POST'),
      isFalse,
    );
  });

  testWidgets('a transfer without a destination is refused here, not by the API',
      (tester) async {
    final adapter = recurringServer();

    await pumpFeature(
      tester,
      const RecurringRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'recurring-type-transfer');
    await tester.enterText(
      find.byKey(const ValueKey('recurring-amount')),
      '100.00',
    );
    await tapKey(tester, 'recurring-save');

    expect(
      find.text(t(AppLocale.en, 'recurring.counterRequired')),
      findsOneWidget,
    );
    expect(
      adapter.requests.any((request) => request.method == 'POST'),
      isFalse,
    );
  });

  testWidgets('the weekly option never offers a weekday the server ignores',
      (tester) async {
    await pumpFeature(
      tester,
      const RecurringRuleEditorScreen(),
      locale: AppLocale.en,
      adapter: recurringServer(),
    );

    await tapKey(tester, 'recurring-freq-weekly');

    expect(find.byKey(const ValueKey('recurring-day-plus')), findsNothing);
    expect(find.text(t(AppLocale.en, 'recurring.weeklyNote')), findsOneWidget);
  });

  // ------------------------------------------------------------------- edit

  testWidgets('editing offers only the four fields PATCH accepts',
      (tester) async {
    final adapter = recurringServer(
      onWrite: (options) => MockAdapter.json({'data': recurringJson(id: 'r-rent')}),
    );

    await pumpFeature(
      tester,
      RecurringRuleEditorScreen(existing: RecurringRule.fromJson(rent)),
      locale: AppLocale.en,
      adapter: adapter,
    );

    // The schedule is a fact on this screen, not a control.
    expect(
      find.text(t(AppLocale.en, 'recurring.scheduleLocked')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('recurring-freq-monthly')), findsNothing);
    expect(find.byKey(const ValueKey('recurring-amount')), findsNothing);
    expect(find.byKey(const ValueKey('recurring-interval-plus')), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('recurring-name')),
      'House rent',
    );
    await tapKey(tester, 'recurring-auto-post');
    await tapKey(tester, 'recurring-save');

    final patch = adapter.requests.lastWhere(
      (request) => request.method == 'PATCH',
    );
    final body = bodyOf(patch);

    expect(body['name'], 'House rent');
    expect(body['auto_post'], isFalse);
    expect(body['is_paused'], isFalse);

    // No end date on this rule, so clearing it is the honest request.
    expect(body.containsKey('ends_at'), isTrue);
    expect(body['ends_at'], isNull);
    expect(body.containsKey('template'), isFalse);
    expect(body.containsKey('frequency'), isFalse);
  });

  testWidgets('a server refusal is shown as its code, never its English',
      (tester) async {
    final adapter = recurringServer(
      onWrite: (options) => MockAdapter.error(
        'recurring_rule_not_found',
        status: 404,
        message: 'This English sentence must never reach the screen.',
      ),
    );

    await pumpFeature(
      tester,
      RecurringRuleEditorScreen(existing: RecurringRule.fromJson(rent)),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tapKey(tester, 'recurring-save');

    expect(
      find.text(t(AppLocale.en, 'error.recurring_rule_not_found')),
      findsOneWidget,
    );
    expect(
      find.text('This English sentence must never reach the screen.'),
      findsNothing,
    );
  });
}
