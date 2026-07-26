import 'package:dio/dio.dart';

import '../core/money/money.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

/// The six channels `ChannelKeys::ALL` names, in the order the server lists
/// them.
///
/// [database] is first because it is the alert row itself: the backend adds it
/// back to every rule and refuses to let a preference switch it off, since
/// muting it would only hide an alert from the app that displays it rather than
/// stop it being raised.
enum AlertChannel {
  database('database'),
  push('push'),
  email('email'),
  sms('sms'),
  telegram('telegram'),
  whatsapp('whatsapp');

  const AlertChannel(this.wire);

  final String wire;

  /// Unknown channel names from a newer server are dropped rather than guessed
  /// at — a chip nobody can label is worse than one fewer chip.
  static AlertChannel? byWire(String? raw) {
    for (final channel in AlertChannel.values) {
      if (channel.wire == raw) return channel;
    }
    return null;
  }

  bool get isMandatory => this == AlertChannel.database;
}

/// The four kinds `AlertRule::TYPES` implements. There is a scanner behind each
/// one and nothing behind anything else, so the editor offers exactly these.
enum AlertRuleType {
  checkDue('check_due'),
  installmentDue('installment_due'),
  budgetThreshold('budget_threshold'),
  lowBalance('low_balance');

  const AlertRuleType(this.wire);

  final String wire;

  static AlertRuleType? byWire(String? raw) {
    for (final type in AlertRuleType.values) {
      if (type.wire == raw) return type;
    }
    return null;
  }

  /// Only the two due-date scanners read `lead_days`; a balance floor and a
  /// budget threshold are about now, not about a horizon.
  bool get usesLeadDays =>
      this == AlertRuleType.checkDue || this == AlertRuleType.installmentDue;

  bool get usesThreshold => this == AlertRuleType.lowBalance;
}

/// Both `Alert::STATUSES` and the per-channel delivery record use these three
/// words, so they share one enum.
enum AlertDelivery {
  pending,
  sent,
  failed;

  static AlertDelivery parse(String? raw) => AlertDelivery.values.firstWhere(
        (status) => status.name == raw,
        orElse: () => AlertDelivery.pending,
      );
}

/// One alert in one person's inbox.
///
/// [payload] is left as the raw map the scanner wrote: its keys differ per
/// type, and flattening four unrelated shapes into one class would invent
/// fields the server never sends. The typed accessors below are the only way
/// the UI reads it, so a missing or malformed key degrades to null instead of
/// throwing halfway through a list build.
final class AlertItem {
  const AlertItem({
    required this.id,
    required this.rawType,
    required this.scheduledAt,
    this.type,
    this.payload = const {},
    this.channels = const {},
    this.status = AlertDelivery.pending,
    this.sentAt,
    this.readAt,
  });

  final String id;

  /// The server's own string, kept so an alert of a kind this build predates
  /// can still be listed instead of vanishing.
  final String rawType;
  final AlertRuleType? type;
  final Map<String, Object?> payload;

  /// channel → delivery outcome, e.g. `{database: sent, push: failed}`.
  final Map<AlertChannel, AlertDelivery> channels;
  final AlertDelivery status;
  final DateTime scheduledAt;
  final DateTime? sentAt;
  final DateTime? readAt;

  bool get isUnread => readAt == null;

  /// Raised, but held back — the member's quiet hours pushed it into the
  /// future, so it is in the inbox before it has been delivered anywhere.
  bool get isDeferred =>
      status == AlertDelivery.pending && scheduledAt.isAfter(DateTime.now());

  /// Money inside the payload. The scanners embed `App\Core\Money\Money`
  /// directly, which serialises its integer under `amount` rather than the
  /// `value` the REST envelope uses, so both spellings are accepted. Neither is
  /// ever parsed out of the `decimal` display string.
  Money? money(String key) {
    final raw = payload[key];
    if (raw is! Map) return null;

    final map = raw.cast<String, Object?>();
    return MoneyCodec.tryDecode(
      map.containsKey('value') ? map : {...map, 'value': map['amount']},
    );
  }

  String? text(String key) {
    final raw = payload[key];
    return raw is String && raw.isNotEmpty ? raw : null;
  }

  /// `days_ahead` arrives from Carbon's `diffInDays`, which is a float in
  /// Carbon 3 — so a whole number may land as `3` or as `3.0`.
  int? integer(String key) => switch (payload[key]) {
        final int value => value,
        final double value => value.round(),
        final String value => int.tryParse(value.trim()),
        _ => null,
      };

  /// The scanners write due dates as bare `Y-m-d`, which parses as local
  /// midnight — which is what a due date means to the person reading it.
  DateTime? date(String key) {
    final raw = payload[key];
    return raw is String ? DateTime.tryParse(raw) : null;
  }

