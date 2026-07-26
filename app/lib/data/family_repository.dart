import '../core/money/currency.dart';
import '../core/money/money.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

/// The three roles `FamilyMember::ROLES` (PHP) defines.
///
/// A parent pays, a child is given an allowance and a ceiling, and everyone
/// else is simply counted.
enum FamilyRole {
  parent,
  child,
  other;

  /// An unrecognised role from a newer server becomes `other`, which is the
  /// role that grants nothing.
  static FamilyRole parse(String? raw) => FamilyRole.values.firstWhere(
        (role) => role.name == raw,
        orElse: () => FamilyRole.other,
      );
}

/// One person in the household.
final class HouseholdMember {
  const HouseholdMember({
    required this.id,
    required this.displayName,
    required this.role,
    required this.currency,
    this.birthDate,
    this.monthlyAllowance,
    this.spendingCap,
    this.accountId,
    this.tag = '',
  });

  final String id;
  final String displayName;
  final FamilyRole role;

  /// The currency the member's own amounts are quoted in. Held separately from
  /// [monthlyAllowance] because a member may have no allowance at all and still
  /// need a currency for the one they are given later.
  final Currency currency;

  final DateTime? birthDate;

  /// Null when no allowance has been configured — which is not the same as an
  /// allowance of zero, and is the difference between "nothing to pay" and
  /// "pay nothing".
  final Money? monthlyAllowance;

  final Money? spendingCap;

  /// Where an allowance lands. Without one the server refuses to move money.
  final String? accountId;

  /// The transaction tag spending is attributed by, e.g. `member:01J…`.
  final String tag;

  bool get canBePaid => monthlyAllowance != null && accountId != null;

  static HouseholdMember fromJson(
    Map<String, Object?> json, {
    Currency? fallbackCurrency,
  }) {
    final currency = Currency.byCode(json['currency'] as String? ?? '') ??
        fallbackCurrency ??
        Currency.irr;

    return HouseholdMember(
      id: json['id'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      role: FamilyRole.parse(json['role'] as String?),
      currency: currency,
      birthDate: _dateOf(json['birth_date']),
      monthlyAllowance: MoneyCodec.tryDecode(
        json['monthly_allowance'],
        fallbackCurrency: currency,
      ),
      spendingCap: MoneyCodec.tryDecode(
        json['spending_cap'],
        fallbackCurrency: currency,
      ),
      accountId: json['account_id'] as String?,
      tag: json['tag'] as String? ?? '',
    );
  }
}

/// What one member spent in one month, against the ceiling they were given.
///
/// [cap], [remaining] and [usedPercent] are all null when the comparison cannot
/// be made — a cap quoted in a currency the books are not kept in cannot be
/// measured against base-currency spending without inventing a rate, and
/// `MemberSpending` (PHP) reports that as unavailable rather than as wrong.
final class MemberSpending {
  const MemberSpending({
    required this.memberId,
    required this.displayName,
    required this.role,
    required this.period,
    required this.spent,
    this.cap,
    this.remaining,
    this.isOverCap = false,
  });

  final String memberId;
  final String displayName;
  final FamilyRole role;
  final String period;
  final Money spent;
  final Money? cap;
  final Money? remaining;
  final bool isOverCap;

  bool get isComparable => cap != null && remaining != null;

  /// Integer percent of the cap consumed, or null when there is nothing to
  /// compare against. Derived from minor units; the server's floating-point
  /// `percentage` is ignored.
  int? get usedPercent {
    final ceiling = cap;
    if (ceiling == null) return null;
    if (ceiling.minorUnits <= 0) return spent.isPositive ? 100 : 0;
    return spent.minorUnits * 100 ~/ ceiling.minorUnits;
  }

  /// Meter geometry only — never an amount.
  double get meterFraction {
    final ceiling = cap;
    if (ceiling == null || ceiling.minorUnits <= 0) {
      return spent.isPositive ? 1 : 0;
    }
    return spent.minorUnits / ceiling.minorUnits;
  }

