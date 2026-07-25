import 'package:collection/collection.dart';

/// A push the server refused because the record moved underneath us, kept until
/// a person decides which version is true.
final class SyncConflict {
  const SyncConflict({
    required this.id,
    required this.entity,
    required this.entityId,
    required this.fields,
    required this.local,
    required this.server,
    required this.serverVersion,
    required this.detectedAt,
  });

  final String id;
  final String entity;
  final String entityId;

  /// The financial fields that disagree — what the resolution screen lists.
  final List<String> fields;
  final Map<String, Object?> local;
  final Map<String, Object?> server;
  final int serverVersion;

  /// Taken from the server's `server_time`, never the device clock.
  final DateTime detectedAt;

  Map<String, Object?> toJson() => {
        'id': id,
        'entity': entity,
        'entity_id': entityId,
        'fields': fields,
        'local': local,
        'server': server,
        'server_version': serverVersion,
        'detected_at': detectedAt.toUtc().toIso8601String(),
      };

  static SyncConflict fromJson(Map<String, Object?> json) => SyncConflict(
        id: json['id']! as String,
        entity: json['entity']! as String,
        entityId: json['entity_id']! as String,
        fields: [for (final field in json['fields'] as List? ?? []) '$field'],
        local: (json['local'] as Map?)?.cast<String, Object?>() ?? const {},
        server: (json['server'] as Map?)?.cast<String, Object?>() ?? const {},
        serverVersion: json['server_version'] as int? ?? 0,
        detectedAt:
            DateTime.tryParse(json['detected_at'] as String? ?? '')?.toUtc() ??
                DateTime.utc(1970),
      );
}

/// Which version of a conflicted record wins.
enum ConflictResolution { keepLocal, keepServer }

/// Decides what may be merged silently and what must be asked about.
///
/// docs/09-sync-offline.md §6: guessing an amount is worse than asking, so a
/// disagreement on money, its currency, the account it moved through or when it
/// happened is never resolved automatically. Everything else is last-write-wins.
abstract final class ConflictPolicy {
  static const _financialByEntity = <String, Set<String>>{
    'transaction': {
      'type',
      'amount',
      'base',
      'currency',
      'account_id',
      'counter_account_id',
      'occurred_at',
      'fx_rate',
    },
    'account': {'balance', 'currency', 'opening_balance'},
    'budget': {'amount', 'currency', 'period'},
  };

  static const _fallbackFinancial = {
    'amount',
    'base',
    'currency',
    'balance',
    'occurred_at',
  };

  static Set<String> financialFieldsOf(String entity) =>
      _financialByEntity[entity] ?? _fallbackFinancial;

  static bool isFinancial(String entity, String field) =>
      financialFieldsOf(entity).contains(field);

  /// Fields present in either version whose values differ.
  static List<String> divergentFields(
    Map<String, Object?> local,
    Map<String, Object?> server,
  ) {
    const equality = DeepCollectionEquality();
    final keys = {...local.keys, ...server.keys}
      ..removeAll(const {'version', 'updated_at', 'created_at'});

    return [
      for (final key in keys)
        if (!equality.equals(local[key], server[key])) key,
    ]..sort();
  }

  static List<String> financialDivergence(
    String entity,
    Map<String, Object?> local,
    Map<String, Object?> server,
  ) =>
      [
        for (final field in divergentFields(local, server))
          if (isFinancial(entity, field)) field,
      ];

  /// Server record with the device's non-financial edits laid back on top.
  ///
  /// The local write is by definition the later one — the device only pushes
  /// what the user just changed — so last-write-wins means the local value.
  static Map<String, Object?> mergeNonFinancial({
    required String entity,
    required Map<String, Object?> local,
    required Map<String, Object?> server,
  }) {
    final merged = Map<String, Object?>.from(server);

    for (final field in divergentFields(local, server)) {
      if (!isFinancial(entity, field)) merged[field] = local[field];
    }

    return merged;
  }
}
