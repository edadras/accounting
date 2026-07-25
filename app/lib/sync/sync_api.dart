import '../data/local/outbox.dart';
import '../data/remote/api_client.dart';

/// The server's verdict on one pushed change.
enum PushVerdict {
  /// Stored. Drop the outbox row and adopt `server_version`.
  applied,

  /// The record moved on the server since `base_version`.
  conflict,

  /// The server will never accept this change as written.
  rejected,

  /// A status this build does not know — treated as needing a person, not a
  /// retry loop.
  unknown,
}

final class PushResult {
  const PushResult({
    required this.id,
    required this.verdict,
    this.serverVersion,
    this.serverPayload,
    this.errorCode,
  });

  final String id;
  final PushVerdict verdict;
  final int? serverVersion;
  final Map<String, Object?>? serverPayload;
  final String? errorCode;

  static PushResult fromJson(Map<String, Object?> json) => PushResult(
        id: json['id'] as String? ?? '',
        verdict: switch (json['status']) {
          'applied' => PushVerdict.applied,
          'conflict' => PushVerdict.conflict,
          'rejected' || 'invalid' => PushVerdict.rejected,
          _ => PushVerdict.unknown,
        },
        serverVersion: json['server_version'] as int?,
        serverPayload:
            (json['server_payload'] as Map?)?.cast<String, Object?>(),
        errorCode: json['error'] is Map
            ? (json['error']! as Map)['code'] as String?
            : json['error'] as String?,
      );
}

final class PushResponse {
  const PushResponse({required this.results, required this.serverTime});

  final List<PushResult> results;

  /// Authoritative clock. docs/09-sync-offline.md §5: never the device's.
  final DateTime? serverTime;
}

final class PullChange {
  const PullChange({
    required this.entity,
    required this.id,
    required this.op,
    required this.version,
    required this.payload,
  });

  final String entity;
  final String id;
  final SyncOp op;
  final int version;
  final Map<String, Object?> payload;

  static PullChange fromJson(Map<String, Object?> json) => PullChange(
        entity: json['entity'] as String? ?? '',
        id: json['id'] as String? ?? '',
        op: switch (json['op']) {
          'delete' => SyncOp.delete,
          'create' => SyncOp.create,
          _ => SyncOp.update,
        },
        version: json['version'] as int? ?? 0,
        payload: (json['payload'] as Map?)?.cast<String, Object?>() ?? const {},
      );
}

final class PullResponse {
  const PullResponse({
    required this.changes,
    required this.serverTime,
    this.nextCursor,
  });

  final List<PullChange> changes;
  final DateTime? serverTime;
  final String? nextCursor;

  bool get hasMore => nextCursor != null && nextCursor!.isNotEmpty;
}

/// `POST /sync/push` and `GET /sync/pull`.
///
/// Parsing is deliberately forgiving about where `changes`, `server_time` and
/// the cursor sit — top level or under `data`/`meta` — so a server-side
/// envelope tweak does not brick every installed client.
final class SyncApi {
  const SyncApi(this.client);

  final ApiClient client;

  Future<PushResponse> push({
    required String deviceId,
    required List<OutboxEntry> entries,
  }) async {
    final response = await client.post('/sync/push', body: {
      'device_id': deviceId,
      'changes': [for (final entry in entries) entry.toChange()],
    },);

    final body = _unwrap(response);

    return PushResponse(
      results: [
        for (final item in body['results'] as List? ?? const [])
          if (item is Map) PushResult.fromJson(item.cast<String, Object?>()),
      ],
      serverTime: _serverTime(response, body),
    );
  }

  Future<PullResponse> pull({
    DateTime? since,
    String? cursor,
    int limit = 200,
  }) async {
    final response = await client.get('/sync/pull', query: {
      if (since != null) 'since': since.toUtc().toIso8601String(),
      if (cursor != null && cursor.isNotEmpty) 'cursor': cursor,
      'limit': limit,
    },);

    final body = _unwrap(response);
    final meta = (response['meta'] as Map?)?.cast<String, Object?>() ?? const {};

    return PullResponse(
      changes: [
        for (final item in body['changes'] as List? ?? const [])
          if (item is Map) PullChange.fromJson(item.cast<String, Object?>()),
      ],
      serverTime: _serverTime(response, body),
      nextCursor: (meta['next_cursor'] ?? meta['cursor_next'] ?? body['next_cursor'])
          as String?,
    );
  }

  static Map<String, Object?> _unwrap(Map<String, Object?> response) {
    final data = response['data'];
    if (data is Map) return data.cast<String, Object?>();
    return response;
  }

  static DateTime? _serverTime(
    Map<String, Object?> response,
    Map<String, Object?> body,
  ) {
    final meta = (response['meta'] as Map?)?.cast<String, Object?>();
    final raw = body['server_time'] ??
        meta?['server_time'] ??
        response['server_time'];
    return raw is String ? DateTime.tryParse(raw)?.toUtc() : null;
  }
}
