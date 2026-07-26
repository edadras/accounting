import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/data/family_repository.dart';
import 'package:finora/presentation/features/family/family_screen.dart';
import 'package:finora/presentation/features/family/member_detail_screen.dart';
import 'package:finora/presentation/widgets/neon_button.dart';
import 'package:finora/presentation/widgets/neon_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/family_budget_harness.dart';
import 'support/membership_harness.dart' show loadTestFonts, pumpFrames;

/// The family screens, driven against a mocked socket.
///
/// Nothing here calls `pumpAndSettle`: these screens open on a
/// `CircularProgressIndicator`, which never goes quiet. Frames are pumped
/// explicitly.
void main() {
  setUpAll(loadTestFonts);

  final parent = familyMemberJson(
    id: 'p1',
    displayName: 'زهرا کریمی',
    role: 'parent',
    accountId: 'acc-parent',
  );

  final child = familyMemberJson(
    id: 'c1',
    displayName: 'Sara',
    role: 'child',
    monthlyAllowance: 30000,
    spendingCap: 50000,
    accountId: 'acc-child',
  );

  /// Answers every family GET, and hands writes to [onWrite].
  MockAdapter familyServer({
    List<Map<String, Object?>> Function()? members,
    List<Map<String, Object?>> Function()? spending,
    List<Map<String, Object?>> Function()? allowances,
    ResponseBody Function(RequestOptions options)? onWrite,
  }) {
    return MockAdapter((options) {
      final path = options.path;

      if (options.method == 'GET') {
        if (path == '/family/members') {
          return MockAdapter.json({
            'data': members?.call() ?? [parent, child],
          });
        }
        if (path.startsWith('/family/members/')) {
          final id = path.split('/').last;
          final all = members?.call() ?? [parent, child];
          return MockAdapter.json({
            'data': all.firstWhere((member) => member['id'] == id),
          });
        }
        if (path == '/family/spending') {
          final rows = spending?.call() ??
              [
                memberSpendingJson(
                  memberId: 'p1',
                  displayName: 'زهرا کریمی',
                  role: 'parent',
                  spent: 120000,
                ),
                memberSpendingJson(
                  memberId: 'c1',
                  displayName: 'Sara',
                  role: 'child',
                  spent: 20000,
                  cap: 50000,
                ),
              ];

          final wanted = options.queryParameters['member_id'] as String?;
          return MockAdapter.json(
            spendingEnvelope(
              wanted == null
                  ? rows
                  : [
                      for (final row in rows)
                        if (row['member_id'] == wanted) row,
                    ],
            ),
          );
        }
        if (path == '/family/allowances') {
          return MockAdapter.json({'data': allowances?.call() ?? const []});
        }
      }

      if (onWrite != null) return onWrite(options);
      return MockAdapter.json(const <String, Object?>{}, status: 204);
    });
  }

  Map<String, Object?> bodyOf(RequestOptions options) {
    final data = options.data;
    if (data is Map) return data.cast<String, Object?>();
    return (jsonDecode(data! as String) as Map).cast<String, Object?>();
  }

  String standingOf(WidgetTester tester, String id) =>
      tester.widget<Text>(find.byKey(ValueKey('family-standing-$id'))).data!;

  group('the household list', () {
    testWidgets('names everyone and measures whoever has a cap',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const FamilyScreen(),
        locale: AppLocale.en,
        adapter: familyServer(),
      );

      expect(find.text('زهرا کریمی'), findsOneWidget);
      expect(find.text('Sara'), findsOneWidget);
      expect(find.text('Parent'), findsOneWidget);
      expect(find.text('Child'), findsOneWidget);

      // The parent has no cap, so nothing is measured against nothing.
      expect(standingOf(tester, 'p1'), 'No cap');
      expect(standingOf(tester, 'c1'), startsWith('Left'));

      expect(
        tester
            .widget<NeonCardShell>(
              find.byKey(const ValueKey('family-member-c1')),
            )
            .glow,
        isFalse,
      );
    });

    testWidgets('a member over their cap glows and says by how much',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const FamilyScreen(),
        locale: AppLocale.en,
        adapter: familyServer(
          spending: () => [
            memberSpendingJson(
              memberId: 'p1',
              displayName: 'زهرا کریمی',
              role: 'parent',
              spent: 0,
            ),
            memberSpendingJson(
              memberId: 'c1',
              displayName: 'Sara',
              role: 'child',
              spent: 65000,
              cap: 50000,
            ),
          ],
        ),
      );

      expect(standingOf(tester, 'c1'), startsWith('Over cap by'));
      expect(
        tester
            .widget<NeonCardShell>(
              find.byKey(const ValueKey('family-member-c1')),
            )
            .glow,
        isTrue,
      );
    });

    testWidgets('with no backend the screen explains instead of crashing',
        (tester) async {
      await pumpFinanceApp(tester, const FamilyScreen(), locale: AppLocale.en);

      expect(tester.takeException(), isNull);
      expect(
        find.text('The household needs a connection to the server.'),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('family-add-member')), findsNothing);
    });
  });

  group('a member', () {
    testWidgets('shows their spending against the cap and every payment paid',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const MemberDetailScreen(memberId: 'c1'),
        locale: AppLocale.en,
        adapter: familyServer(
          allowances: () => [
            allowancePaymentJson(
              id: 'ap-1',
              memberId: 'c1',
              payerMemberId: 'p1',
              amount: 30000,
              period: '2026-06',
            ),
          ],
        ),
      );

      expect(find.text('Sara'), findsWidgets);
      expect(find.byKey(const ValueKey('member-spending')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('allowance-payment-ap-1')),
        findsOneWidget,
      );
      expect(find.text('Spending cap'), findsWidgets);
    });

    testWidgets('paying sends the payer and the month, then refetches',
        (tester) async {
      var paid = <Map<String, Object?>>[];

      final adapter = familyServer(
        allowances: () => paid,
        onWrite: (options) {
          if (options.method == 'POST') {
            paid = [
              allowancePaymentJson(
                id: 'ap-new',
                memberId: 'c1',
                payerMemberId: 'p1',
                amount: 30000,
              ),
            ];
            return MockAdapter.json({'data': paid.first}, status: 201);
          }
          return MockAdapter.json(const <String, Object?>{}, status: 204);
        },
      );

      await pumpFinanceApp(
        tester,
        const MemberDetailScreen(memberId: 'c1'),
        locale: AppLocale.en,
        adapter: adapter,
      );

      expect(find.text('No allowance has been paid yet.'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('member-pay-allowance')));
      await pumpFrames(tester);

      // Nothing has been sent yet: the dialog still needs a payer.
      expect(find.byKey(const ValueKey('allowance-payer-p1')), findsOneWidget);
      // The recipient is never offered as their own payer.
      expect(find.byKey(const ValueKey('allowance-payer-c1')), findsNothing);
      expect(
        adapter.requests.where((request) => request.method == 'POST'),
        isEmpty,
      );

      await tester.tap(find.byKey(const ValueKey('allowance-payer-p1')));
      await pumpFrames(tester);
      await tester.tap(find.byKey(const ValueKey('allowance-confirm')));
      await pumpFrames(tester);

      final post = adapter.requests.firstWhere(
        (request) => request.method == 'POST',
      );

      expect(post.path, '/family/members/c1/allowance');

      final body = bodyOf(post);
      expect(body['payer_member_id'], 'p1');
      expect(body['period'], financePeriod);

      // No amount is sent: the server pays the allowance the member is
      // configured with, and inventing a figure here could disagree with it.
      expect(body.containsKey('amount'), isFalse);

      expect(
        find.byKey(const ValueKey('allowance-payment-ap-new')),
        findsOneWidget,
      );
    });

    testWidgets('a month already settled is not offered again', (tester) async {
      await pumpFinanceApp(
        tester,
        const MemberDetailScreen(memberId: 'c1'),
        locale: AppLocale.en,
        adapter: familyServer(
          allowances: () => [
            allowancePaymentJson(
              id: 'ap-1',
              memberId: 'c1',
              payerMemberId: 'p1',
              amount: 30000,
            ),
          ],
        ),
      );

      // The server enforces one payment per month with a unique index, so the
      // button says why and refuses the tap rather than offering a call that
      // can only be refused by the server.
      final button = tester.widget<NeonButton>(
        find.byKey(const ValueKey('member-pay-allowance')),
      );
      expect(button.onPressed, isNull);
      expect(find.text('Already paid this month'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('member-pay-allowance')));
      await pumpFrames(tester);

      expect(find.byKey(const ValueKey('allowance-confirm')), findsNothing);
    });

    testWidgets('a member with no account cannot be paid, and is told why',
        (tester) async {
      await pumpFinanceApp(
        tester,
        const MemberDetailScreen(memberId: 'c2'),
        locale: AppLocale.en,
        adapter: familyServer(
          members: () => [
            parent,
            familyMemberJson(
              id: 'c2',
              displayName: 'Reza',
              role: 'child',
              monthlyAllowance: 30000,
            ),
          ],
          spending: () => [
            memberSpendingJson(
              memberId: 'c2',
              displayName: 'Reza',
              role: 'child',
              spent: 0,
            ),
          ],
        ),
      );

      expect(
        find.text(
          'This member has no account, so money cannot move to them.',
        ),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('member-pay-allowance')));
      await pumpFrames(tester);

      expect(find.byKey(const ValueKey('allowance-confirm')), findsNothing);
    });

    testWidgets('a refusal reads in the app language, not the server English',
        (tester) async {
      final adapter = familyServer(
        onWrite: (options) => MockAdapter.error(
          FamilyFailure.allowanceAlreadyPaid,
          status: 409,
          message: 'SERVER-ENGLISH-PROSE about a duplicate payment.',
        ),
      );

      await pumpFinanceApp(
        tester,
        const MemberDetailScreen(memberId: 'c1'),
        locale: AppLocale.fa,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('member-pay-allowance')));
      await pumpFrames(tester);
      await tester.tap(find.byKey(const ValueKey('allowance-payer-p1')));
      await pumpFrames(tester);
      await tester.tap(find.byKey(const ValueKey('allowance-confirm')));
      await pumpFrames(tester);

      expect(
        find.text('پول‌توجیبی این ماه قبلاً پرداخت شده است.'),
        findsOneWidget,
      );
      expect(find.textContaining('SERVER-ENGLISH-PROSE'), findsNothing);
    });
  });

  group('editing a member', () {
    testWidgets('sends integer minor units and clears an emptied cap',
        (tester) async {
      final adapter = familyServer(
        onWrite: (options) => MockAdapter.json({'data': child}),
      );

      await pumpFinanceApp(
        tester,
        const MemberDetailScreen(memberId: 'c1'),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('member-edit')));
      await pumpFrames(tester);

      // The form opens on what is stored: 30000 minor units reads as 300.00.
      expect(
        tester
            .widget<TextField>(find.byKey(const ValueKey('member-allowance')))
            .controller!
            .text,
        '300.00',
      );

      await tester.enterText(
        find.byKey(const ValueKey('member-allowance')),
        '450',
      );
      await tester.enterText(find.byKey(const ValueKey('member-cap')), '');
      await tester.tap(find.byKey(const ValueKey('member-save')));
      await pumpFrames(tester);

      final patch = adapter.requests.firstWhere(
        (request) => request.method == 'PATCH',
      );
      final body = bodyOf(patch);

      expect(patch.path, '/family/members/c1');
      expect(body['monthly_allowance'], 45000);
      expect(body['monthly_allowance'], isA<int>());

      // An emptied field means "no ceiling at all", which is not a cap of zero.
      expect(body.containsKey('spending_cap'), isTrue);
      expect(body['spending_cap'], isNull);
    });

    testWidgets('a nameless member never reaches the server', (tester) async {
      final adapter = familyServer();

      await pumpFinanceApp(
        tester,
        const FamilyScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.tap(find.byKey(const ValueKey('family-add-member')));
      await pumpFrames(tester);

      await tester.tap(find.byKey(const ValueKey('member-save')));
      await pumpFrames(tester);

      expect(find.text('Give this member a name.'), findsOneWidget);
      expect(
        adapter.requests.where((request) => request.method == 'POST'),
        isEmpty,
      );
    });
  });

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    final screens = <String, Widget>{
      'family': const FamilyScreen(),
      'member': const MemberDetailScreen(memberId: 'c1'),
    };

    for (final entry in screens.entries) {
      testWidgets('${entry.key} builds in ${locale.code}', (tester) async {
        await pumpFinanceApp(
          tester,
          entry.value,
          locale: locale,
          adapter: familyServer(
            allowances: () => [
              allowancePaymentJson(
                id: 'ap-1',
                memberId: 'c1',
                payerMemberId: 'p1',
                amount: 30000,
                period: '2026-06',
              ),
            ],
          ),
        );

        expect(tester.takeException(), isNull);
        expect(
          Directionality.of(tester.element(find.byType(Scaffold).first)),
          locale.textDirection,
        );
      });
    }
  }
}
