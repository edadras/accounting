import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../domain/entities.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';

/// The four frequencies `RecurringRule::FREQUENCIES` implements.
///
/// Deliberately a small subset of RRULE, because that is all the server can
/// actually compute: `occurrenceAfter()` adds an interval to the previous
/// occurrence and, for the monthly and yearly cases, clamps to a requested day
/// of the month.
enum RecurringFrequency {
  daily('daily'),
  weekly('weekly'),
  monthly('monthly'),
  yearly('yearly');

  const RecurringFrequency(this.wire);

  final String wire;

  static RecurringFrequency parse(String? raw) =>
      RecurringFrequency.values.firstWhere(
        (frequency) => frequency.wire == raw,
        orElse: () => RecurringFrequency.monthly,
      );

  /// Only these two pin an occurrence to a day of the month; a daily or weekly
  /// rule counts from the last run.
  bool get usesDayOfMonth =>
      this == RecurringFrequency.monthly || this == RecurringFrequency.yearly;
}

/// The transaction a rule posts each time it comes due.
///
/// The same shape `RecordTransaction` accepts, which is why the server stores
/// it as JSON rather than as columns. [amount] carries its currency, because
/// the wire keeps the integer and the code in two separate fields and pairing
/// them anywhere but here invites one of them being read without the other.
final class RecurringTemplate {
  const RecurringTemplate({
    required this.type,
    required this.accountId,
    required this.amount,
    this.counterAccountId,
    this.categoryId,
    this.description,
    this.payee,
  });

  final TransactionType type;
  final String accountId;
  final Money amount;
  final String? counterAccountId;
  final String? categoryId;
  final String? description;
  final String? payee;

  Map<String, Object?> toJson() => {
        'type': type.name,
        'account_id': accountId,
        if (counterAccountId != null && counterAccountId!.isNotEmpty)
          'counter_account_id': counterAccountId,
        if (categoryId != null && categoryId!.isNotEmpty)
          'category_id': categoryId,

        // Minor units and code, never a decimal string: the server validates
        // this as an integer and nothing here may hand it a rounded float.
        'amount': amount.minorUnits,
        'currency': amount.currency.code,
        if (description != null && description!.isNotEmpty)
          'description': description,
        if (payee != null && payee!.isNotEmpty) 'payee': payee,
      };

  static RecurringTemplate fromJson(Map<String, Object?> json) {
    final code = json['currency'] as String? ?? '';
    final currency = Currency.byCode(code) ??
        Currency(code: code.toUpperCase(), symbol: code, minorUnit: 2);

    return RecurringTemplate(
      type: TransactionType.values.firstWhere(
        (type) => type.name == json['type'],
        orElse: () => TransactionType.expense,
      ),
      accountId: json['account_id'] as String? ?? '',
      amount: Money(_intOf(json['amount']) ?? 0, currency),
      counterAccountId: json['counter_account_id'] as String?,
      categoryId: json['category_id'] as String?,
      description: json['description'] as String?,
      payee: json['payee'] as String?,
    );
  }
}

/// A standing instruction: the rent on the 5th, the salary on the 1st.
final class RecurringRule {
  const RecurringRule({
    required this.id,
    required this.template,
    required this.frequency,
    required this.startsAt,
    this.name,
    this.interval = 1,
    this.dayOfMonth,
    this.endsAt,
    this.nextRunAt,
    this.lastRunAt,
    this.autoPost = true,
    this.isPaused = false,
  });

  final String id;
  final String? name;
  final RecurringTemplate template;
  final RecurringFrequency frequency;
  final int interval;

  /// Clamped by the server to the length of the month — "the 31st" in February
  /// is the 28th, not a skipped month.
  final int? dayOfMonth;
  final DateTime startsAt;
  final DateTime? endsAt;

  /// Computed by the server and never by this app: the whole point of the
  /// column is that the poster can find due rules with an index lookup, and a
  /// locally guessed date would disagree with it the first time a month is
  /// short.
  final DateTime? nextRunAt;
  final DateTime? lastRunAt;

  /// False makes the rule a reminder: `scopeDue` skips it, so nothing is ever
  /// written to the books without the user confirming it.
  final bool autoPost;
  final bool isPaused;

  /// A rule with no next occurrence has run past its own end date. The server
  /// nulls the column rather than re-deriving an exhausted schedule nightly.
  bool get hasEnded => nextRunAt == null;

  bool get isRunning => !isPaused && !hasEnded;