  static AlertItem fromJson(Map<String, Object?> json) {
    final rawType = json['type'] as String? ?? '';

    return AlertItem(
      id: json['id'] as String? ?? '',
      rawType: rawType,
      type: AlertRuleType.byWire(rawType),
      payload: (json['payload'] as Map?)?.cast<String, Object?>() ?? const {},
      channels: _deliveries(json['channels']),
      status: AlertDelivery.parse(json['status'] as String?),
      scheduledAt: _dateOf(json['scheduled_at']) ?? DateTime.now(),
      sentAt: _dateOf(json['sent_at']),
      readAt: _dateOf(json['read_at']),
    );
  }

  static Map<AlertChannel, AlertDelivery> _deliveries(Object? raw) {
    if (raw is! Map) return const {};

    final parsed = <AlertChannel, AlertDelivery>{};
    raw.forEach((key, value) {
      final channel = AlertChannel.byWire('$key');
      if (channel != null) {
        parsed[channel] = AlertDelivery.parse('$value');
      }
    });
    return parsed;
  }
}

/// What a workspace wants to be told about, on which channels, how early.
final class AlertRule {
  const AlertRule({
    required this.id,
    required this.type,
    this.config = const {},
    this.channels = const [AlertChannel.database],
    this.leadDays = 3,
    this.isActive = true,
  });

  final String id;
  final AlertRuleType type;
  final Map<String, Object?> config;
  final List<AlertChannel> channels;
  final int leadDays;
  final bool isActive;

  /// The floor a `low_balance` rule compares against, in minor units of each
  /// account's own currency. Absent for every other kind.
  int? get threshold => switch (config['threshold']) {
        final int value => value,
        final String value => int.tryParse(value.trim()),
        _ => null,
      };

  AlertRule copyWith({
    AlertRuleType? type,
    Map<String, Object?>? config,
    List<AlertChannel>? channels,
    int? leadDays,
    bool? isActive,
  }) =>
      AlertRule(
        id: id,
        type: type ?? this.type,
        config: config ?? this.config,
        channels: channels ?? this.channels,
        leadDays: leadDays ?? this.leadDays,
        isActive: isActive ?? this.isActive,
      );

  Map<String, Object?> toJson() => {
        if (id.isNotEmpty) 'id': id,
        'type': type.wire,
        'config': config,
        'channels': [for (final channel in channels) channel.wire],
        'lead_days': leadDays,
        'is_active': isActive,
      };

  static AlertRule fromJson(Map<String, Object?> json) {
    final channels = <AlertChannel>[];

    for (final raw in json['channels'] as List? ?? const []) {
      final channel = AlertChannel.byWire('$raw');
      if (channel != null) channels.add(channel);
    }

    return AlertRule(
      id: json['id'] as String? ?? '',
      type: AlertRuleType.byWire(json['type'] as String?) ??
          AlertRuleType.checkDue,
      config: (json['config'] as Map?)?.cast<String, Object?>() ?? const {},
      channels: channels.isEmpty ? const [AlertChannel.database] : channels,
      leadDays: switch (json['lead_days']) {
        final int value => value,
        _ => 3,
      },
      isActive: json['is_active'] as bool? ?? true,
    );
  }
}

/// One member's control over how and when this workspace may reach them.
final class AlertPreferences {
  const AlertPreferences({
    this.channels = const {},
    this.quietHoursStart,
    this.quietHoursEnd,
    this.timezone,
  });

  /// Only the channels the member has spoken about. An absent one is on, which
  /// is why this is not filled in with defaults on the way in.
  final Map<AlertChannel, bool> channels;

  /// Local wall-clock `HH:MM` in [timezone], not an instant.
  final String? quietHoursStart;
  final String? quietHoursEnd;
  final String? timezone;

  bool enables(AlertChannel channel) =>
      channel.isMandatory || (channels[channel] ?? true);

  /// A window with equal ends describes zero minutes, which the server treats
  /// as no quiet hours at all rather than as deferring forever.
  bool get hasQuietHours =>
      quietHoursStart != null &&
      quietHoursEnd != null &&
      quietHoursStart != quietHoursEnd;

  AlertPreferences withChannel(AlertChannel channel, {required bool enabled}) =>
      AlertPreferences(
        channels: {...channels, channel: enabled},
        quietHoursStart: quietHoursStart,
        quietHoursEnd: quietHoursEnd,
        timezone: timezone,
      );

  /// Both ends move together — a half-open window is not a state the server
  /// can act on, so it is not a state this object can hold.
  AlertPreferences withQuietHours(String? start, String? end) =>
      AlertPreferences(
        channels: channels,
        quietHoursStart: start,
        quietHoursEnd: end,
        timezone: timezone,
      );

  AlertPreferences withTimezone(String? zone) => AlertPreferences(
        channels: channels,
        quietHoursStart: quietHoursStart,
        quietHoursEnd: quietHoursEnd,
        timezone: zone,
      );

  Map<String, Object?> toJson() => {
        'channels': {
          for (final channel in AlertChannel.values)
            if (!channel.isMandatory) channel.wire: enables(channel),
        },
        'quiet_hours_start': quietHoursStart,
        'quiet_hours_end': quietHoursEnd,
        'timezone': timezone,
      };

