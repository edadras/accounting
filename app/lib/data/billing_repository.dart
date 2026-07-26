import '../core/money/currency.dart';
import '../core/money/money.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

/// `Subscription::STATUSES`.
enum SubscriptionStatus {
  trialing('trialing'),
  active('active'),
  pastDue('past_due'),
  cancelled('cancelled');

  const SubscriptionStatus(this.wire);

  final String wire;

  /// An unknown status from a newer server reads as `active`, which is what the
  /// server's own `effectivePlanCode` falls back to for anything it does not
  /// recognise — and it never grants more than the plan code already says.
  static SubscriptionStatus parse(String? raw) {
    for (final status in SubscriptionStatus.values) {
      if (status.wire == raw) return status;
    }
    return SubscriptionStatus.active;
  }

  String get labelKey => 'billing.status.$name';
}

enum PlanInterval {
  monthly,
  yearly;

  static PlanInterval parse(String? raw) =>
      raw == 'yearly' ? PlanInterval.yearly : PlanInterval.monthly;

  String get labelKey => 'billing.interval.$name';
}

/// A row of the published catalogue.
final class BillingPlan {
  const BillingPlan({
    required this.code,
    required this.name,
    required this.price,
    required this.interval,
    this.limits = const {},
    this.flags = const {},
  });

  final String code;

  /// The catalogue's own English name (`Free`, `Premium`). Shown only when the
  /// app has no translation for the code — see `billing.plan.<code>`.
  final String name;

  /// Integer minor units, like every other amount in the system.
  final Money price;

  final PlanInterval interval;

  /// Countable caps; a null value means unlimited, and a missing key means the
  /// plan says nothing about that counter.
  final Map<String, int?> limits;

  final Map<String, bool> flags;

  bool get isFree => price.minorUnits == 0;

  /// The feature keys this plan switches on, in the catalogue's own order.
  List<String> get includedFeatures => [
        for (final entry in flags.entries)
          if (entry.value) entry.key,
      ];

  /// What [other] has that this plan does not — the honest answer to "what
  /// stops working if I move down to it".
  List<String> featuresLostAgainst(BillingPlan other) => [
        for (final feature in other.includedFeatures)
          if (flags[feature] != true) feature,
      ];

  static BillingPlan fromJson(Map<String, Object?> json) {
    final features = (json['features'] as Map?)?.cast<String, Object?>();

    return BillingPlan(
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      price: _money(json['price']),
      interval: PlanInterval.parse(json['interval'] as String?),
      limits: _limits(features?['limits']),
      flags: _flags(features?['flags']),
    );
  }
}

/// What cancelling would actually do, and when.
///
/// Mirrors `CancelSubscription` together with `Subscription::effectivePlanCode`
/// rather than guessing: a trial is cut off at once because nothing was paid
/// for, and a paid period is honoured to its end. The confirmation screen is
/// built from this, so it can never promise a date the server will not keep.
final class CancellationOutcome {
  const CancellationOutcome({required this.endsAt});

  /// When paid access stops. Null means it stops the moment the button is
  /// pressed.
  final DateTime? endsAt;

  bool get isImmediate => endsAt == null;
}

final class Subscription {
  const Subscription({
    required this.id,
    required this.planCode,
    required this.effectivePlanCode,
    required this.status,
    required this.onTrial,
    this.trialEndsAt,
    this.renewsAt,
    this.cancelledAt,
  });

  final String id;

  /// The plan the row names.
  final String planCode;

  /// The plan in force today, which is not always [planCode] — an expired
  /// trial or a lapsed period resolves to the default plan without anything
  /// having to run.
  final String effectivePlanCode;

  final SubscriptionStatus status;
  final bool onTrial;
  final DateTime? trialEndsAt;
  final DateTime? renewsAt;
  final DateTime? cancelledAt;

  bool get isCancelled => status == SubscriptionStatus.cancelled;

  /// The server refuses a second trial once `trial_ends_at` has ever been set,
  /// so the button is not offered rather than offered and refused.
  bool get hasUsedTrial => trialEndsAt != null;

  /// True while a cancelled subscription is still inside the period that was
  /// paid for. This is the state a UI must not describe as "downgraded".
  bool keepsAccessUntilPeriodEnd(DateTime now) =>
      isCancelled && renewsAt != null && renewsAt!.isAfter(now);

