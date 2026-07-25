import 'package:ulid/ulid.dart';

import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../domain/analytics.dart';
import '../domain/entities.dart';
import '../domain/ledger_math.dart';
import 'ledger_repository.dart';
import 'local/local_store.dart';
import 'local/outbox.dart';
import 'remote/entity_codec.dart';
import 'remote/money_codec.dart';

/// The offline-first [LedgerRepository].
///
/// Reads come from [LocalStore] and writes go into it plus the outbox, so the
/// UI never waits on a network round trip to show the user their own money
/// (docs/09-sync-offline.md §2). The sync engine drains the outbox later; this
/// class knows nothing about HTTP.
final class ApiLedgerRepository implements LedgerRepository {
  ApiLedgerRepository({
    required this.store,
    required this.baseCurrency,
    String Function()? idFactory,
    DateTime Function()? clock,
  })  : _newId = idFactory ?? _ulid,
        _now = clock ?? DateTime.now;

  final LocalStore store;
  final Currency baseCurrency;
  final String Function() _newId;
  final DateTime Function() _now;

  static String _ulid() => Ulid().toString();

  @override
  Future<List<Account>> accounts() async => [
        for (final record in store.records(LocalStore.entityAccount))
          AccountCodec.decode(record.data, baseCurrency: baseCurrency),
      ];

  @override
  Future<List<Category>> categories() async => [
        for (final record in store.records(LocalStore.entityCategory))
          CategoryCodec.decode(record.data),
      ];

  @override
  Future<List<Transaction>> transactions({int limit = 100}) async =>
      _transactions().take(limit).toList();

  List<Transaction> _transactions() => [
        for (final record in store.records(LocalStore.entityTransaction))
          TransactionCodec.decode(
            record.data,
            baseCurrency: baseCurrency,
            pendingSync: record.pendingSync,
          ),
      ]..sort((a, b) => b.occurredAt.compareTo(a.occurredAt));

  @override
  Future<Transaction> record(Transaction draft) async {
    // The id is minted on the device, so this record already holds its final
    // identity even though the server has never heard of it.
    final id = draft.id.isEmpty ? _newId() : draft.id;
    final saved = Transaction(
      id: id,
      type: draft.type,
      amount: draft.amount,
      baseAmount: draft.baseAmount,
      accountId: draft.accountId,
      categoryId: draft.categoryId,
      occurredAt: draft.occurredAt,
      description: draft.description,
      payee: draft.payee,
      pendingSync: true,
    );

    final payload = TransactionCodec.encode(saved);

    await store.put(
      LocalStore.entityTransaction,
      LocalRecord(id: id, data: payload, pendingSync: true),
    );

    await store.enqueue(OutboxEntry(
      id: _newId(),
      entity: LocalStore.entityTransaction,
      entityId: id,
      op: SyncOp.create,
      payload: payload,
      baseVersion: 0,
      createdAt: _now().toUtc(),
    ),);

    await _applyToAccountBalance(saved);

    return saved;
  }

  /// A local projection only: the server recomputes balances from entries, and
  /// the next pull replaces this with the authoritative figure.
  Future<void> _applyToAccountBalance(Transaction transaction) async {
    final existing =
        store.record(LocalStore.entityAccount, transaction.accountId);
    if (existing == null) return;

    final balance = MoneyCodec.tryDecode(
      existing.data['balance'],
      fallbackCurrency: baseCurrency,
    );
    if (balance == null || balance.currency != transaction.amount.currency) {
      return;
    }

    await store.put(
      LocalStore.entityAccount,
      existing.copyWith(
        data: {
          ...existing.data,
          'balance': MoneyCodec.encode(
            Money(
              balance.minorUnits + LedgerMath.accountDelta(transaction),
              balance.currency,
            ),
          ),
        },
      ),
    );
  }

  @override
  Future<DashboardSummary> summary() async => LedgerMath.summarise(
        transactions: _transactions(),
        accounts: await accounts(),
        categories: await categories(),
        baseCurrency: baseCurrency,
        now: _now(),
      );

  @override
  Future<List<Budget>> budgets() async {
    final stored = store.records(LocalStore.entityBudget);
    if (stored.isEmpty) return const [];

    final summary = await this.summary();

    Money spentOn(String? categoryId) {
      if (categoryId == null) return summary.spentThisMonth;
      var total = 0;
      for (final slice in summary.byCategory) {
        if (slice.categoryId == categoryId) total += slice.amount.minorUnits;
      }
      return Money(total, baseCurrency);
    }

    return [
      for (final record in stored)
        BudgetCodec.decode(
          record.data,
          baseCurrency: baseCurrency,
          spent: spentOn(record.data['category_id'] as String?),
        ),
    ];
  }

  @override
  Future<CashFlowReport> cashFlow({int months = 6}) async =>
      LedgerMath.cashFlow(
        transactions: _transactions(),
        baseCurrency: baseCurrency,
        months: months,
        now: _now(),
      );
}
