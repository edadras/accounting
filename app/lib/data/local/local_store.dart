import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../../sync/conflict.dart';
import 'key_value_store.dart';
import 'outbox.dart';

/// One locally-held entity, with the bookkeeping sync needs around it.
final class LocalRecord {
  const LocalRecord({
    required this.id,
    required this.data,
    this.version = 0,
    this.pendingSync = false,
    this.deleted = false,
  });

  final String id;

  /// The server's JSON for this record, or the device's version of it while a
  /// write is still queued.
  final Map<String, Object?> data;

  /// The server version this copy is based on. `0` means the server has never
  /// seen it.
  final int version;
  final bool pendingSync;
  final bool deleted;

  LocalRecord copyWith({
    Map<String, Object?>? data,
    int? version,
    bool? pendingSync,
    bool? deleted,
  }) =>
      LocalRecord(
        id: id,
        data: data ?? this.data,
        version: version ?? this.version,
        pendingSync: pendingSync ?? this.pendingSync,
        deleted: deleted ?? this.deleted,
      );

  Map<String, Object?> toJson() => {
        'id': id,
        'data': data,
        'version': version,
        'pending_sync': pendingSync,
        'deleted': deleted,
      };

  static LocalRecord fromJson(Map<String, Object?> json) => LocalRecord(
        id: json['id']! as String,
        data: (json['data'] as Map?)?.cast<String, Object?>() ?? const {},
        version: json['version'] as int? ?? 0,
        pendingSync: json['pending_sync'] as bool? ?? false,
        deleted: json['deleted'] as bool? ?? false,
      );
}

/// The client's source of truth.
///
/// docs/09-sync-offline.md: the UI reads here and only here, so a transaction
/// recorded in a tunnel is on screen before any network call is even attempted.
/// Records live in memory for synchronous reads and are written through to a
/// [KeyValueStore] as JSON on every change.
final class LocalStore extends ChangeNotifier {
  LocalStore._(this._kv, this._namespace);

  static const entityTransaction = 'transaction';
  static const entityAccount = 'account';
  static const entityCategory = 'category';
  static const entityBudget = 'budget';

  static const _knownEntities = [
    entityTransaction,
    entityAccount,
    entityCategory,
    entityBudget,
  ];

  final KeyValueStore _kv;
  final String _namespace;

  final Map<String, Map<String, LocalRecord>> _records = {};
  final List<OutboxEntry> _outbox = [];
  final List<SyncConflict> _conflicts = [];

  String? _cursor;
  DateTime? _lastServerTime;

  static Future<LocalStore> open(
    KeyValueStore kv, {
    String namespace = 'finora.v1',
  }) async {
    final store = LocalStore._(kv, namespace);
    await store._load();
    return store;
  }

  String _key(String suffix) => '$_namespace.$suffix';

  Future<void> _load() async {
    for (final entity in _knownEntities) {
      final raw = await _kv.read(_key('records.$entity'));
      final bucket = <String, LocalRecord>{};
      for (final item in _decodeList(raw)) {
        final record = LocalRecord.fromJson(item);
        bucket[record.id] = record;
      }
      _records[entity] = bucket;
    }

    _outbox
      ..clear()
      ..addAll([
        for (final item in _decodeList(await _kv.read(_key('outbox'))))
          OutboxEntry.fromJson(item),
      ]);

    _conflicts
      ..clear()
      ..addAll([
        for (final item in _decodeList(await _kv.read(_key('conflicts'))))
          SyncConflict.fromJson(item),
      ]);

    final meta = await _kv.read(_key('sync'));
    if (meta != null) {
      final decoded = jsonDecode(meta);
      if (decoded is Map) {
        _cursor = decoded['cursor'] as String?;
        _lastServerTime =
            DateTime.tryParse(decoded['server_time'] as String? ?? '')?.toUtc();
      }
    }
  }

  static List<Map<String, Object?>> _decodeList(String? raw) {
    if (raw == null || raw.isEmpty) return const [];
    final decoded = jsonDecode(raw);
    if (decoded is! List) return const [];
    return [
      for (final item in decoded)
        if (item is Map) item.cast<String, Object?>(),
    ];
  }