  Map<String, Object?> toJson() => {
        if (id.isNotEmpty) 'id': id,
        'name': name,
        'template': template.toJson(),
        'frequency': frequency.wire,
        'interval': interval,
        if (frequency.usesDayOfMonth && dayOfMonth != null)
          'day_of_month': dayOfMonth,
        'starts_at': _dateOnly(startsAt),
        'ends_at': endsAt == null ? null : _dateOnly(endsAt!),
        'auto_post': autoPost,
        'is_paused': isPaused,
      };

  static RecurringRule fromJson(Map<String, Object?> json) => RecurringRule(
        id: json['id'] as String? ?? '',
        name: switch (json['name']) {
          final String value when value.trim().isNotEmpty => value,
          _ => null,
        },
        template: RecurringTemplate.fromJson(
          (json['template'] as Map?)?.cast<String, Object?>() ?? const {},
        ),
        frequency: RecurringFrequency.parse(json['frequency'] as String?),
        interval: _intOf(json['interval']) ?? 1,
        dayOfMonth: _intOf(json['day_of_month']),
        startsAt: _dateOf(json['starts_at']) ?? DateTime.now(),
        endsAt: _dateOf(json['ends_at']),
        nextRunAt: _dateOf(json['next_run_at']),
        lastRunAt: _dateOf(json['last_run_at']),
        autoPost: json['auto_post'] as bool? ?? true,
        isPaused: json['is_paused'] as bool? ?? false,
      );
}

/// Every refusal `RecurringException` (PHP) can raise, plus the transport
/// codes, reduced to a code the UI translates.
final class RecurringRuleException extends CodedFailure {
  const RecurringRuleException(super.code, {super.statusCode});

  static const ruleNotFound = 'recurring_rule_not_found';
  static const unknownFrequency = 'unknown_frequency';
  static const incompleteTemplate = 'incomplete_recurring_template';

  /// Raised before a request is attempted, when the build has no server behind
  /// it at all.
  static const noBackend = 'recurring_unavailable';
}

/// Standing instructions: what gets posted, how often, and when it next runs.
final class RecurringRepository {
  const RecurringRepository({required this.client});

  final ApiClient client;

  /// Ordered by the server on `next_run_at`, which is the order the list wants:
  /// what is about to happen, first.
  Future<List<RecurringRule>> rules() async {
    final response = await _guard(() => client.get('/recurring-rules'));

    return [
      for (final item in response['data'] as List? ?? const [])
        if (item is Map) RecurringRule.fromJson(item.cast<String, Object?>()),
    ];
  }

  Future<RecurringRule> create(RecurringRule rule) async {
    final response = await _guard(
      () => client.post('/recurring-rules', body: rule.toJson()),
    );

    return RecurringRule.fromJson(_data(response));
  }

  /// The four fields `RecurringRuleController::update()` validates, and no
  /// others.
  ///
  /// The schedule and the template are not among them: once a rule exists the
  /// server will not change what it posts or when, so this signature refuses to
  /// pretend otherwise. Sending `ends_at: null` clears the end date, which is
  /// why it is passed as a flag rather than inferred from a null argument.
  Future<RecurringRule> update(
    String id, {
    String? name,
    DateTime? endsAt,
    bool clearEndsAt = false,
    bool? autoPost,
    bool? isPaused,
  }) async {
    final response = await _guard(
      () => client.patch('/recurring-rules/$id', body: {
        if (name != null) 'name': name,
        if (clearEndsAt) 'ends_at': null else if (endsAt != null)
          'ends_at': _dateOnly(endsAt),
        if (autoPost != null) 'auto_post': autoPost,
        if (isPaused != null) 'is_paused': isPaused,
      },),
    );

    return RecurringRule.fromJson(_data(response));
  }

  Future<RecurringRule> setPaused(String id, {required bool paused}) =>
      update(id, isPaused: paused);

  Future<void> delete(String id) =>
      _guard(() => client.delete('/recurring-rules/$id'));

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw RecurringRuleException(error.code, statusCode: error.statusCode);
    }
  }

  static Map<String, Object?> _data(Map<String, Object?> response) =>
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {};
}

/// A schedule is a calendar day, not an instant: sending midnight local time as
/// a UTC timestamp moves half the world's rules a day. The server validates
/// `date`, so a bare date is what it gets.
String _dateOnly(DateTime date) => '${date.year.toString().padLeft(4, '0')}-'
    '${date.month.toString().padLeft(2, '0')}-'
    '${date.day.toString().padLeft(2, '0')}';

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;

int? _intOf(Object? raw) => switch (raw) {
      final int value => value,
      final double value => value.round(),
      final String value => int.tryParse(value.trim()),
      _ => null,
    };
