import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/presentation/features/billing/billing_screen.dart';
import 'package:finora/presentation/features/billing/invoices_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/membership_harness.dart' show loadTestFonts;
import 'support/search_billing_harness.dart';

/// The billing screens.
///
/// The cancellation tests are the point of this file. `CancelSubscription`
/// keeps the paid plan alive until `renews_at` and cuts a trial off at once,
/// and the dialog has to say which of those two is about to happen, with the
/// date — a cancel flow that hides the date is the one people report as a dark
/// pattern.
void main() {
  setUpAll(loadTestFonts);

  /// The plan comparison runs off the bottom of a phone, so a tap has to bring
  /// its target into view first.
  Future<void> tapKey(WidgetTester tester, Key key) async {
    await tester.ensureVisible(find.byKey(key));
    await pumpFrames(tester);
    await tester.tap(find.byKey(key));
    await pumpFrames(tester);
  }

  /// `ensureVisible` parks its target at the top of the viewport, and a
  /// `ListView` does not keep off-screen children in the tree — so anything
  /// asserted about the plan card is asserted after coming back to it.
  Future<void> scrollToTop(WidgetTester tester) async {
    await tester.drag(find.byType(ListView).first, const Offset(0, 3000));
    await pumpFrames(tester);
  }

  testWidgets('the current plan card names the renewal date', (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    expect(find.text('Premium'), findsWidgets);
    expect(find.text('Active'), findsOneWidget);
    expect(find.textContaining('Renews on'), findsOneWidget);
    expect(find.textContaining('2026/08/24'), findsOneWidget);
  });

  testWidgets('cancelling states the date access actually ends',
      (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tapKey(tester, const ValueKey('billing-cancel'));

    final when = tester.widget<Text>(
      find.descendant(
        of: find.byKey(const ValueKey('billing-cancel-when')),
        matching: find.byType(Text),
      ),
    );

    // The date is stated, and it is the server's renewal date — not "now".
    expect(when.data, contains('2026/08/24'));
    expect(when.data, contains('You keep'));
    expect(when.data, contains('Free'));

    // What stops working, by name.
    expect(find.text('AI assistant'), findsWidgets);
    expect(find.text('Receipt text recognition'), findsWidgets);

    // And what happens to the data.
    expect(find.textContaining('No data is deleted'), findsOneWidget);

    await tapKey(tester, const ValueKey('billing-cancel-confirm'));

    expect(server.cancelCalls, 1);
    await scrollToTop(tester);

    // The plan is not presented as gone: the server still serves it until the
    // period ends, and the card says exactly that, with the same date.
    expect(find.textContaining('still active until'), findsOneWidget);
    expect(find.textContaining('2026/08/24'), findsOneWidget);
    expect(find.text('Cancelled'), findsOneWidget);
  });

  testWidgets('backing out of the cancel dialog cancels nothing',
      (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tapKey(tester, const ValueKey('billing-cancel'));

    await tester.tap(find.text('Keep my plan'));
    await pumpFrames(tester);
    await scrollToTop(tester);

    expect(server.cancelCalls, 0);
    expect(find.textContaining('Renews on'), findsOneWidget);
  });

  testWidgets('cancelling a trial says it ends today, and claims no date',
      (tester) async {
    final server = FakeBillingServer()
      ..status = 'trialing'
      ..onTrial = true
      ..trialEndsAt = '2026-08-08T12:00:00Z'
      ..renewsAt = null;

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    expect(find.textContaining('The trial ends on'), findsOneWidget);

    await tapKey(tester, const ValueKey('billing-cancel'));

    final when = tester.widget<Text>(
      find.descendant(
        of: find.byKey(const ValueKey('billing-cancel-when')),
        matching: find.byType(Text),
      ),
    );

    // A trial has nothing paid for, so the server ends it at once — and the
    // dialog must not promise the trial end date it is about to throw away.
    expect(when.data, contains('ends at once'));
    expect(when.data, isNot(contains('2026/08/08')));

    await tapKey(tester, const ValueKey('billing-cancel-confirm'));

    expect(server.cancelCalls, 1);
    await scrollToTop(tester);
    expect(find.textContaining('the paid period has ended'), findsOneWidget);
  });

  testWidgets('a plan change is presented as immediate, because it is',
      (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tapKey(tester, const ValueKey('billing-choose-family'));

    final when = tester.widget<Text>(
      find.descendant(
        of: find.byKey(const ValueKey('billing-change-when')),
        matching: find.byType(Text),
      ),
    );

    expect(when.data, contains('right now'));
    expect(when.data, contains('14.99'));
    expect(when.data, contains('per month'));

    await tapKey(tester, const ValueKey('billing-change-confirm'));

    expect(server.subscribeCalls, 1);
    expect(server.planCode, 'family');
  });

  testWidgets('a downgrade warns that the server refuses rather than deletes',
      (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tapKey(tester, const ValueKey('billing-choose-free'));

    expect(find.textContaining('never deletes data'), findsOneWidget);
  });

  testWidgets('a refused write is shown as translated wording', (tester) async {
    final server = FakeBillingServer()..failWith = 'already_on_plan';

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tapKey(tester, const ValueKey('billing-choose-family'));
    await tapKey(tester, const ValueKey('billing-change-confirm'));

    expect(find.text('You are already on this plan.'), findsOneWidget);
    expect(
      find.text('The workspace is already on that plan.'),
      findsNothing,
    );
  });

  testWidgets('the trial is offered once and says what it falls back to',
      (tester) async {
    final server = FakeBillingServer()
      ..planCode = 'free'
      ..effectivePlanCode = 'free'
      ..renewsAt = null;

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tapKey(tester, const ValueKey('billing-trial-premium'));

    expect(find.textContaining('falls back to the free plan'), findsOneWidget);
    expect(find.textContaining('one trial, once'), findsOneWidget);

    await tapKey(tester, const ValueKey('billing-trial-confirm'));

    expect(server.trialCalls, 1);
    await scrollToTop(tester);
    expect(find.textContaining('The trial ends on'), findsOneWidget);

    // A used trial is never offered again — the server refuses it, so the
    // button is gone rather than there and failing.
    expect(find.byKey(const ValueKey('billing-trial-family')), findsNothing);
  });

  testWidgets('usage is metered only where the plan has a cap', (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    // Premium caps members at one and nothing else.
    expect(find.byKey(const ValueKey('billing-usage-members')), findsOneWidget);
    expect(find.byKey(const ValueKey('billing-usage-accounts')), findsNothing);
    expect(find.textContaining('of'), findsWidgets);
  });

  testWidgets('the plan card renders integer minor units, not a double',
      (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    expect(find.textContaining('9.99'), findsOneWidget);
    expect(find.textContaining('14.99'), findsOneWidget);
    // The free plan says "Free" rather than a zero amount.
    expect(find.text('Free'), findsWidgets);
  });

  testWidgets('the whole screen renders in Persian and RTL', (tester) async {
    final server = FakeBillingServer();

    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.fa,
      adapter: MockAdapter(server.handle),
    );

    expect(find.text('اشتراک'), findsWidgets);
    expect(find.text('فعال'), findsOneWidget);

    await tapKey(tester, const ValueKey('billing-cancel'));

    final when = tester.widget<Text>(
      find.descendant(
        of: find.byKey(const ValueKey('billing-cancel-when')),
        matching: find.byType(Text),
      ),
    );

    // Jalali, because a Persian user reads ۱۴۰۵/۰۶/۰۲ rather than 2026/08/24 —
    // and the date is there in either calendar.
    expect(when.data, contains('۱۴۰۵'));
    expect(tester.takeException(), isNull);
  });

  testWidgets('with no backend the billing screen explains itself',
      (tester) async {
    await pumpSearchBillingApp(
      tester,
      const BillingScreen(),
      locale: AppLocale.en,
    );

    expect(
      find.text('Billing needs a connection to the server.'),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  group('invoices', () {
    testWidgets('an empty history says why it is empty', (tester) async {
      final server = FakeBillingServer();

      await pumpSearchBillingApp(
        tester,
        const InvoicesScreen(),
        locale: AppLocale.en,
        adapter: MockAdapter(server.handle),
      );

      expect(
        find.byKey(const ValueKey('billing-invoices-empty')),
        findsOneWidget,
      );
      expect(find.textContaining('never invoiced'), findsOneWidget);
    });

    testWidgets('a failed charge keeps its row', (tester) async {
      final server = FakeBillingServer()
        ..invoices = [
          invoiceJson(id: 'i1'),
          invoiceJson(id: 'i2', status: 'failed', paidAt: null),
        ];

      await pumpSearchBillingApp(
        tester,
        const InvoicesScreen(),
        locale: AppLocale.en,
        adapter: MockAdapter(server.handle),
      );

      expect(find.byKey(const ValueKey('billing-invoice-i1')), findsOneWidget);
      expect(find.byKey(const ValueKey('billing-invoice-i2')), findsOneWidget);
      expect(find.text('Paid'), findsOneWidget);
      expect(find.text('Failed'), findsOneWidget);
      expect(find.textContaining('Period'), findsWidgets);
    });
  });
}
