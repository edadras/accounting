/// The kinds of local write the outbox can carry.
enum SyncOp { create, update, delete }

enum OutboxStatus {
  /// Waiting for its turn to be pushed.
  pending,

  /// Sent, awaiting the server's verdict for this batch.
  inFlight,

  /// Failed for a reason that may pass — a retry is expected.
  failed,

  /// Failed for a reason that cannot pass on its own (a 4xx, or a financial
  /// conflict). Needs a person.
  blocked,
}

/// One queued write, exactly the row shape in docs/09-sync-offline.md §4.
final class OutboxEntry {
  const OutboxEntry({
    required this.id,
    required this.entity,
    required this.entityId,
    required this.op,
    required this.payload,
    required this.baseVersion,
    required this.createdAt,
    this.attempts = 0,
    this.lastError,
    this.status = OutboxStatus.pending,
  });

  final String id;
  final String entity;
  final String entityId;
  final SyncOp op;
  final Map<String, Object?> payload;

  /// The server version this write was made against. `0` for a create.
  final int baseVersion;
  final DateTime createdAt;
  final int attempts;

  /// The stable `ApiException.code` of the last failure, never a prose message
  /// — the UI translates it.
  final String? lastError;
  final OutboxStatus status;

  bool get isSendable =>
      status == OutboxStatus.pending ||
      status == OutboxStatus.failed ||
      status == OutboxStatus.inFlight;

  OutboxEntry copyWith({
    SyncOp? op,
    Map<String, Object?>? payload,
    int? baseVersion,
    int? attempts,
    String? lastError,
    bool clearLastError = false,
    OutboxStatus? status,
  }) =>
      OutboxEntry(
        id: id,
        entity: entity,
        entityId: entityId,
        op: op ?? this.op,
        payload: payload ?? this.payload,
        baseVersion: baseVersion ?? this.baseVersion,
        createdAt: createdAt,
        attempts: attempts ?? this.attempts,
        lastError: clearLastError ? null : (lastError ?? this.lastError),
        status: status ?? this.status,
      );

  Map<String, Object?> toJson() => {
        'id': id,
        'entity': entity,
        'entity_id': entityId,
        'op': op.name,
        'payload': payload,
        'base_version': baseVersion,
        'created_at': createdAt.toUtc().toIso8601String(),
        'attempts': attempts,
        'last_error': lastError,
        'status': status.name,
      };

  static OutboxEntry fromJson(Map<String, Object?> json) => OutboxEntry(
        id: json['id']! as String,
        entity: json['entity']! as String,
        entityId: json['entity_id']! as String,
        op: SyncOp.values.firstWhere(
          (value) => value.name == json['op'],
          orElse: () => SyncOp.update,
        ),
        payload: (json['payload'] as Map?)?.cast<String, Object?>() ?? const {},
        baseVersion: json['base_version'] as int? ?? 0,
        createdAt:
            DateTime.tryParse(json['created_at'] as String? ?? '')?.toUtc() ??
                DateTime.utc(1970),
        attempts: json['attempts'] as int? ?? 0,
        lastError: json['last_error'] as String?,
        status: OutboxStatus.values.firstWhere(
          (value) => value.name == json['status'],
          orElse: () => OutboxStatus.pending,
        ),
      );

  /// The wire shape of one element of `changes` in `POST /sync/push`.
  Map<String, Object?> toChange() => {
        'entity': entity,
        'id': entityId,
        'op': op.name,
        'base_version': baseVersion,
        'payload': payload,
      };
}
