import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../domain/analytics.dart' show BudgetPeriod;
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

/// The six things a budget can be drawn around, in the order
/// `Budget::SCOPES` (PHP) declares them.
enum BudgetScope {
  overall,
  category,
  project,
  trip,
  building,
  member;

  /// A scope this build has never heard of degrades to `overall` rather than
  /// throwing: the ceiling is still real, and refusing to render it would hide
  /// a budget the user is being measured against.
  static BudgetScope parse(String? raw) => BudgetScope.values.firstWhere(
        (scope) => scope.name == raw,
        orElse: () => BudgetScope.overall,
      );

  /// Everything except `overall` points at a row somewhere and therefore needs
  /// a `scope_id`; the server rejects the write otherwise.
  bool get needsTarget => this != BudgetScope.overall;
}

/// The two [BudgetPeriod] members whose window is a calendar unit, plus the one
/// that is defined by nothing but its two ends.
extension BudgetPeriodWire on BudgetPeriod {
  String get wireName => name;

  static BudgetPeriod parse(String? raw) => switch (raw) {
        'yearly' => BudgetPeriod.yearly,
        'custom' => BudgetPeriod.custom,
        _ => BudgetPeriod.monthly,
      };

  /// `custom` has no calendar to fall back on, so it is the only period that
  /// must carry an end date.
  bool get needsEndDate => this == BudgetPeriod.custom;
}

/// A budget as the server stores it: the ceiling the user set, untouched by
/// any carry-over.
final class BudgetPlan {
  const BudgetPlan({
    required this.id,
    required this.name,
    required this.scope,
    required this.period,
    required this.amount,
    required this.startsAt,
    this.scopeId,
    this.endsAt,
    this.rollover = false,
    this.alertThresholds = const [80, 100],
    this.version = 1,
  });

  final String id;
  final String name;
  final BudgetScope scope;
  final String? scopeId;
  final BudgetPeriod period;
  final Money amount;
  final DateTime startsAt;
  final DateTime? endsAt;
  final bool rollover;
  final List<int> alertThresholds;
  final int version;

  static BudgetPlan fromJson(
    Map<String, Object?> json, {
    Currency? fallbackCurrency,
  }) {
    return BudgetPlan(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      scope: BudgetScope.parse(json['scope'] as String?),
      scopeId: json['scope_id'] as String?,
      period: BudgetPeriodWire.parse(json['period'] as String?),
      amount: MoneyCodec.decode(
        json['amount'],
        fallbackCurrency: fallbackCurrency,
      ),
      startsAt: _dateOf(json['starts_at']) ?? DateTime.now(),
      endsAt: _dateOf(json['ends_at']),
      rollover: json['rollover'] as bool? ?? false,
      alertThresholds: _thresholds(json['alert_thresholds']),
      version: json['version'] as int? ?? 1,
    );
  }
}

/// One budget measured against the period containing "now".
///
/// Every figure here arrives as integer minor units and stays that way. The
/// server also sends a floating-point `percentage`, which this class ignores:
/// consumption is derived from [spent] and [effectiveAmount] with integer
/// arithmetic so that "exactly at the limit" is exactly at the limit.
final class BudgetStatus {
  const BudgetStatus({
    required this.id,
    required this.name,
    required this.scope,
    required this.period,
    required this.periodKey,
    required this.amount,
    required this.effectiveAmount,
    required this.spent,
    required this.remaining,
    this.scopeId,
    this.periodStart,
    this.periodEnd,
    this.rollover = false,
    this.alertThresholds = const [80, 100],
    this.thresholdsCrossed = const [],
  });

  final String id;
  final String name;
  final BudgetScope scope;
  final String? scopeId;
  final BudgetPeriod period;
  final String periodKey;
  final DateTime? periodStart;
  final DateTime? periodEnd;

  /// What the user set, in the workspace base currency.
  final Money amount;

  /// What may actually be spent this period — [amount] plus whatever last
  /// period left behind. `RolloverBudget` (PHP) keeps the two apart on purpose:
  /// a report has to be able to show the allowance and the carry-over as two
  /// facts rather than one number that quietly grew.
  final Money effectiveAmount;

  final Money spent;
  final Money remaining;
  final bool rollover;
  final List<int> alertThresholds;
  final List<int> thresholdsCrossed;

  /// The part of this period's ceiling that came from last period, or null when
  /// nothing was carried in. Never negative: an overspent period does not hand
  /// the next one a debt.
  Money? get carriedOver {
    if (effectiveAmount.currency != amount.currency) return null;
    final difference = effectiveAmount - amount;
    return difference.isPositive ? difference : null;
  }

