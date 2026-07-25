import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';

/// One field that moved, with both sides of the move.
///
/// [before] and [after] are whatever JSON the server stored; rendering them is
/// the screen's job, because "1000" as a role and "1000" as a balance want very
/// different treatment.
final class AuditChange {
  const AuditChange({required this.field, this.before, this.after});

  final String field;
  final Object? before;
  final Object? after;

  bool get isAddition => before == null && after != null;
  bool get isRemoval => after == null && before != null;
}

final class AuditEntry {
  const AuditEntry({
    required this.id,
    required this.action,
    this.subjectType,
    this.subjectId,
    this.before = const {},
    this.after = const {},
    this.ip,
    this.userAgent,
    this.userName,
    this.createdAt,
  });

  final String id;

  /// Dotted and stable, e.g. `auth.login_failed`, `workspace.member_invited`.
  final String action;
  final String? subjectType;
  final String? subjectId;
  final Map<String, Object?> before;
  final Map<String, Object?> after;
  final String? ip;
  final String? userAgent;
  final String? userName;
  final DateTime? createdAt;

  /// The row someone scanning for a break-in is looking for. Every failure the
  /// backend records ends its action in `failed` — `auth.login_failed`,
  /// `data_export.failed` — which is why this is a suffix test rather than a
  /// list that goes stale the moment a new one is added.
  bool get isFailure =>
      action.endsWith('_failed') || action.endsWith('.failed');

  /// The fields that actually moved, `before` first so the reading order is
  /// old → new even when a key exists on only one side.
  List<AuditChange> get changes {
    final fields = <String>[
      ...before.keys,
      ...after.keys.where((key) => !before.containsKey(key)),
    ];

    return [
      for (final field in fields)
        if (before[field] != after[field])
          AuditChange(field: field, before: before[field], after: after[field]),
    ];
  }

  static AuditEntry fromJson(Map<String, Object?> json) {
    final user = (json['user'] as Map?)?.cast<String, Object?>();

    return AuditEntry(
      id: json['id'] as String? ?? '',
      action: json['action'] as String? ?? '',
      subjectType: json['subject_type'] as String?,
      subjectId: json['subject_id'] as String?,
      before: _mapOf(json['before']),
      after: _mapOf(json['after']),
      ip: json['ip'] as String?,
      userAgent: json['user_agent'] as String?,
      userName: user?['name'] as String?,
      createdAt: switch (json['created_at']) {
        final String raw => DateTime.tryParse(raw)?.toLocal(),
        _ => null,
      },
    );
  }

  static Map<String, Object?> _mapOf(Object? raw) =>
      raw is Map ? raw.cast<String, Object?>() : const {};
}

final class AuditException extends CodedFailure {
  const AuditException(super.code, {super.statusCode});

  /// No server behind this build, so there is no trail to read.
  static const noBackend = 'audit_unavailable';
}

/// The workspace trail and the caller's own security log.
///
/// Two endpoints rather than one because they answer different questions: the
/// trail is "what happened in our books", the security log is "what happened to
/// my account", and only the second is readable by everyone.
final class AuditRepository {
  const AuditRepository({required this.client});

  final ApiClient client;

  /// Owner and admin only — the server 403s anyone else, and the screen renders
  /// that as an explanation.
  Future<List<AuditEntry>> workspaceTrail({
    String? action,
    DateTime? from,
    DateTime? to,
    int perPage = 100,
  }) async {
    final response = await _guard(
      () => client.get('/audit-logs', query: {
        if (action != null) 'action': action,
        if (from != null) 'from': from.toUtc().toIso8601String(),
        if (to != null) 'to': to.toUtc().toIso8601String(),
        'per_page': perPage,
      },),
    );

    return _entries(response);
  }

  Future<List<AuditEntry>> securityLog({int limit = 50}) async {
    final response = await _guard(
      () => client.get('/me/security-log', query: {'limit': limit}),
    );

    return _entries(response);
  }

  static List<AuditEntry> _entries(Map<String, Object?> response) => [
        for (final item in response['data'] as List? ?? const [])
          if (item is Map) AuditEntry.fromJson(item.cast<String, Object?>()),
      ];

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw AuditException(error.code, statusCode: error.statusCode);
    }
  }
}
