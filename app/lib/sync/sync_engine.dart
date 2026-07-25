import 'package:ulid/ulid.dart';

import '../data/local/local_store.dart';
import '../data/local/outbox.dart';
import '../data/remote/api_exception.dart';
import 'conflict.dart';
import 'sync_api.dart';

final class SyncOutcome {
  const SyncOutcome({
    this.pushed = 0,
    this.conflicted = 0,
    this.rejected = 0,
    this.pulled = 0,
    this.error,
  });

  final int pushed;
  final int conflicted;
  final int rejected;
  final int pulled;

  /// The failure that stopped this run, already reduced to a stable code.
  final ApiException? error;

  bool get succeeded => error == null;

  /// A network failure means the device is offline; a 422 means the device is
  /// online and simply wrong.
  bool get reachedServer => error == null || !error!.isNetworkFailure;

  SyncOutcome merge(SyncOutcome other) => SyncOutcome(
        pushed: pushed + other.pushed,
        conflicted: conflicted + other.conflicted,
        rejected: rejected + other.rejected,
        pulled: pulled + other.pulled,
        error: error ?? other.error,
      );
}

/// Drains the outbox to the server and folds server changes back in.
///
/// Nothing here ever blocks the UI: the repository has already written to
/// [LocalStore] and returned by the time the engine runs.
final class SyncEngine {
  SyncEngine({
    required this.api,
    required this.store,
    required this.deviceId,
    this.batchSize = 50,
    this.pageSize = 200,
    this.maxAttempts = 8,
    String Function()? idFactory,
  }) : _newId = idFactory ?? _ulid;

  final SyncApi api;
  final LocalStore store;
  final String deviceId;
  final int batchSize;
  final int pageSize;

  /// After this many failures a queued write stops retrying and waits for a
  /// person, so a permanently poisoned row cannot spin forever.
  final int maxAttempts;

  final String Function() _newId;

  static String _ulid() => Ulid().toString();

  Future<SyncOutcome> syncNow() async {
    final pushed = await push();
    if (!pushed.reachedServer) return pushed;
    return pushed.merge(await pull());
  }

  // ------------------------------------------------------------------- push

  Future<SyncOutcome> push() async {
    var outcome = const SyncOutcome();
    final handled = <String>{};

    while (true) {
      final batch = _nextBatch(handled);
      if (batch.isEmpty) return outcome;

      handled.addAll(batch.map((entry) => entry.id));

      final PushResponse response;
      try {
        response = await api.push(deviceId: deviceId, entries: batch);
      } on ApiException catch (error) {
        await _failBatch(batch, error);
        return outcome.merge(SyncOutcome(error: error));
      }

      if (response.serverTime != null) {
        await store.saveSyncState(
          cursor: store.cursor,
          serverTime: response.serverTime,
        );
      }

      outcome = outcome.merge(await _applyPushResults(batch, response));
    }
  }

  /// FIFO, and at most one queued write per record per batch: two changes to
  /// the same transaction must be applied in order, not raced.
  List<OutboxEntry> _nextBatch(Set<String> alreadyHandled) {
    final batch = <OutboxEntry>[];
    final seen = <String>{};

    for (final entry in store.outbox) {
      if (batch.length >= batchSize) break;
      if (!entry.isSendable) continue;
      if (alreadyHandled.contains(entry.id)) continue;
      if (!seen.add('${entry.entity}:${entry.entityId}')) continue;
      batch.add(entry);
    }

    return batch;
  }

  Future<void> _failBatch(List<OutboxEntry> batch, ApiException error) async {
    final ids = {for (final entry in batch) entry.id};

    await store.replaceOutbox([
      for (final entry in store.outbox)
        if (!ids.contains(entry.id))
          entry
        else
          entry.copyWith(
            attempts: entry.attempts + 1,
            lastError: error.code,
            // A 4xx cannot come good on its own; surface it instead of
            // retrying it forever.
            status: error.isRetryable && entry.attempts + 1 < maxAttempts
                ? OutboxStatus.failed
                : OutboxStatus.blocked,
          ),
    ]);
  }

  Future<SyncOutcome> _applyPushResults(
    List<OutboxEntry> batch,
    PushResponse response,
  ) async {
    final verdicts = {
      for (final result in response.results) result.id: result,
    };
    final batchIds = {for (final entry in batch) entry.id};
    final detectedAt = response.serverTime ?? store.lastServerTime;

    final remaining = <OutboxEntry>[];
    var pushed = 0;
    var conflicted = 0;
    var rejected = 0;

    for (final entry in store.outbox) {
      if (!batchIds.contains(entry.id)) {
        remaining.add(entry);
        continue;
      }

      final result = verdicts[entry.entityId] ?? verdicts[entry.id];

      if (result == null) {
        // Silence about a change is not consent — keep it queued.
        remaining.add(entry.copyWith(attempts: entry.attempts + 1));
        continue;
      }

      switch (result.verdict) {
        case PushVerdict.applied:
          pushed++;
          await _markSynced(entry, result.serverVersion ?? entry.baseVersion + 1);

        case PushVerdict.conflict:
          final resolved = await _resolveConflict(entry, result, detectedAt);
          if (resolved == null) {
            conflicted++;
            remaining.add(entry.copyWith(
              status: OutboxStatus.blocked,
              lastError: ApiException.codeVersionConflict,
              attempts: entry.attempts + 1,
            ),);
          } else {
            remaining.add(resolved);
          }

        case PushVerdict.rejected:
        case PushVerdict.unknown:
          rejected++;
          remaining.add(entry.copyWith(
            status: OutboxStatus.blocked,
            lastError: result.errorCode ?? ApiException.codeValidationFailed,
            attempts: entry.attempts + 1,
          ),);
      }
    }

    await store.replaceOutbox(remaining);

    return SyncOutcome(
      pushed: pushed,
      conflicted: conflicted,
      rejected: rejected,
    );
  }