  CancellationOutcome cancellationOutcome(DateTime now) {
    // A trial has no paid period to honour: the action clears `renews_at`, and
    // the effective plan drops to the default one immediately.
    if (status == SubscriptionStatus.trialing) {
      return const CancellationOutcome(endsAt: null);
    }

    final renews = renewsAt;
    if (renews == null || !renews.isAfter(now)) {
      return const CancellationOutcome(endsAt: null);
    }

    return CancellationOutcome(endsAt: renews);
  }

  static Subscription fromJson(Map<String, Object?> json) {
    final planCode = json['plan_code'] as String? ?? '';

    return Subscription(
      id: json['id'] as String? ?? '',
      planCode: planCode,
      effectivePlanCode: json['effective_plan_code'] as String? ?? planCode,
      status: SubscriptionStatus.parse(json['status'] as String?),
      onTrial: json['on_trial'] as bool? ?? false,
      trialEndsAt: _dateOf(json['trial_ends_at']),
      renewsAt: _dateOf(json['renews_at']),
      cancelledAt: _dateOf(json['cancelled_at']),
    );
  }
}

/// `GET /billing/subscription` — the row plus the two things the client would
/// otherwise have to recompute: what the plan allows, and how much is used.
final class BillingSnapshot {
  const BillingSnapshot({
    required this.subscription,
    required this.entitlementPlan,
    this.flags = const {},
    this.limits = const {},
    this.usage = const {},
  });

  final Subscription subscription;

  /// The plan the entitlements were resolved from — the effective one.
  final String entitlementPlan;

  final Map<String, bool> flags;

  /// Null means unlimited.
  final Map<String, int?> limits;

  final Map<String, int> usage;

  /// The counters that have a cap, paired with what is already in use. Keys
  /// with no cap are left out: an unlimited counter is not a meter.
  List<({String key, int used, int limit})> get meteredUsage => [
        for (final entry in usage.entries)
          if (limits[entry.key] != null)
            (key: entry.key, used: entry.value, limit: limits[entry.key]!),
      ];

  static BillingSnapshot fromJson(Map<String, Object?> response) {
    final entitlements =
        (response['entitlements'] as Map?)?.cast<String, Object?>() ?? const {};

    final subscription = Subscription.fromJson(
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {},
    );

    return BillingSnapshot(
      subscription: subscription,
      entitlementPlan: entitlements['plan'] as String? ??
          subscription.effectivePlanCode,
      flags: _flags(entitlements['flags']),
      limits: _limits(entitlements['limits']),
      usage: _usage(response['usage']),
    );
  }
}

enum InvoiceStatus {
  pending,
  paid,
  failed,
  refunded;

  static InvoiceStatus parse(String? raw) {
    for (final status in InvoiceStatus.values) {
      if (status.name == raw) return status;
    }
    return InvoiceStatus.pending;
  }

  String get labelKey => 'billing.invoiceStatus.$name';
}

final class BillingInvoice {
  const BillingInvoice({
    required this.id,
    required this.number,
    required this.planCode,
    required this.amount,
    required this.status,
    this.periodStart,
    this.periodEnd,
    this.issuedAt,
    this.paidAt,
  });

  final String id;
  final String number;
  final String planCode;
  final Money amount;
  final InvoiceStatus status;
  final DateTime? periodStart;
  final DateTime? periodEnd;
  final DateTime? issuedAt;
  final DateTime? paidAt;

  static BillingInvoice fromJson(Map<String, Object?> json) => BillingInvoice(
        id: json['id'] as String? ?? '',
        number: json['number'] as String? ?? '',
        planCode: json['plan_code'] as String? ?? '',
        amount: _money(json['amount']),
        status: InvoiceStatus.parse(json['status'] as String?),
        periodStart: _dateOf(json['period_start']),
        periodEnd: _dateOf(json['period_end']),
        issuedAt: _dateOf(json['issued_at']),
        paidAt: _dateOf(json['paid_at']),
      );
}

/// Every refusal `BillingException` (PHP) can raise, reduced to its code.
final class BillingFailure extends CodedFailure {
  const BillingFailure(super.code, {super.statusCode});

