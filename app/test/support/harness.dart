import 'package:dio/dio.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/data/api_ledger_repository.dart';
import 'package:finora/data/local/key_value_store.dart';
import 'package:finora/data/local/local_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/remote/money_codec.dart';
import 'package:finora/domain/entities.dart';
import 'package:finora/sync/sync_api.dart';
import 'package:finora/sync/sync_engine.dart';

import 'fake_server.dart';

/// A whole offline stack wired to a fake server, assembled the way
/// `FinoraBackend` assembles the real one.
final class SyncHarness {
  SyncHarness._({
    required this.store,
    required this.repository,
    required this.engine,
    required this.server,
    required this.adapter,
    required this.keyValueStore,
  });

  static const accountId = '01JACCOUNT000000000000000';
  static const categoryId = '01JCATEGORY00000000000000';

  final LocalStore store;
  final ApiLedgerRepository repository;
  final SyncEngine engine;
  final FakeSyncServer server;
  final MockAdapter adapter;

  /// Exposed so a test can reopen the store and prove the queue survived.
  final MemoryKeyValueStore keyValueStore;

  /// Flipped by a test to take the network away mid-run.
  bool offline = false;

  /// When true the server applies the batch but the answer never arrives —
  /// the interrupted-push case idempotency exists for.
  bool dropResponses = false;

  /// Takes over the response entirely, for testing raw status codes.
  ResponseBody Function(RequestOptions options)? respond;

  static Future<SyncHarness> create({
    Currency baseCurrency = Currency.irr,
    int batchSize = 50,
  }) async {
    final server = FakeSyncServer();
    late final SyncHarness harness;

    final adapter = MockAdapter((options) {
      if (harness.offline) throw connectionFailure(options);
      final override = harness.respond;
      if (override != null) return override(options);
      final response = server.handle(options);
      if (harness.dropResponses) throw connectionFailure(options);
      return response;
    });

    final client = ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1, wait: _noWait),
    );

    final keyValueStore = MemoryKeyValueStore();
    final store = await LocalStore.open(keyValueStore);

    harness = SyncHarness._(
      store: store,
      repository: ApiLedgerRepository(store: store, baseCurrency: baseCurrency),
      engine: SyncEngine(
        api: SyncApi(client),
        store: store,
        deviceId: '01JDEVICE0000000000000000',
        batchSize: batchSize,
      ),
      server: server,
      adapter: adapter,
      keyValueStore: keyValueStore,
    );

    await harness._seedReferenceData(baseCurrency);
    return harness;
  }

  Future<void> _seedReferenceData(Currency baseCurrency) async {
    await store.put(
      LocalStore.entityAccount,
      LocalRecord(
        id: accountId,
        version: 1,
        data: {
          'id': accountId,
          'name': 'کیف پول',
          'type': 'cash',
          'currency': baseCurrency.code,
          'balance': MoneyCodec.encode(Money(1000000, baseCurrency)),
        },
      ),
    );

    await store.put(
      LocalStore.entityCategory,
      const LocalRecord(
        id: categoryId,
        version: 1,
        data: {
          'id': categoryId,
          'name': 'خوراک',
          'path': '/food',
          'depth': 0,
        },
      ),
    );
  }

  /// Records [count] expenses the way the quick-add sheet does: id minted on
  /// the device, amount in minor units.
  Future<List<Transaction>> recordExpenses(
    int count, {
    Currency currency = Currency.irr,
  }) async {
    final saved = <Transaction>[];

    for (var i = 0; i < count; i++) {
      saved.add(await repository.record(Transaction(
        id: 'TX${i.toString().padLeft(24, '0')}',
        type: TransactionType.expense,
        amount: Money(1000 + i, currency),
        baseAmount: Money(1000 + i, currency),
        accountId: accountId,
        categoryId: categoryId,
        occurredAt: DateTime.utc(2026, 7, 20, 12).add(Duration(minutes: i)),
        description: 'خرید $i',
      ),),);
    }

    return saved;
  }
}

Future<void> _noWait(Duration duration) async {}

/// Convenience for building the payload a server would hold for a record.
Map<String, Object?> serverTransactionPayload({
  required String id,
  required int amountMinorUnits,
  Currency currency = Currency.irr,
  String description = 'خرید',
  String accountId = SyncHarness.accountId,
  String categoryId = SyncHarness.categoryId,
  String occurredAt = '2026-07-20T12:00:00.000Z',
}) =>
    {
      'id': id,
      'type': 'expense',
      'amount': MoneyCodec.encode(Money(amountMinorUnits, currency)),
      'base': MoneyCodec.encode(Money(amountMinorUnits, currency)),
      'account_id': accountId,
      'counter_account_id': null,
      'category_id': categoryId,
      'occurred_at': occurredAt,
      'description': description,
      'payee': null,
    };

/// Lets a test hand the adapter a bare response.
ResponseBody jsonResponse(Object? body, {int status = 200}) =>
    MockAdapter.json(body, status: status);