  /// Integer percent of the ceiling consumed. 100 means exactly at the limit,
  /// never "close enough to it".
  int get usedPercent {
    final ceiling = effectiveAmount.minorUnits;
    if (ceiling <= 0) return spent.minorUnits > 0 ? 100 : 0;
    return spent.minorUnits * 100 ~/ ceiling;
  }

  /// Spent more than the ceiling allows. Derived from the integers rather than
  /// read from the server's `is_over_budget`, so it cannot disagree with the
  /// numbers printed beside it.
  bool get isOverLimit => remaining.isNegative;

  /// Spent the ceiling down to nothing and not a unit more. Distinct from
  /// [isOverLimit] because the two mean different things to the person reading
  /// the screen — and because only one of them is a breach.
  bool get isAtLimit => remaining.isZero && spent.isPositive;

  /// 80% is the first alert line the server ships as a default.
  bool get isNearLimit => !isOverLimit && !isAtLimit && usedPercent >= 80;

  /// For the meter's geometry only — never for an amount.
  double get meterFraction {
    final ceiling = effectiveAmount.minorUnits;
    if (ceiling <= 0) return spent.minorUnits > 0 ? 1 : 0;
    return spent.minorUnits / ceiling;
  }

  static BudgetStatus fromJson(
    Map<String, Object?> json, {
    Currency? fallbackCurrency,
  }) {
    final amount = MoneyCodec.decode(
      json['amount'],
      fallbackCurrency: fallbackCurrency,
    );

    Money money(String key) =>
        MoneyCodec.tryDecode(json[key], fallbackCurrency: amount.currency) ??
        Money(0, amount.currency);

    return BudgetStatus(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      scope: BudgetScope.parse(json['scope'] as String?),
      scopeId: json['scope_id'] as String?,
      period: BudgetPeriodWire.parse(json['period'] as String?),
      periodKey: json['period_key'] as String? ?? '',
      periodStart: _dateOf(json['period_start']),
      periodEnd: _dateOf(json['period_end']),
      amount: amount,
      effectiveAmount: money('effective_amount'),
      spent: money('spent'),
      remaining: money('remaining'),
      rollover: json['rollover'] as bool? ?? false,
      alertThresholds: _thresholds(json['alert_thresholds']),
      thresholdsCrossed: _thresholds(
        json['thresholds_crossed'],
        fallback: const [],
      ),
    );
  }
}

/// What the form hands the repository. Amounts are [Money], so nothing between
/// the keyboard and the wire ever sees a double.
final class BudgetDraft {
  const BudgetDraft({
    required this.name,
    required this.scope,
    required this.period,
    required this.amount,
    required this.startsAt,
    this.scopeId,
    this.endsAt,
    this.rollover = false,
    this.alertThresholds = const [80, 100],
  });

  final String name;
  final BudgetScope scope;
  final String? scopeId;
  final BudgetPeriod period;
  final Money amount;
  final DateTime startsAt;
  final DateTime? endsAt;
  final bool rollover;
  final List<int> alertThresholds;

  /// Rebuilds the draft an existing budget was made from, so the edit form
  /// opens on what is actually stored rather than on defaults.
  static BudgetDraft from(BudgetPlan plan) => BudgetDraft(
        name: plan.name,
        scope: plan.scope,
        scopeId: plan.scopeId,
        period: plan.period,
        amount: plan.amount,
        startsAt: plan.startsAt,
        endsAt: plan.endsAt,
        rollover: plan.rollover,
        alertThresholds: plan.alertThresholds,
      );

  BudgetDraft copyWith({
    String? name,
    BudgetScope? scope,
    Object? scopeId = _unset,
    BudgetPeriod? period,
    Money? amount,
    DateTime? startsAt,
    Object? endsAt = _unset,
    bool? rollover,
    List<int>? alertThresholds,
  }) =>
      BudgetDraft(
        name: name ?? this.name,
        scope: scope ?? this.scope,
        scopeId: scopeId == _unset ? this.scopeId : scopeId as String?,
        period: period ?? this.period,
        amount: amount ?? this.amount,
        startsAt: startsAt ?? this.startsAt,
        endsAt: endsAt == _unset ? this.endsAt : endsAt as DateTime?,
        rollover: rollover ?? this.rollover,
        alertThresholds: alertThresholds ?? this.alertThresholds,
      );

