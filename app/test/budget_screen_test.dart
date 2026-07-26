import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/presentation/features/budget/budget_screen.dart';
import 'package:finora/presentation/widgets/neon_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/family_budget_harness.dart';
import 'support/membership_harness.dart' show loadTestFonts, pumpFrames;

/// The budget management screen, driven against a mocked socket.
///
/// Nothing here calls `pumpAndSettle`: the screen opens on a
/// `CircularProgressIndicator`, and a tree with a live progress indicator never
/// goes quiet. Frames are pumped explicitly.
void main() {
  setUpAll(loadTestFonts);

  /// Answers the two GETs the board makes, and hands everything else to
  /// [onWrite] so a test can decide what a POST or DELETE does.
  MockAdapter budgetServer({
    List<Map<String, Object?>> Function()? status,
    List<Map<String, Object?>> Function()? plans,
    ResponseBody Function(RequestOptions options)? onWrite,
  }) {
    return MockAdapter((options) {
      if (options.method == 'GET' && options.path == '/budgets/status') {
        return MockAdapter.json({'data': status?.call() ?? const []});
      }
      if (options.method == 'GET' && options.path == '/budgets') {
        return MockAdapter.json({'data': plans?.call() ?? const []});
      }
      if (onWrite != null) return onWrite(options);
      return MockAdapter.json(const <String, Object?>{}, status: 204);
    });
  }

  /// The rendered percent for a row.
  ///
  /// Read from the widget rather than matched as a literal: the number is
  /// wrapped in bidi isolate characters so it keeps its shape inside Persian
  /// text, and those are invisible in a source file.
  String percentOf(WidgetTester tester, String id) =>
      tester.widget<Text>(find.byKey(ValueKey('budget-percent-$id'))).data!;

  String standingOf(WidgetTester tester, String id) =>
      tester.widget<Text>(find.byKey(ValueKey('budget-standing-$id'))).data!;

  Map<String, Object?> bodyOf(RequestOptions options) {
    final data = options.data;
    if (data is Map) return data.cast<String, Object?>();
    return (jsonDecode(data! as String) as Map).cast<String, Object?>();
  }

  group('consumption', () {
    testWidgets('a budget exactly at its limit says so and does not glow',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: budgetServer(
          status: () => [
            budgetStatusJson(
              id: 'at-limit',
              name: 'Groceries',
              amount: 50000,
              spent: 50000,
            ),
          ],
          plans: () => [
            budgetPlanJson(id: 'at-limit', name: 'Groceries', amount: 50000),
          ],
        ),
      );

      // Integer arithmetic, so 50000 of 50000 is exactly 100 — never 99 or 101
      // through a rounded double.
      expect(percentOf(tester, 'at-limit'), contains('100'));
      expect(standingOf(tester, 'at-limit'), 'Exactly at the limit');

      // Spending every unit of a budget is not a breach, and glow is
      // information: nothing has gone wrong, so nothing glows.
      final card = tester.widget<NeonCardShell>(
        find.byKey(const ValueKey('budget-at-limit')),
      );
      expect(card.glow, isFalse);
      expect(find.textContaining('Over budget'), findsNothing);
    });

    testWidgets('a budget over its limit glows and says by how much',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: budgetServer(
          status: () => [
            budgetStatusJson(
              id: 'over',
              name: 'Leisure',
              amount: 50000,
              spent: 60000,
            ),
          ],
          plans: () => [
            budgetPlanJson(id: 'over', name: 'Leisure', amount: 50000),
          ],
        ),
      );

      expect(percentOf(tester, 'over'), contains('120'));
      expect(standingOf(tester, 'over'), startsWith('Over budget'));
      expect(find.text('Exactly at the limit'), findsNothing);

      final card = tester.widget<NeonCardShell>(
        find.byKey(const ValueKey('budget-over')),
      );
      expect(card.glow, isTrue);
    });

    testWidgets('a budget at 40% neither glows nor warns', (tester) async {
      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: budgetServer(
          status: () => [
            budgetStatusJson(
              id: 'calm',
              name: 'Transport',
              amount: 50000,
              spent: 20000,
            ),
          ],
          plans: () => [
            budgetPlanJson(id: 'calm', name: 'Transport', amount: 50000),
          ],
        ),
      );

      expect(percentOf(tester, 'calm'), contains('40'));
      expect(standingOf(tester, 'calm'), startsWith('Left'));
      expect(
        tester
            .widget<NeonCardShell>(find.byKey(const ValueKey('budget-calm')))
            .glow,
        isFalse,
      );
    });

    testWidgets('a carried-over remainder is shown apart from the ceiling',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: budgetServer(
          status: () => [
            // What the user set is 50000; last period left 18000 behind, so
            // 68000 may be spent. The server keeps the two apart and so does
            // the row.
            budgetStatusJson(
              id: 'rolled',
              name: 'Food',
              amount: 50000,
              effective: 68000,
              spent: 34000,
              rollover: true,
            ),
          ],
          plans: () => [
            budgetPlanJson(
              id: 'rolled',
              name: 'Food',
              amount: 50000,
              rollover: true,
            ),
          ],
        ),
      );

      expect(find.byKey(const ValueKey('budget-carried-rolled')), findsOneWidget);

      // 34000 of the 68000 effective ceiling, not of the 50000 the user typed.
      expect(percentOf(tester, 'rolled'), contains('50'));
    });

    testWidgets('a rollover budget with nothing carried in says so instead',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: budgetServer(
          status: () => [
            budgetStatusJson(
              id: 'fresh',
              name: 'Food',
              amount: 50000,
              spent: 1000,
              rollover: true,
            ),
          ],
          plans: () => [
            budgetPlanJson(
              id: 'fresh',
              name: 'Food',
              amount: 50000,
              rollover: true,
            ),
          ],
        ),
      );

      expect(find.byKey(const ValueKey('budget-carried-fresh')), findsNothing);
      expect(
        find.byKey(const ValueKey('budget-rollover-on-fresh')),
        findsOneWidget,
      );
    });
  });

  group('writing', () {
    testWidgets('creating a budget sends integer minor units', (tester) async {
      final adapter = budgetServer(
        onWrite: (options) => MockAdapter.json(
          {'data': budgetPlanJson(id: 'new', name: 'Food', amount: 50000)},
          status: 201,
        ),
      );

      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('budget-create')));
      await pumpFrames(tester);

      await tester.enterText(
        find.byKey(const ValueKey('budget-name')),
        'Food',
      );
      await tester.enterText(
        find.byKey(const ValueKey('budget-amount')),
        '500',
      );
      await tester.tap(find.byKey(const ValueKey('budget-rollover')));
      await pumpFrames(tester);

      await tester.tap(find.byKey(const ValueKey('budget-save')));
      await pumpFrames(tester);

      final post = adapter.requests.firstWhere(
        (request) => request.method == 'POST',
      );
      final body = bodyOf(post);

      // TRY has two minor units, so 500 typed is 50000 on the wire — an
      // integer, never a decimal string the server would have to re-parse.
      expect(body['amount'], 50000);
      expect(body['amount'], isA<int>());
      expect(body['currency'], 'TRY');
      expect(body['name'], 'Food');
      expect(body['scope'], 'overall');
      expect(body['scope_id'], isNull);
      expect(body['period'], 'monthly');
      expect(body['rollover'], isTrue);
    });

    testWidgets('an amount of zero never reaches the server', (tester) async {
      final adapter = budgetServer();

      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('budget-create')));
      await pumpFrames(tester);

      await tester.enterText(find.byKey(const ValueKey('budget-name')), 'Food');
      await tester.enterText(find.byKey(const ValueKey('budget-amount')), '0');
      await tester.tap(find.byKey(const ValueKey('budget-save')));
      await pumpFrames(tester);

      expect(find.text('Enter a limit greater than zero.'), findsOneWidget);
      expect(
        adapter.requests.where((request) => request.method == 'POST'),
        isEmpty,
      );
    });

    testWidgets('editing patches in place and keeps the budget id',
        (tester) async {
      final calls = <String>[];

      final adapter = budgetServer(
        status: () => [
          budgetStatusJson(
            id: 'old',
            name: 'Food',
            amount: 50000,
            spent: 10000,
          ),
        ],
        plans: () => [
          budgetPlanJson(id: 'old', name: 'Food', amount: 50000),
        ],
        onWrite: (options) {
          calls.add('${options.method} ${options.path}');
          if (options.method == 'PATCH') {
            return MockAdapter.json(
              {'data': budgetPlanJson(id: 'old', name: 'Food', amount: 60000)},
            );
          }
          return MockAdapter.json(const <String, Object?>{}, status: 204);
        },
      );

      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('budget-edit-old')));
      await pumpFrames(tester);

      // The form opens on what is stored, not on defaults.
      expect(find.text('Food'), findsWidgets);

      await tester.enterText(
        find.byKey(const ValueKey('budget-amount')),
        '600',
      );
      await tester.tap(find.byKey(const ValueKey('budget-save')));
      await pumpFrames(tester);

      // One call, and the id survives it — `budget_usages` hangs off that id,
      // so every period already measured goes on meaning the same thing.
      expect(calls, ['PATCH /budgets/old']);

      final body = bodyOf(adapter.requests.firstWhere(
        (request) => request.method == 'PATCH',
      ),);

      expect(body['amount'], 60000);
      expect(body['amount'], isA<int>());

      // The server rejects a currency outright, so the edit does not send one
      // even unchanged.
      expect(body.containsKey('currency'), isFalse);
    });

    testWidgets('a refused edit leaves the board as it was', (tester) async {
      final adapter = budgetServer(
        status: () => [
          budgetStatusJson(
            id: 'old',
            name: 'Food',
            amount: 50000,
            spent: 10000,
          ),
        ],
        plans: () => [
          budgetPlanJson(id: 'old', name: 'Food', amount: 50000),
        ],
        onWrite: (options) => MockAdapter.error(
          'forbidden',
          status: 403,
          message: 'SERVER-ENGLISH-PROSE about write access.',
        ),
      );

      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('budget-edit-old')));
      await pumpFrames(tester);

      await tester.enterText(
        find.byKey(const ValueKey('budget-amount')),
        '600',
      );
      await tester.tap(find.byKey(const ValueKey('budget-save')));
      await pumpFrames(tester);

      // Still on the form, told why, and nothing was deleted on the way — the
      // edit is one call that either happened or did not.
      expect(find.text('You do not have access to that.'), findsOneWidget);
      expect(find.textContaining('SERVER-ENGLISH-PROSE'), findsNothing);
      expect(
        adapter.requests.where((request) => request.method == 'DELETE'),
        isEmpty,
      );
    });

    testWidgets('deleting asks first, then refetches the board',
        (tester) async {
      var live = [
        budgetStatusJson(
          id: 'doomed',
          name: 'Leisure',
          amount: 50000,
          spent: 1000,
        ),
      ];

      final adapter = budgetServer(
        status: () => live,
        plans: () => [
          budgetPlanJson(id: 'doomed', name: 'Leisure', amount: 50000),
        ],
        onWrite: (options) {
          if (options.method == 'DELETE' &&
              options.path == '/budgets/doomed') {
            live = [];
          }
          return MockAdapter.json(const <String, Object?>{}, status: 204);
        },
      );

      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('budget-delete-doomed')));
      await pumpFrames(tester);

      // Nothing has happened yet — the confirmation is a real gate.
      expect(find.text('Delete this budget?'), findsOneWidget);
      expect(live, hasLength(1));

      await tester.tap(find.text('Delete').last);
      await pumpFrames(tester);

      expect(live, isEmpty);
      expect(find.byKey(const ValueKey('budget-doomed')), findsNothing);
      expect(
        find.text('No budgets yet. Create one to start measuring.'),
        findsOneWidget,
      );
    });

    testWidgets('a refusal reads in the app language, not the server English',
        (tester) async {
      final adapter = budgetServer(
        onWrite: (options) => MockAdapter.error(
          'validation_failed',
          status: 422,
          message: 'SERVER-ENGLISH-PROSE about the amount field.',
        ),
      );

      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: AppLocale.fa,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('budget-create')));
      await pumpFrames(tester);

      await tester.enterText(find.byKey(const ValueKey('budget-name')), 'خوراک');
      await tester.enterText(
        find.byKey(const ValueKey('budget-amount')),
        '500',
      );
      await tester.tap(find.byKey(const ValueKey('budget-save')));
      await pumpFrames(tester);

      expect(find.text('اطلاعات وارد شده معتبر نیست.'), findsOneWidget);
      expect(find.textContaining('SERVER-ENGLISH-PROSE'), findsNothing);
    });
  });

  testWidgets('with no backend the screen explains instead of crashing',
      (tester) async {
    await pumpFinanceApp(tester, const BudgetScreen(), locale: AppLocale.en);

    expect(tester.takeException(), isNull);
    expect(
      find.text('Budgets need a connection to the server.'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('budget-create')), findsNothing);
  });

  testWidgets('a caller below write access sees the permission state',
      (tester) async {
    await pumpFinanceApp(
      tester,
      const BudgetScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(
        (options) => MockAdapter.json(
          const {'message': 'This action is unauthorized.'},
          status: 403,
        ),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.text('You cannot manage budgets'), findsOneWidget);
    expect(find.text('This action is unauthorized.'), findsNothing);
  });

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    testWidgets('the board builds in ${locale.code}', (tester) async {
      await pumpFinanceApp(
        tester,
        const BudgetScreen(),
        locale: locale,
        adapter: budgetServer(
          status: () => [
            budgetStatusJson(
              id: 'a',
              name: 'خوراک',
              amount: 50000,
              effective: 68000,
              spent: 68000,
              rollover: true,
            ),
            budgetStatusJson(
              id: 'b',
              name: 'Leisure',
              amount: 40000,
              spent: 55000,
              period: 'yearly',
            ),
          ],
          plans: () => [
            budgetPlanJson(id: 'a', name: 'خوراک', amount: 50000, rollover: true),
            budgetPlanJson(
              id: 'b',
              name: 'Leisure',
              amount: 40000,
              period: 'yearly',
            ),
          ],
        ),
      );

      expect(tester.takeException(), isNull);
      expect(
        Directionality.of(tester.element(find.byType(Scaffold).first)),
        locale.textDirection,
      );

      // The at-limit row and the over row keep their distinct standings in both
      // directions.
      expect(
        tester
            .widget<NeonCardShell>(find.byKey(const ValueKey('budget-a')))
            .glow,
        isFalse,
      );
      expect(
        tester
            .widget<NeonCardShell>(find.byKey(const ValueKey('budget-b')))
            .glow,
        isTrue,
      );
    });
  }
}