  static AlertPreferences fromJson(Map<String, Object?> json) {
    final channels = <AlertChannel, bool>{};
    final raw = json['channels'];

    if (raw is Map) {
      raw.forEach((key, value) {
        final channel = AlertChannel.byWire('$key');
        if (channel != null) channels[channel] = value == true;
      });
    }

    return AlertPreferences(
      channels: channels,
      quietHoursStart: _time(json['quiet_hours_start']),
      quietHoursEnd: _time(json['quiet_hours_end']),
      timezone: switch (json['timezone']) {
        final String zone when zone.isNotEmpty => zone,
        _ => null,
      },
    );
  }

  static String? _time(Object? raw) =>
      raw is String && RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(raw)
          ? raw
          : null;
}

/// Every refusal `AlertException` (PHP) can raise, plus the transport codes,
/// reduced to a code the UI translates.
final class AlertsException extends CodedFailure {
  const AlertsException(super.code, {super.statusCode});

  static const alertNotFound = 'alert_not_found';
  static const ruleNotFound = 'alert_rule_not_found';
  static const unknownRuleType = 'unknown_rule_type';
  static const unknownChannel = 'unknown_channel';
  static const invalidQuietWindow = 'invalid_quiet_window';

  /// Raised before a request is attempted, when the build has no server behind
  /// it at all.
  static const noBackend = 'alerts_unavailable';
}

/// Alerts: the inbox, what raises them, and how they are allowed to reach you.
///
/// No cache. An inbox served from a stale copy would keep showing an alert the
/// user read on another device, and "already dealt with" is the one thing an
/// alert list has to get right.
final class AlertsRepository {
  const AlertsRepository({required this.client});

  final ApiClient client;

  Future<List<AlertItem>> inbox({
    bool unreadOnly = false,
    AlertRuleType? type,
  }) async {
    final response = await _guard(
      () => client.get('/alerts', query: {
        if (unreadOnly) 'unread': true,
        if (type != null) 'type': type.wire,
      },),
    );

    return _listOf(response, AlertItem.fromJson);
  }

  Future<AlertItem> markRead(String id) async {
    final response = await _guard(() => client.post('/alerts/$id/read'));
    return AlertItem.fromJson(_data(response));
  }

  Future<List<AlertRule>> rules() async {
    final response = await _guard(() => client.get('/alerts/rules'));
    return _listOf(response, AlertRule.fromJson);
  }

  Future<AlertRule> createRule(AlertRule rule) async {
    final response = await _guard(
      () => client.post('/alerts/rules', body: rule.toJson()),
    );

    return AlertRule.fromJson(_data(response));
  }

  Future<void> deleteRule(String id) =>
      _guard(() => client.delete('/alerts/rules/$id'));

  /// One request, one outcome: the rule is either edited or untouched.
  ///
  /// Every field is optional, so an omitted one keeps its stored value. [type]
  /// is not among them and never will be — it decides which scanner reads the
  /// rule and therefore what [AlertRule.config] means, so the server answers a
  /// changed type with 422 rather than reinterpreting the config it already
  /// holds.
  Future<AlertRule> updateRule(
    String id, {
    Map<String, Object?>? config,
    List<AlertChannel>? channels,
    int? leadDays,
    bool? isActive,
  }) async {
    final response = await _guard(
      () => client.patch('/alerts/rules/$id', body: {
        if (config != null) 'config': config,
        if (channels != null)
          'channels': [for (final channel in channels) channel.wire],
        if (leadDays != null) 'lead_days': leadDays,
        if (isActive != null) 'is_active': isActive,
      },),
    );

    return AlertRule.fromJson(_data(response));
  }

  /// Pausing a rule stops it firing without losing what it watches, so it is
  /// the one flag flipped on its own rather than through the editor.
  Future<AlertRule> setRuleActive(String id, {required bool active}) =>
      updateRule(id, isActive: active);

  Future<AlertPreferences> preferences() async {
    final response = await _guard(() => client.get('/alerts/preferences'));
    return AlertPreferences.fromJson(_data(response));
  }

  Future<AlertPreferences> savePreferences(
    AlertPreferences preferences,
  ) async {
    final response = await _guard(() => _put(
          '/alerts/preferences',
          preferences.toJson(),
        ),);

    return AlertPreferences.fromJson(_data(response));
  }

  /// `PUT` is the one verb [ApiClient] does not wrap, and this is the only
  /// endpoint in the app that needs it. Issued on the same Dio instance so it
  /// still passes through the session, error-mapping and retry interceptors —
  /// the failure envelope is parsed in exactly one place either way.
  Future<Map<String, Object?>> _put(String path, Object? body) async {
    try {
      final response = await client.dio.put<Object?>(path, data: body);
      final data = response.data;
      return data is Map ? data.cast<String, Object?>() : const {};
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw AlertsException(error.code, statusCode: error.statusCode);
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

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