  // ---------------------------------------------------------------- records

  List<LocalRecord> records(String entity) => [
        for (final record in (_records[entity] ?? const {}).values)
          if (!record.deleted) record,
      ];

  LocalRecord? record(String entity, String id) {
    final found = _records[entity]?[id];
    return found == null || found.deleted ? null : found;
  }

  bool hasPendingWrite(String entity, String id) => _outbox.any(
        (entry) => entry.entity == entity && entry.entityId == id,
      );

  Future<void> put(String entity, LocalRecord record) async {
    (_records[entity] ??= {})[record.id] = record;
    await _persistRecords(entity);
    notifyListeners();
  }

  /// One write for a whole page of pulled changes, rather than one per row.
  Future<void> putAll(String entity, Iterable<LocalRecord> records) async {
    if (records.isEmpty) return;
    final bucket = _records[entity] ??= {};
    for (final record in records) {
      bucket[record.id] = record;
    }
    await _persistRecords(entity);
    notifyListeners();
  }

  Future<void> markDeleted(String entity, String id) async {
    final existing = _records[entity]?[id];
    if (existing == null) return;
    _records[entity]![id] = existing.copyWith(deleted: true);
    await _persistRecords(entity);
    notifyListeners();
  }

  Future<void> _persistRecords(String entity) => _kv.write(
        _key('records.$entity'),
        jsonEncode([
          for (final record in (_records[entity] ?? const {}).values)
            record.toJson(),
        ]),
      );

  // ----------------------------------------------------------------- outbox

  /// FIFO, which is the order the server must apply them in.
  List<OutboxEntry> get outbox => List.unmodifiable(_outbox);

  List<OutboxEntry> sendable({int limit = 50}) =>
      [for (final entry in _outbox) if (entry.isSendable) entry]
          .take(limit)
          .toList();

  int get pendingCount =>
      _outbox.where((entry) => entry.status != OutboxStatus.blocked).length;

  Future<void> enqueue(OutboxEntry entry) async {
    _outbox.add(entry);
    await _persistOutbox();
    notifyListeners();
  }

  Future<void> replaceOutbox(Iterable<OutboxEntry> entries) async {
    _outbox
      ..clear()
      ..addAll(entries);
    await _persistOutbox();
    notifyListeners();
  }

  Future<void> _persistOutbox() => _kv.write(
        _key('outbox'),
        jsonEncode([for (final entry in _outbox) entry.toJson()]),
      );

  // -------------------------------------------------------------- conflicts

  List<SyncConflict> get conflicts => List.unmodifiable(_conflicts);

  Future<void> addConflict(SyncConflict conflict) async {
    _conflicts
      ..removeWhere((existing) =>
          existing.entity == conflict.entity &&
          existing.entityId == conflict.entityId,)
      ..add(conflict);
    await _persistConflicts();
    notifyListeners();
  }

  Future<void> removeConflict(String id) async {
    _conflicts.removeWhere((conflict) => conflict.id == id);
    await _persistConflicts();
    notifyListeners();
  }

  Future<void> _persistConflicts() => _kv.write(
        _key('conflicts'),
        jsonEncode([for (final conflict in _conflicts) conflict.toJson()]),
      );

  // ------------------------------------------------------------- sync state

  String? get cursor => _cursor;

  /// The last `server_time` the server reported. Used as `since` on the next
  /// pull, because the device clock may be wrong by hours.
  DateTime? get lastServerTime => _lastServerTime;

  Future<void> saveSyncState({String? cursor, DateTime? serverTime}) async {
    _cursor = cursor;
    if (serverTime != null) _lastServerTime = serverTime.toUtc();
    await _kv.write(
      _key('sync'),
      jsonEncode({
        'cursor': _cursor,
        'server_time': _lastServerTime?.toIso8601String(),
      }),
    );
    notifyListeners();
  }

  Future<void> wipe() async {
    _records.clear();
    _outbox.clear();
    _conflicts.clear();
    _cursor = null;
    _lastServerTime = null;
    await _kv.clear();
    notifyListeners();
  }
}