  static MemberSpending fromJson(
    Map<String, Object?> json, {
    Currency? fallbackCurrency,
  }) {
    final spent = MoneyCodec.decode(
      json['spent'],
      fallbackCurrency: fallbackCurrency,
    );

    return MemberSpending(
      memberId: json['member_id'] as String? ?? '',
      displayName: json['display_name'] as String? ?? '',
      role: FamilyRole.parse(json['role'] as String?),
      period: json['period'] as String? ?? '',
      spent: spent,
      cap: MoneyCodec.tryDecode(json['cap'], fallbackCurrency: spent.currency),
      remaining: MoneyCodec.tryDecode(
        json['remaining'],
        fallbackCurrency: spent.currency,
      ),
      isOverCap: json['is_over_cap'] as bool? ?? false,
    );
  }
}

/// The spending rows plus the window they were measured over.
final class SpendingReport {
  const SpendingReport({required this.period, required this.rows});

  final String period;
  final List<MemberSpending> rows;

  MemberSpending? forMember(String memberId) {
    for (final row in rows) {
      if (row.memberId == memberId) return row;
    }
    return null;
  }
}

/// The record that a month's allowance was paid, and the ledger transfer that
/// actually moved the money.
final class AllowancePayment {
  const AllowancePayment({
    required this.id,
    required this.memberId,
    required this.payerMemberId,
    required this.period,
    required this.amount,
    this.transactionId,
    this.paidAt,
  });

  final String id;
  final String memberId;
  final String payerMemberId;
  final String period;
  final Money amount;
  final String? transactionId;
  final DateTime? paidAt;

  static AllowancePayment fromJson(
    Map<String, Object?> json, {
    Currency? fallbackCurrency,
  }) =>
      AllowancePayment(
        id: json['id'] as String? ?? '',
        memberId: json['member_id'] as String? ?? '',
        payerMemberId: json['payer_member_id'] as String? ?? '',
        period: json['period'] as String? ?? '',
        amount: MoneyCodec.decode(
          json['amount'],
          fallbackCurrency: fallbackCurrency,
        ),
        transactionId: json['transaction_id'] as String?,
        paidAt: _dateOf(json['paid_at']),
      );
}

/// Every refusal `FamilyException` (PHP) can raise, plus the transport codes,
/// reduced to a code the UI translates.
final class FamilyFailure extends CodedFailure {
  const FamilyFailure(super.code, {super.statusCode});

  static const memberNotFound = 'family_member_not_found';
  static const invalidPeriod = 'invalid_period';
  static const allowanceAlreadyPaid = 'allowance_already_paid';
  static const noAllowanceConfigured = 'no_allowance_configured';
  static const nonPositiveAllowance = 'non_positive_allowance';
  static const memberHasNoAccount = 'member_has_no_account';
  static const payerIsRecipient = 'payer_is_recipient';
  static const currencyMismatch = 'currency_mismatch';

  /// Raised before a request is attempted, when the build has no server behind
  /// it at all.
  static const noBackend = 'family_unavailable';
}

/// The household: who is in it, what they spend, and what they are paid.
final class FamilyRepository {
  const FamilyRepository({required this.client, this.baseCurrency});

  final ApiClient client;
  final Currency? baseCurrency;

  Future<List<HouseholdMember>> members() async {
    final response = await _guard(() => client.get('/family/members'));
    return _listOf(
      response,
      (json) => HouseholdMember.fromJson(json, fallbackCurrency: baseCurrency),
    );
  }

  Future<HouseholdMember> member(String id) async {
    final response = await _guard(() => client.get('/family/members/$id'));
    return HouseholdMember.fromJson(
      _data(response),
      fallbackCurrency: baseCurrency,
    );
  }

