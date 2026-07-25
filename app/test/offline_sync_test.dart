import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/data/local/local_store.dart';
import 'package:finora/data/local/outbox.dart';
import 'package:finora/data/remote/api_exception.dart';
import 'package:finora/data/remote/money_codec.dart';
import 'package:finora/sync/conflict.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/harness.dart';

/// The mandatory offline scenarios from docs/09-sync-offline.md §10.
void main() {
  group('airplane mode', () {
    test('50 transactions land locally and queue, and the UI sees them at once',
        () async {
      final harness = await SyncHarness.create();
      harness.offline = true;

      await harness.recordExpenses(50);

      expect(harness.store.records(LocalStore.entityTransaction).length, 50);
      expect(harness.store.outbox.length, 50);
      expect(harness.store.pendingCount, 50);

      // "The UI sees them" means the repository — the only thing the UI reads.
      final visible = await harness.repository.transactions();
      expect(visible.length, 50);
      expect(visible.every((transaction) => transaction.pendingSync), isTrue);

      // Not one request was even attempted while recording.
      expect(harness.adapter.requests, isEmpty);
    });

    test('every queued write already holds its final ULID', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;

      final saved = await harness.recordExpenses(3);

      expect(
        harness.store.outbox.map((entry) => entry.entityId),
        saved.map((transaction) => transaction.id),
      );
      expect(
        harness.store.outbox.every((entry) => entry.op == SyncOp.create),
        isTrue,
      );
    });

    test('the balance moves immediately, without waiting for the server',
        () async {
      final harness = await SyncHarness.create();
      harness.offline = true;

      await harness.recordExpenses(1);

      final account = (await harness.repository.accounts()).single;
      expect(account.balance, const Money(1000000 - 1000, Currency.irr));
    });

    test('the dashboard totals what is only in the outbox', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;

      await harness.recordExpenses(3);
      final summary = await harness.repository.summary();

      // 1000 + 1001 + 1002, all still unsent.
      expect(summary.byCategory.single.amount.minorUnits, 3003);
    });

    test('a push while offline queues for retry rather than failing the write',
        () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(5);

      final outcome = await harness.engine.push();

      expect(outcome.succeeded, isFalse);
      expect(outcome.reachedServer, isFalse);
      expect(outcome.error!.code, ApiException.codeNetworkUnreachable);
      expect(harness.store.outbox.length, 5);
      expect(
        harness.store.outbox.every(
          (entry) =>
              entry.status == OutboxStatus.failed &&
              entry.lastError == ApiException.codeNetworkUnreachable,
        ),
        isTrue,
      );
    });
  });

  group('reconnect', () {
    test('all 50 push, the outbox drains, and a replay adds nothing', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(50);

      harness.offline = false;
      final outcome = await harness.engine.push();

      expect(outcome.pushed, 50);
      expect(harness.store.outbox, isEmpty);
      expect(harness.store.pendingCount, 0);
      expect(harness.server.count(LocalStore.entityTransaction), 50);
      expect(harness.server.seenChangeIds.toSet().length, 50);

      final records = harness.store.records(LocalStore.entityTransaction);
      expect(records.length, 50);
      expect(records.every((record) => record.version == 1), isTrue);
      expect(records.every((record) => !record.pendingSync), isTrue);

      // Replaying the exact same batch is a no-op on both sides.
      await harness.engine.push();
      expect(harness.server.count(LocalStore.entityTransaction), 50);
      expect(harness.store.records(LocalStore.entityTransaction).length, 50);
      expect(harness.store.outbox, isEmpty);
    });

    test('a push cut off after the server applied it does not duplicate',
        () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(50);

      // The server stores all 50, then the connection dies before the answer.
      harness
        ..offline = false
        ..dropResponses = true;
      await harness.engine.push();

      expect(harness.server.count(LocalStore.entityTransaction), 50);
      expect(harness.store.outbox.length, 50);

      harness.dropResponses = false;
      await harness.engine.push();

      expect(harness.server.count(LocalStore.entityTransaction), 50);
      expect(harness.server.seenChangeIds.length, 100);
      expect(harness.server.seenChangeIds.toSet().length, 50);
      expect(harness.store.records(LocalStore.entityTransaction).length, 50);
      expect(harness.store.outbox, isEmpty);
    });

    test('the outbox is drained in batches', () async {
      final harness = await SyncHarness.create(batchSize: 10);
      harness.offline = true;
      await harness.recordExpenses(50);

      harness.offline = false;
      final outcome = await harness.engine.push();

      expect(outcome.pushed, 50);
      expect(harness.server.pushCalls, 5);
      expect(harness.store.outbox, isEmpty);
    });
  });

  group('conflicts', () {
    Future<SyncHarness> conflicted({
      required int serverAmount,
      String serverDescription = 'خرید',
      int serverVersion = 4,
    }) async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      final id = (await harness.recordExpenses(1)).single.id;

      harness.server.conflictFor[id] = serverTransactionPayload(
        id: id,
        amountMinorUnits: serverAmount,
        description: serverDescription,
      );
      harness.server.versions['transaction:$id'] = serverVersion;
      harness.offline = false;

      return harness;
    }

    test('a conflict on the amount is kept unresolved and does not overwrite',
        () async {
      final harness = await conflicted(serverAmount: 9999);
      final id = harness.store.outbox.single.entityId;

      final outcome = await harness.engine.push();

      expect(outcome.conflicted, 1);

      final conflict = harness.store.conflicts.single;
      expect(conflict.entityId, id);
      expect(conflict.fields, contains('amount'));
      expect(conflict.serverVersion, 4);
      // The server's clock, never the device's.
      expect(conflict.detectedAt, DateTime.utc(2026, 7, 25, 9));
      expect(MoneyCodec.decode(conflict.local['amount']).minorUnits, 1000);
      expect(MoneyCodec.decode(conflict.server['amount']).minorUnits, 9999);

      // The user's own figure is untouched.
      final local = harness.store.record(LocalStore.entityTransaction, id)!;
      expect(MoneyCodec.decode(local.data['amount']).minorUnits, 1000);

      final visible = (await harness.repository.transactions()).single;
      expect(visible.amount, const Money(1000, Currency.irr));

      // And it is not retried behind the user's back.
      expect(harness.store.outbox.single.status, OutboxStatus.blocked);
      expect(harness.store.pendingCount, 0);
    });

    test('a conflict on the description alone merges automatically', () async {
      final harness = await conflicted(
        serverAmount: 1000,
        serverDescription: 'a different note',
        serverVersion: 7,
      );
      final id = harness.store.outbox.single.entityId;

      final outcome = await harness.engine.push();

      expect(outcome.conflicted, 0);
      expect(harness.store.conflicts, isEmpty);

      final local = harness.store.record(LocalStore.entityTransaction, id)!;
      expect(local.data['description'], 'خرید 0');
      expect(MoneyCodec.decode(local.data['amount']).minorUnits, 1000);
      expect(local.version, 7);

      // The merged record is re-queued against the version the server holds.
      final queued = harness.store.outbox.single;
      expect(queued.status, OutboxStatus.pending);
      expect(queued.op, SyncOp.update);
      expect(queued.baseVersion, 7);
      expect(queued.payload['description'], 'خرید 0');
    });

    test('keeping the local version re-queues it against the server version',
        () async {
      final harness = await conflicted(serverAmount: 9999);
      await harness.engine.push();

      await harness.engine.resolve(
        harness.store.conflicts.single,
        ConflictResolution.keepLocal,
      );

      expect(harness.store.conflicts, isEmpty);
      final queued = harness.store.outbox.single;
      expect(queued.status, OutboxStatus.pending);
      expect(queued.baseVersion, 4);
      expect(MoneyCodec.decode(queued.payload['amount']).minorUnits, 1000);
    });

    test('keeping the server version adopts it and drops the queued write',
        () async {
      final harness = await conflicted(serverAmount: 9999);
      final id = harness.store.outbox.single.entityId;
      await harness.engine.push();

      await harness.engine.resolve(
        harness.store.conflicts.single,
        ConflictResolution.keepServer,
      );

      expect(harness.store.conflicts, isEmpty);
      expect(harness.store.outbox, isEmpty);

      final local = harness.store.record(LocalStore.entityTransaction, id)!;
      expect(MoneyCodec.decode(local.data['amount']).minorUnits, 9999);
      expect(local.version, 4);
    });

    test('a money field is never quietly merged, even alongside a note change',
        () {
      final local = serverTransactionPayload(
        id: 'x',
        amountMinorUnits: 1000,
        description: 'mine',
      );
      final server = serverTransactionPayload(
        id: 'x',
        amountMinorUnits: 2000,
        description: 'theirs',
      );

      expect(
        ConflictPolicy.financialDivergence('transaction', local, server),
        containsAll(<String>['amount', 'base']),
      );
      expect(
        ConflictPolicy.mergeNonFinancial(
          entity: 'transaction',
          local: local,
          server: server,
        )['description'],
        'mine',
      );
    });
  });

  group('server failures', () {
    test('a 500 leaves the write queued for another attempt', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(2);
      harness
        ..offline = false
        ..respond = (_) => MockAdapter.error('server_error', status: 500);

      final outcome = await harness.engine.push();

      expect(outcome.error!.code, 'server_error');
      expect(harness.store.outbox.length, 2);
      expect(
        harness.store.outbox.every(
          (entry) =>
              entry.status == OutboxStatus.failed && entry.attempts == 1,
        ),
        isTrue,
      );
      expect(harness.store.pendingCount, 2);

      // Once the server recovers the same writes go through untouched.
      harness.respond = null;
      expect((await harness.engine.push()).pushed, 2);
      expect(harness.store.outbox, isEmpty);
    });

    test('a 422 blocks the write instead of retrying it forever', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(2);
      harness
        ..offline = false
        ..respond = (_) => MockAdapter.error('validation_failed');

      final outcome = await harness.engine.push();

      expect(outcome.error!.code, 'validation_failed');
      expect(
        harness.store.outbox.every(
          (entry) =>
              entry.status == OutboxStatus.blocked &&
              entry.lastError == 'validation_failed',
        ),
        isTrue,
      );
      // Blocked rows are not "waiting to send" — they are waiting for a person.
      expect(harness.store.pendingCount, 0);

      // A second run does not touch them again.
      final requestsSoFar = harness.adapter.requests.length;
      await harness.engine.push();
      expect(harness.adapter.requests.length, requestsSoFar);
    });

    test('a rejected change is surfaced, not retried', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      final id = (await harness.recordExpenses(1)).single.id;

      harness
        ..offline = false
        ..respond = (_) => jsonResponse({
              'data': {
                'results': [
                  {
                    'id': id,
                    'status': 'rejected',
                    'error': {'code': 'plan_limit_reached'},
                  },
                ],
              },
              'meta': {'server_time': '2026-07-25T09:00:00Z'},
            });

      final outcome = await harness.engine.push();

      expect(outcome.rejected, 1);
      expect(harness.store.outbox.single.status, OutboxStatus.blocked);
      expect(harness.store.outbox.single.lastError, 'plan_limit_reached');
    });
  });

  group('pull', () {
    test('server changes are applied without duplicating existing rows',
        () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      final saved = await harness.recordExpenses(3);
      harness.offline = false;
      await harness.engine.push();

      expect(harness.store.records(LocalStore.entityTransaction).length, 3);

      harness.server.pendingPullChanges.addAll([
        // A row the device already has, at a newer server version.
        {
          'entity': 'transaction',
          'id': saved.first.id,
          'op': 'update',
          'version': 9,
          'payload': serverTransactionPayload(
            id: saved.first.id,
            amountMinorUnits: 4242,
            description: 'edited elsewhere',
          ),
        },
        // A row it has never seen.
        {
          'entity': 'transaction',
          'id': 'TXFROMOTHERDEVICE00000000',
          'op': 'create',
          'version': 1,
          'payload': serverTransactionPayload(
            id: 'TXFROMOTHERDEVICE00000000',
            amountMinorUnits: 777,
            description: 'from the laptop',
          ),
        },
      ]);

      final outcome = await harness.engine.pull();

      expect(outcome.pulled, 2);
      expect(harness.store.records(LocalStore.entityTransaction).length, 4);

      final updated =
          harness.store.record(LocalStore.entityTransaction, saved.first.id)!;
      expect(MoneyCodec.decode(updated.data['amount']).minorUnits, 4242);
      expect(updated.version, 9);

      // Replaying the same page changes nothing.
      harness.server.pendingPullChanges.addAll([
        {
          'entity': 'transaction',
          'id': saved.first.id,
          'op': 'update',
          'version': 9,
          'payload': serverTransactionPayload(
            id: saved.first.id,
            amountMinorUnits: 4242,
            description: 'edited elsewhere',
          ),
        },
      ]);
      expect((await harness.engine.pull()).pulled, 0);
      expect(harness.store.records(LocalStore.entityTransaction).length, 4);
    });

    test('a delete from another device removes the row', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      final saved = await harness.recordExpenses(2);
      harness.offline = false;
      await harness.engine.push();

      harness.server.pendingPullChanges.add({
        'entity': 'transaction',
        'id': saved.first.id,
        'op': 'delete',
        'version': 2,
        'payload': const <String, Object?>{},
      });

      await harness.engine.pull();

      expect(harness.store.records(LocalStore.entityTransaction).length, 1);
      expect((await harness.repository.transactions()).length, 1);
    });

    test('an unpushed local edit is not overwritten by a pull', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      final id = (await harness.recordExpenses(1)).single.id;

      harness.offline = false;
      harness.server.pendingPullChanges.add({
        'entity': 'transaction',
        'id': id,
        'op': 'update',
        'version': 3,
        'payload': serverTransactionPayload(id: id, amountMinorUnits: 999999),
      });

      await harness.engine.pull();

      final local = harness.store.record(LocalStore.entityTransaction, id)!;
      expect(MoneyCodec.decode(local.data['amount']).minorUnits, 1000);
      expect(local.pendingSync, isTrue);
    });

    test('sync time comes from the server, not the device', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(1);
      harness.offline = false;

      await harness.engine.syncNow();

      expect(harness.store.lastServerTime, DateTime.utc(2026, 7, 25, 9));

      final pullRequest = harness.adapter.requests
          .lastWhere((request) => request.path.endsWith('/sync/pull'));
      expect(pullRequest.queryParameters['since'], '2026-07-25T09:00:00.000Z');
    });
  });

  group('persistence', () {
    test('a queued write survives a restart', () async {
      final harness = await SyncHarness.create();
      harness.offline = true;
      await harness.recordExpenses(4);

      final reopened = await LocalStore.open(harness.keyValueStore);

      expect(reopened.outbox.length, 4);
      expect(reopened.records(LocalStore.entityTransaction).length, 4);
      expect(reopened.outbox.first.payload['description'], 'خرید 0');
    });
  });
}