  static const unknownPlan = 'unknown_plan';
  static const alreadyOnPlan = 'already_on_plan';
  static const trialAlreadyUsed = 'trial_already_used';
  static const downgradeBlocked = 'downgrade_blocked';
  static const paymentFailed = 'payment_failed';
  static const subscriptionNotFound = 'subscription_not_found';

  /// No server behind this build, so there is nothing to bill.
  static const noBackend = 'billing_unavailable';

  static const _own = {
    unknownPlan,
    alreadyOnPlan,
    trialAlreadyUsed,
    downgradeBlocked,
    paymentFailed,
    subscriptionNotFound,
    noBackend,
  };

  /// Billing writes its own wording for the refusals it invents, and shares the
  /// `error.` namespace for the transport and permission codes everything else
  /// already has copy for. Either way the server's English `message` is never
  /// what reaches the screen.
  @override
  String get translationKey =>
      _own.contains(code) ? 'billing.error.$code' : 'error.$code';
}

/// Plans, the current subscription and the invoice history.
///
/// Every write returns the subscription the server ended up with, so a screen
/// can render the real outcome instead of the one it hoped for — which is the
/// whole point on cancel, where the server keeps the plan alive until the paid
/// period ends and a client that assumed otherwise would lie about the date.
final class BillingRepository {
  const BillingRepository({required this.client});

  final ApiClient client;

  Future<List<BillingPlan>> plans() async {
    final response = await _guard(() => client.get('/billing/plans'));

    return [
      for (final item in response['data'] as List? ?? const [])
        if (item is Map) BillingPlan.fromJson(item.cast<String, Object?>()),
    ];
  }

  Future<BillingSnapshot> subscription() async {
    final response = await _guard(() => client.get('/billing/subscription'));
    return BillingSnapshot.fromJson(response);
  }

  /// Charges and switches at once — `ChangePlan` settles the invoice before it
  /// saves, in both directions. Nothing about this is deferred.
  Future<Subscription> subscribe(String planCode) async {
    final response = await _guard(
      () => client.post('/billing/subscription', body: {'plan_code': planCode}),
    );

    return Subscription.fromJson(_data(response));
  }

  Future<Subscription> startTrial(String planCode, {int? days}) async {
    final response = await _guard(
      () => client.post('/billing/subscription/trial', body: {
        'plan_code': planCode,
        if (days != null) 'days': days,
      },),
    );

    return Subscription.fromJson(_data(response));
  }

  /// Ends the subscription without taking anything away today. The returned row
  /// still carries `renews_at`, which is the date access actually stops.
  Future<Subscription> cancel() async {
    final response =
        await _guard(() => client.post('/billing/subscription/cancel'));

    return Subscription.fromJson(_data(response));
  }

  Future<List<BillingInvoice>> invoices() async {
    final response = await _guard(() => client.get('/billing/invoices'));

    return [
      for (final item in response['data'] as List? ?? const [])
        if (item is Map) BillingInvoice.fromJson(item.cast<String, Object?>()),
    ];
  }

  static Map<String, Object?> _data(Map<String, Object?> response) =>
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {};

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw BillingFailure(error.code, statusCode: error.statusCode);
    }
  }
}

/// Billing serialises `Money` through its own `jsonSerialize`, which names the
/// integer `amount` where the ledger's envelope names it `value`. Both are the
/// same thing — a count of minor units — so the key is renamed here rather than
/// teaching [MoneyCodec] a second spelling.
Money _money(Object? raw) {
  if (raw is! Map) return _zero;

  final json = raw.cast<String, Object?>();

  return MoneyCodec.tryDecode({
        'value': json['value'] ?? json['amount'],
        'currency': json['currency'],
        'minor_unit': json['minor_unit'],
      }) ??
      _zero;
}

Map<String, bool> _flags(Object? raw) => raw is! Map
    ? const {}
    : {
        for (final entry in raw.entries)
          '${entry.key}': entry.value == true,
      };

Map<String, int?> _limits(Object? raw) => raw is! Map
    ? const {}
    : {
        for (final entry in raw.entries)
          '${entry.key}': entry.value is int ? entry.value as int : null,
      };

Map<String, int> _usage(Object? raw) => raw is! Map
    ? const {}
    : {
        for (final entry in raw.entries)
          if (entry.value is int) '${entry.key}': entry.value as int,
      };

const _zero = Money(0, Currency.usd);

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