  Map<String, Object?> toJson() => {
        ..._sharedJson(),
        'amount': amount.minorUnits,
        'currency': amount.currency.code,
      };

  /// The same fields minus `currency`.
  ///
  /// `UpdateBudgetRequest` rejects a currency outright: spend is measured in the
  /// budget's own currency, so re-denominating an existing one would reinterpret
  /// every figure already recorded against it. Sending it "unchanged" would be
  /// a 422 for no reason.
  Map<String, Object?> toUpdateJson() => {
        ..._sharedJson(),
        'amount': amount.minorUnits,
      };

  Map<String, Object?> _sharedJson() => {
        'name': name.trim(),
        'scope': scope.name,
        // `overall` covers the whole ledger and must not carry a target; the
        // server nulls it anyway, and sending one would only be a lie on the
        // wire.
        'scope_id': scope.needsTarget ? scopeId : null,
        'period': period.wireName,
        'starts_at': startsAt.toUtc().toIso8601String(),
        'ends_at': period.needsEndDate ? endsAt?.toUtc().toIso8601String() : null,
        'rollover': rollover,
        'alert_thresholds': alertThresholds,
      };

  static const _unset = Object();
}

/// Every refusal the budget endpoints can produce, reduced to a code the UI
/// translates. The server's English prose is never carried.
final class BudgetException extends CodedFailure {
  const BudgetException(super.code, {super.statusCode});

  /// Raised before a request is attempted, when the build has no server behind
  /// it at all.
  static const noBackend = 'budgets_unavailable';
}

/// Budgets: the ceilings themselves, and what they have consumed.
final class BudgetRepository {
  const BudgetRepository({required this.client, this.baseCurrency});

  final ApiClient client;

  /// Used only when a payload omits the currency, which the documented shape
  /// never does.
  final Currency? baseCurrency;

  Future<List<BudgetPlan>> plans() async {
    final response = await _guard(() => client.get('/budgets'));
    return _listOf(
      response,
      (json) => BudgetPlan.fromJson(json, fallbackCurrency: baseCurrency),
    );
  }

  Future<BudgetPlan> plan(String id) async {
    final response = await _guard(() => client.get('/budgets/$id'));
    return BudgetPlan.fromJson(_data(response), fallbackCurrency: baseCurrency);
  }

  /// Live consumption for every budget. `at` is only ever sent by tests and by
  /// a future period picker; omitted it means "the period containing now".
  Future<List<BudgetStatus>> status({DateTime? at}) async {
    final response = await _guard(
      () => client.get(
        '/budgets/status',
        query: at == null ? null : {'at': at.toUtc().toIso8601String()},
      ),
    );

    return _listOf(
      response,
      (json) => BudgetStatus.fromJson(json, fallbackCurrency: baseCurrency),
    );
  }

  Future<BudgetPlan> create(BudgetDraft draft) async {
    final response = await _guard(
      () => client.post('/budgets', body: draft.toJson()),
    );
    return BudgetPlan.fromJson(_data(response), fallbackCurrency: baseCurrency);
  }

  /// Edits a budget in place, keeping its id.
  ///
  /// The id surviving is the point: `budget_usages` hangs off it, and every
  /// period already measured against this budget goes on meaning the same
  /// thing after the edit.
  Future<BudgetPlan> update({
    required String id,
    required BudgetDraft draft,
  }) async {
    final response = await _guard(
      () => client.patch('/budgets/$id', body: draft.toUpdateJson()),
    );
    return BudgetPlan.fromJson(_data(response), fallbackCurrency: baseCurrency);
  }

  Future<void> delete(String id) => _guard(() => client.delete('/budgets/$id'));

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw BudgetException(error.code, statusCode: error.statusCode);
    }
  }

  static Map<String, Object?> _data(Map<String, Object?> response) =>
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {};

  static List<T> _listOf<T>(
    Map<String, Object?> response,
    T Function(Map<String, Object?>) parse,
  ) =>
      [
        for (final item in response['data'] as List? ?? const [])
          if (item is Map) parse(item.cast<String, Object?>()),
      ];
}

List<int> _thresholds(Object? raw, {List<int> fallback = const [80, 100]}) {
  if (raw is! List) return fallback;
  final parsed = <int>[
    for (final value in raw)
      if (value is int) value else if (value is String) int.tryParse(value) ?? 0,
  ]..removeWhere((value) => value <= 0);

  return parsed.isEmpty ? fallback : parsed;
}

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