  Future<void> _markSynced(OutboxEntry entry, int serverVersion) async {
    if (entry.op == SyncOp.delete) {
      await store.markDeleted(entry.entity, entry.entityId);
      return;
    }

    final existing = store.record(entry.entity, entry.entityId);
    await store.put(
      entry.entity,
      LocalRecord(
        id: entry.entityId,
        data: existing?.data ?? entry.payload,
        version: serverVersion,
        pendingSync: false,
      ),
    );
  }

  /// Returns the re-queued entry when the conflict merged cleanly, or null when
  /// it touches money and must be shown to the user.
  Future<OutboxEntry?> _resolveConflict(
    OutboxEntry entry,
    PushResult result,
    DateTime? detectedAt,
  ) async {
    final serverPayload = result.serverPayload ?? const <String, Object?>{};
    final serverVersion = result.serverVersion ?? entry.baseVersion;

    final financial = ConflictPolicy.financialDivergence(
      entry.entity,
      entry.payload,
      serverPayload,
    );

    if (financial.isNotEmpty || serverPayload.isEmpty) {
      await store.addConflict(SyncConflict(
        id: _newId(),
        entity: entry.entity,
        entityId: entry.entityId,
        fields: financial,
        local: entry.payload,
        server: serverPayload,
        serverVersion: serverVersion,
        detectedAt: detectedAt ?? DateTime.utc(1970),
      ),);
      // The local record is left exactly as the user wrote it.
      return null;
    }

    final merged = ConflictPolicy.mergeNonFinancial(
      entity: entry.entity,
      local: entry.payload,
      server: serverPayload,
    );

    await store.put(
      entry.entity,
      LocalRecord(
        id: entry.entityId,
        data: merged,
        version: serverVersion,
        pendingSync: true,
      ),
    );

    return entry.copyWith(
      payload: merged,
      baseVersion: serverVersion,
      op: SyncOp.update,
      attempts: entry.attempts + 1,
      status: entry.attempts + 1 >= maxAttempts
          ? OutboxStatus.blocked
          : OutboxStatus.pending,
      clearLastError: true,
    );
  }

  // ------------------------------------------------------------------- pull

  Future<SyncOutcome> pull() async {
    var cursor = store.cursor;
    var applied = 0;

    for (var page = 0; page < 100; page++) {
      final PullResponse response;
      try {
        response = await api.pull(
          since: store.lastServerTime,
          cursor: cursor,
          limit: pageSize,
        );
      } on ApiException catch (error) {
        return SyncOutcome(pulled: applied, error: error);
      }

      applied += await _applyPull(response.changes);

      // `since` only advances once the whole page run is done, so an
      // interrupted pull resumes rather than skips.
      await store.saveSyncState(
        cursor: response.hasMore ? response.nextCursor : null,
        serverTime: response.hasMore ? store.lastServerTime : response.serverTime,
      );

      if (!response.hasMore) break;
      cursor = response.nextCursor;
    }

    return SyncOutcome(pulled: applied);
  }

  Future<int> _applyPull(List<PullChange> changes) async {
    if (changes.isEmpty) return 0;

    final byEntity = <String, List<LocalRecord>>{};
    var applied = 0;

    for (final change in changes) {
      if (change.id.isEmpty || change.entity.isEmpty) continue;

      // A record the device has already changed but not yet pushed must not be
      // overwritten by the server copy — push decides that, not pull.
      if (store.hasPendingWrite(change.entity, change.id)) continue;

      if (change.op == SyncOp.delete) {
        await store.markDeleted(change.entity, change.id);
        applied++;
        continue;
      }

      final existing = store.record(change.entity, change.id);
      if (existing != null && existing.version >= change.version) continue;

      (byEntity[change.entity] ??= []).add(LocalRecord(
        id: change.id,
        data: change.payload,
        version: change.version,
      ),);
      applied++;
    }

    for (final entry in byEntity.entries) {
      await store.putAll(entry.key, entry.value);
    }

    return applied;
  }

  // ------------------------------------------------------------- resolution

  /// Applies the user's choice from the conflicts screen.
  Future<void> resolve(
    SyncConflict conflict,
    ConflictResolution choice,
  ) async {
    final blocked = {
      for (final entry in store.outbox)
        if (entry.entity == conflict.entity &&
            entry.entityId == conflict.entityId)
          entry.id,
    };

    switch (choice) {
      case ConflictResolution.keepServer:
        await store.put(
          conflict.entity,
          LocalRecord(
            id: conflict.entityId,
            data: conflict.server,
            version: conflict.serverVersion,
          ),
        );
        await store.replaceOutbox([
          for (final entry in store.outbox)
            if (!blocked.contains(entry.id)) entry,
        ]);

      case ConflictResolution.keepLocal:
        await store.put(
          conflict.entity,
          LocalRecord(
            id: conflict.entityId,
            data: conflict.local,
            version: conflict.serverVersion,
            pendingSync: true,
          ),
        );
        // Re-queued against the version the server actually holds, so the
        // second attempt is an update rather than another stale write.
        await store.replaceOutbox([
          for (final entry in store.outbox)
            if (!blocked.contains(entry.id))
              entry
            else
              entry.copyWith(
                payload: conflict.local,
                baseVersion: conflict.serverVersion,
                op: SyncOp.update,
                status: OutboxStatus.pending,
                attempts: 0,
                clearLastError: true,
              ),
        ]);
    }

    await store.removeConflict(conflict.id);
  }
}
