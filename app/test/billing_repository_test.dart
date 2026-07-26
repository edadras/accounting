import 'package:finora/data/billing_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/search_billing_harness.dart';

/// The mapping layer between the billing controllers and the screens, plus the
/// one piece of reasoning the client is allowed to do on its own: working out
/// what a cancellation would actually do, so the confirmation can say it before
/// the button is pressed.
void main() {
  BillingRepository repositoryFor(FakeBillingServer server) =>
      BillingRepository(client: fakeClient(MockAdapter(server.handle)));

  group('catalogue', () {
    test('prices arrive as integer minor units under their own key', () async {
      final plans = await repositoryFor(FakeBillingServer()).plans();

      expect(plans.map((plan) => plan.code), ['free', 'premium', 'family']);

      final premium = plans[1];
      // The billing envelope names the integer `amount` where the ledger names
      // it `value`; both are minor units and neither is a decimal.
      expect(premium.price.minorUnits, 999);
      expect(premium.price.currency.code, 'USD');
      expect(premium.interval, PlanInterval.monthly);
      expect(premium.isFree, isFalse);
      expect(plans.first.isFree, isTrue);

      expect(premium.limits['members'], 1);
      expect(premium.limits.containsKey('accounts'), isTrue);
      expect(premium.limits['accounts'], isNull); // unlimited
      expect(premium.includedFeatures, contains('ai'));
      expect(premium.includedFeatures, isNot(contains('sso')));
    });

    test('what a plan loses against a richer one is computable', () async {
      final plans = await repositoryFor(FakeBillingServer()).plans();
      final free = plans.first;
      final family = plans.last;

      expect(
        free.featuresLostAgainst(family),
        containsAll(['ai', 'ocr', 'voice', 'investment', 'shared_budget']),
      );
      expect(family.featuresLostAgainst(free), isEmpty);
    });
  });

  group('subscription', () {
    test('carries entitlements and usage alongside the row', () async {
      final snapshot = await repositoryFor(FakeBillingServer()).subscription();

      expect(snapshot.subscription.planCode, 'premium');
      expect(snapshot.subscription.status, SubscriptionStatus.active);
      expect(snapshot.entitlementPlan, 'premium');
      expect(snapshot.flags['ai'], isTrue);
      expect(snapshot.flags['sso'], isFalse);
      expect(snapshot.usage['accounts'], 4);

      // Only the counters with a cap are meterable; an unlimited one is not a
      // meter, it is a fact.
      expect(snapshot.meteredUsage.map((row) => row.key), ['members']);
      expect(snapshot.meteredUsage.single.limit, 1);
    });

    test('an unknown status never grants more than the plan code', () {
      final subscription = Subscription.fromJson(const {
        'id': 's1',
        'plan_code': 'premium',
        'effective_plan_code': 'free',
        'status': 'hibernating',
        'on_trial': false,
      });

      expect(subscription.status, SubscriptionStatus.active);
      expect(subscription.effectivePlanCode, 'free');
    });
  });

  group('what cancelling would do', () {
    Subscription build({
      required String status,
      String? renewsAt,
      String? trialEndsAt,
    }) =>
        Subscription.fromJson({
          'id': 's1',
          'plan_code': 'premium',
          'effective_plan_code': 'premium',
          'status': status,
          'on_trial': status == 'trialing',
          'trial_ends_at': trialEndsAt,
          'renews_at': renewsAt,
          'cancelled_at': null,
        });

    test('a paid period is honoured to its end', () {
      final outcome = build(
        status: 'active',
        renewsAt: '2026-08-24T09:00:00Z',
      ).cancellationOutcome(billingClock);

      expect(outcome.isImmediate, isFalse);
      expect(outcome.endsAt, DateTime.parse('2026-08-24T09:00:00Z').toLocal());
    });

    test('a trial has nothing paid for, so it ends at once', () {
      // Even with a trial end date in the future: `CancelSubscription` clears
      // the renewal date for a trial, and the effective plan drops the moment
      // the request returns.
      final outcome = build(
        status: 'trialing',
        trialEndsAt: '2026-08-08T12:00:00Z',
      ).cancellationOutcome(billingClock);

      expect(outcome.isImmediate, isTrue);
      expect(outcome.endsAt, isNull);
    });

    test('a period that has already run out ends at once', () {
      final outcome = build(
        status: 'active',
        renewsAt: '2026-07-01T09:00:00Z',
      ).cancellationOutcome(billingClock);

      expect(outcome.isImmediate, isTrue);
    });

    test('an active row with no renewal date ends at once', () {
      final outcome =
          build(status: 'active').cancellationOutcome(billingClock);

      expect(outcome.isImmediate, isTrue);
    });

    test('a cancelled row inside its period still has access', () async {
      final server = FakeBillingServer();
      final subscription = await repositoryFor(server).cancel();

      expect(subscription.status, SubscriptionStatus.cancelled);
      expect(subscription.isCancelled, isTrue);
      expect(subscription.renewsAt, isNotNull);
      expect(subscription.keepsAccessUntilPeriodEnd(billingClock), isTrue);
      // The server does not pretend the plan is gone, and neither does this.
      expect(subscription.effectivePlanCode, 'premium');
    });

    test('a cancelled trial keeps nothing', () async {
      final server = FakeBillingServer()
        ..status = 'trialing'
        ..onTrial = true
        ..trialEndsAt = '2026-08-08T12:00:00Z'
        ..renewsAt = null;

      final subscription = await repositoryFor(server).cancel();

      expect(subscription.keepsAccessUntilPeriodEnd(billingClock), isFalse);
      expect(subscription.effectivePlanCode, 'free');
    });
  });

  group('writes', () {
    test('subscribing posts the plan code and returns the new row', () async {
      final server = FakeBillingServer();

      final subscription = await repositoryFor(server).subscribe('family');

      expect(server.requests.single.path, '/billing/subscription');
      expect(server.requests.single.method, 'POST');
      expect((server.requests.single.data as Map)['plan_code'], 'family');
      expect(subscription.planCode, 'family');
      expect(subscription.renewsAt, isNotNull);
    });

    test('a trial is its own endpoint and can name a length', () async {
      final server = FakeBillingServer();

      final subscription =
          await repositoryFor(server).startTrial('premium', days: 7);

      expect(server.requests.single.path, '/billing/subscription/trial');
      expect((server.requests.single.data as Map)['days'], 7);
      expect(subscription.status, SubscriptionStatus.trialing);
      expect(subscription.hasUsedTrial, isTrue);
    });

    test('invoices keep a failed charge', () async {
      final server = FakeBillingServer()
        ..invoices = [
          invoiceJson(id: 'i1'),
          invoiceJson(id: 'i2', status: 'failed', paidAt: null),
        ];

      final invoices = await repositoryFor(server).invoices();

      expect(invoices, hasLength(2));
      expect(invoices.first.amount.minorUnits, 999);
      expect(invoices.first.status, InvoiceStatus.paid);
      expect(invoices.last.status, InvoiceStatus.failed);
      expect(invoices.last.paidAt, isNull);
      expect(invoices.first.periodEnd, isNotNull);
    });
  });

  group('failures', () {
    test('billing refusals resolve in the billing namespace', () async {
      final server = FakeBillingServer()..failWith = 'downgrade_blocked';

      await expectLater(
        repositoryFor(server).subscribe('free'),
        throwsA(
          isA<BillingFailure>()
              .having((e) => e.code, 'code', 'downgrade_blocked')
              .having(
                (e) => e.translationKey,
                'key',
                'billing.error.downgrade_blocked',
              ),
        ),
      );
    });

    test('shared codes keep the shared wording', () {
      const failure = BillingFailure('forbidden', statusCode: 403);

      expect(failure.translationKey, 'error.forbidden');
      expect(failure.isPermissionDenied, isTrue);
    });

    test('the no-backend code has billing wording of its own', () {
      const failure = BillingFailure(BillingFailure.noBackend);

      expect(failure.translationKey, 'billing.error.billing_unavailable');
    });
  });
}