  Future<HouseholdMember> addMember({
    required String displayName,
    required FamilyRole role,
    required Currency currency,
    Money? monthlyAllowance,
    Money? spendingCap,
    String? accountId,
    DateTime? birthDate,
  }) async {
    final response = await _guard(
      () => client.post('/family/members', body: {
        'display_name': displayName.trim(),
        'role': role.name,
        'currency': currency.code,
        'monthly_allowance': monthlyAllowance?.minorUnits,
        'spending_cap': spendingCap?.minorUnits,
        'account_id': accountId,
        'birth_date': birthDate?.toUtc().toIso8601String(),
      },),
    );

    return HouseholdMember.fromJson(
      _data(response),
      fallbackCurrency: currency,
    );
  }

  /// Only the fields the caller names are sent. A member's currency is not
  /// among them: `FamilyMemberController::update` does not accept one, because
  /// changing it would re-denominate an allowance that has already been paid.
  Future<HouseholdMember> updateMember(
    String id, {
    String? displayName,
    FamilyRole? role,
    Money? monthlyAllowance,
    Money? spendingCap,
    bool clearAllowance = false,
    bool clearCap = false,
    String? accountId,
  }) async {
    final response = await _guard(
      () => client.patch('/family/members/$id', body: {
        if (displayName != null) 'display_name': displayName.trim(),
        if (role != null) 'role': role.name,
        if (clearAllowance)
          'monthly_allowance': null
        else if (monthlyAllowance != null)
          'monthly_allowance': monthlyAllowance.minorUnits,
        if (clearCap)
          'spending_cap': null
        else if (spendingCap != null)
          'spending_cap': spendingCap.minorUnits,
        if (accountId != null) 'account_id': accountId,
      },),
    );

    return HouseholdMember.fromJson(
      _data(response),
      fallbackCurrency: baseCurrency,
    );
  }

  /// [period] is a `YYYY-MM` month; omitted, the server answers for the month
  /// containing now.
  Future<SpendingReport> spending({String? period, String? memberId}) async {
    final response = await _guard(
      () => client.get('/family/spending', query: {
        if (period != null) 'period': period,
        if (memberId != null) 'member_id': memberId,
      },),
    );

    final meta = (response['meta'] as Map?)?.cast<String, Object?>() ?? const {};

    return SpendingReport(
      period: meta['period'] as String? ?? period ?? '',
      rows: _listOf(
        response,
        (json) => MemberSpending.fromJson(json, fallbackCurrency: baseCurrency),
      ),
    );
  }

  Future<List<AllowancePayment>> allowances({
    String? period,
    String? memberId,
  }) async {
    final response = await _guard(
      () => client.get('/family/allowances', query: {
        if (period != null) 'period': period,
        if (memberId != null) 'member_id': memberId,
      },),
    );

    return _listOf(
      response,
      (json) => AllowancePayment.fromJson(json, fallbackCurrency: baseCurrency),
    );
  }

  /// Pays [memberId] for [period] out of [payerMemberId]'s account.
  ///
  /// [amount] is optional: omitted, the server pays the allowance the member is
  /// configured with, which is the case the UI uses. Sending an amount is for
  /// the month somebody was paid something other than the standing figure.
  Future<AllowancePayment> payAllowance({
    required String memberId,
    required String payerMemberId,
    required String period,
    Money? amount,
    DateTime? paidAt,
  }) async {
    final response = await _guard(
      () => client.post('/family/members/$memberId/allowance', body: {
        'payer_member_id': payerMemberId,
        'period': period,
        if (amount != null) ...{
          'amount': amount.minorUnits,
          'currency': amount.currency.code,
        },
        if (paidAt != null) 'paid_at': paidAt.toUtc().toIso8601String(),
      },),
    );

    return AllowancePayment.fromJson(
      _data(response),
      fallbackCurrency: amount?.currency ?? baseCurrency,
    );
  }

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw FamilyFailure(error.code, statusCode: error.statusCode);
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

/// `YYYY-MM` for a moment, which is the only period shape the family endpoints
/// accept.
String monthKey(DateTime moment) =>
    '${moment.year.toString().padLeft(4, '0')}-'
    '${moment.month.toString().padLeft(2, '0')}';

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
