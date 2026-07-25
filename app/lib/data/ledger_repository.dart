import 'package:collection/collection.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../domain/analytics.dart';
import '../domain/entities.dart';

abstract interface class LedgerRepository {
  Future<List<Account>> accounts();
  Future<List<Category>> categories();
  Future<List<Transaction>> transactions({int limit});
  Future<Transaction> record(Transaction draft);
  Future<DashboardSummary> summary();
  Future<List<Budget>> budgets();
  Future<CashFlowReport> cashFlow({int months});
}

/// In-memory repository so the app runs, and can be demoed and golden-tested,
/// without a server.
///
/// It is deliberately the same shape as the eventual Isar-backed local store:
/// writes land locally first and are marked `pendingSync`, which is exactly the
/// offline-first contract in docs/09-sync-offline.md. Swapping this for the
/// real store is a one-line provider override.
final class InMemoryLedgerRepository implements LedgerRepository {
  InMemoryLedgerRepository({required this.baseCurrency}) {
    _seed();
  }

  final Currency baseCurrency;

  final List<Account> _accounts = [];
  final List<Category> _categories = [];
  final List<Transaction> _transactions = [];

  var _sequence = 0;

  String _nextId() => 'local-${(++_sequence).toString().padLeft(6, '0')}';

  void _seed() {
    _accounts.addAll([
      Account(
        id: 'acc-wallet',
        name: 'کیف پول',
        type: AccountType.cash,
        balance: Money(248050, baseCurrency),
        icon: 'wallet',
      ),
      Account(
        id: 'acc-bank',
        name: 'بانک ملت',
        type: AccountType.bank,
        balance: Money(1840000, baseCurrency),
        icon: 'bank',
      ),
      Account(
        id: 'acc-card',
        name: 'کارت اعتباری',
        type: AccountType.card,
        balance: Money(-92500, baseCurrency),
        icon: 'card',
      ),
    ]);

    _categories.addAll(const [
      Category(id: 'cat-food', name: 'خوراک', path: '/home/food', depth: 1, parentId: 'cat-home'),
      Category(id: 'cat-restaurant', name: 'رستوران', path: '/home/food/restaurant', depth: 2, parentId: 'cat-food'),
      Category(id: 'cat-transport', name: 'حمل و نقل', path: '/transport', depth: 0),
      Category(id: 'cat-bills', name: 'قبوض', path: '/home/bills', depth: 1, parentId: 'cat-home'),
      Category(id: 'cat-leisure', name: 'تفریح', path: '/leisure', depth: 0),
      Category(id: 'cat-salary', name: 'حقوق', path: '/salary', depth: 0),
    ]);

    final now = DateTime.now();
    void add(
      TransactionType type,
      int minorUnits,
      String categoryId,
      String description,
      int daysAgo,
      String accountId,
    ) {
      final amount = Money(minorUnits, baseCurrency);
      _transactions.add(Transaction(
        id: _nextId(),
        type: type,
        amount: amount,
        baseAmount: amount,
        accountId: accountId,
        categoryId: categoryId,
        occurredAt: now.subtract(Duration(days: daysAgo, hours: daysAgo * 3 % 20)),
        description: description,
      ),);
    }

    add(TransactionType.expense, 35000, 'cat-restaurant', 'شام', 0, 'acc-wallet');
    add(TransactionType.expense, 12500, 'cat-transport', 'تاکسی', 0, 'acc-wallet');
    add(TransactionType.expense, 84000, 'cat-food', 'سوپرمارکت', 1, 'acc-bank');
    add(TransactionType.expense, 42000, 'cat-bills', 'قبض برق', 3, 'acc-bank');
    add(TransactionType.expense, 68000, 'cat-restaurant', 'ناهار کاری', 4, 'acc-card');
    add(TransactionType.expense, 21000, 'cat-leisure', 'سینما', 6, 'acc-wallet');
    add(TransactionType.expense, 15500, 'cat-transport', 'بنزین', 8, 'acc-bank');
    add(TransactionType.income, 3200000, 'cat-salary', 'حقوق ماهانه', 10, 'acc-bank');
    add(TransactionType.expense, 96000, 'cat-food', 'خرید هفتگی', 12, 'acc-bank');

    _sort();
  }

  void _sort() =>
      _transactions.sort((a, b) => b.occurredAt.compareTo(a.occurredAt));

  @override
  Future<List<Account>> accounts() async => List.unmodifiable(_accounts);

  @override
  Future<List<Category>> categories() async => List.unmodifiable(_categories);

  @override
  Future<List<Transaction>> transactions({int limit = 100}) async =>
      List.unmodifiable(_transactions.take(limit));

  @override
  Future<Transaction> record(Transaction draft) async {
    // Written locally and marked pending — the UI never waits on a network
    // round trip to show the user their own money.
    final saved = draft.copyWith(pendingSync: true);
    _transactions.add(saved);
    _sort();

    final index = _accounts.indexWhere((a) => a.id == saved.accountId);
    if (index != -1) {
      final account = _accounts[index];
      final delta = switch (saved.type) {
        TransactionType.expense => -saved.amount.minorUnits,
        TransactionType.income => saved.amount.minorUnits,
        TransactionType.transfer => 0,
      };
      _accounts[index] = Account(
        id: account.id,
        name: account.name,
        type: account.type,
        balance: Money(account.balance.minorUnits + delta, account.balance.currency),
        icon: account.icon,
      );
    }

    return saved;
  }

  @override
  Future<DashboardSummary> summary() async {
    final now = DateTime.now();
    final startOfDay = DateTime(now.year, now.month, now.day);
    final startOfMonth = DateTime(now.year, now.month);

    var today = 0;
    var month = 0;
    var earned = 0;
    final perCategory = <String, int>{};

    for (final transaction in _transactions) {
      if (transaction.type == TransactionType.expense) {
        if (!transaction.occurredAt.isBefore(startOfDay)) {
          today += transaction.baseAmount.minorUnits;
        }
        if (!transaction.occurredAt.isBefore(startOfMonth)) {
          month += transaction.baseAmount.minorUnits;
          final key = transaction.categoryId ?? 'uncategorised';
          perCategory[key] = (perCategory[key] ?? 0) + transaction.baseAmount.minorUnits;
        }
      } else if (transaction.type == TransactionType.income &&
          !transaction.occurredAt.isBefore(startOfMonth)) {
        earned += transaction.baseAmount.minorUnits;
      }
    }

    final slices = perCategory.entries
        .map((entry) => CategorySlice(
              categoryId: entry.key,
              label: _categories
                      .where((c) => c.id == entry.key)
                      .map((c) => c.name)
                      .firstOrNull ??
                  entry.key,
              amount: Money(entry.value, baseCurrency),
            ),)
        .toList()
      ..sort((a, b) => b.amount.minorUnits.compareTo(a.amount.minorUnits));

    final netWorth = _accounts.fold<int>(0, (sum, a) => sum + a.balance.minorUnits);

    return DashboardSummary(
      netWorth: Money(netWorth, baseCurrency),
      spentToday: Money(today, baseCurrency),
      spentThisMonth: Money(month, baseCurrency),
      earnedThisMonth: Money(earned, baseCurrency),
      byCategory: slices,
      recent: _transactions.take(6).toList(),
    );
  }

  @override
  Future<List<Budget>> budgets() async {
    final summary = await this.summary();

    Money spentOn(String categoryId) {
      final slice = summary.byCategory
          .where((slice) => slice.categoryId == categoryId)
          .firstOrNull;
      return slice?.amount ?? Money(0, baseCurrency);
    }

    return [
      Budget(
        id: 'bud-overall',
        name: 'کل ماه',
        amount: Money(400000, baseCurrency),
        spent: summary.spentThisMonth,
        period: BudgetPeriod.monthly,
      ),
      Budget(
        id: 'bud-food',
        name: 'خوراک',
        amount: Money(150000, baseCurrency),
        spent: spentOn('cat-food') + spentOn('cat-restaurant'),
        period: BudgetPeriod.monthly,
        categoryId: 'cat-food',
        rollover: true,
        carriedOver: Money(18000, baseCurrency),
      ),
      Budget(
        id: 'bud-transport',
        name: 'حمل و نقل',
        amount: Money(40000, baseCurrency),
        spent: spentOn('cat-transport'),
        period: BudgetPeriod.monthly,
        categoryId: 'cat-transport',
      ),
      Budget(
        id: 'bud-leisure',
        name: 'تفریح',
        amount: Money(25000, baseCurrency),
        spent: spentOn('cat-leisure'),
        period: BudgetPeriod.monthly,
        categoryId: 'cat-leisure',
      ),
    ];
  }

  @override
  Future<CashFlowReport> cashFlow({int months = 6}) async {
    final now = DateTime.now();
    final buckets = <String, (int income, int expense)>{};

    for (var i = months - 1; i >= 0; i--) {
      final month = DateTime(now.year, now.month - i);
      buckets['${month.year}-${month.month.toString().padLeft(2, '0')}'] = (0, 0);
    }

    for (final transaction in _transactions) {
      final key = '${transaction.occurredAt.year}-'
          '${transaction.occurredAt.month.toString().padLeft(2, '0')}';
      final bucket = buckets[key];
      if (bucket == null) continue;

      // Transfers move the user's own money between their own accounts, so
      // they belong in neither column.
      buckets[key] = switch (transaction.type) {
        TransactionType.income => (
            bucket.$1 + transaction.baseAmount.minorUnits,
            bucket.$2,
          ),
        TransactionType.expense => (
            bucket.$1,
            bucket.$2 + transaction.baseAmount.minorUnits,
          ),
        TransactionType.transfer => bucket,
      };
    }

    var totalIncome = 0;
    var totalExpense = 0;
    final points = <TrendPoint>[];

    for (final entry in buckets.entries) {
      totalIncome += entry.value.$1;
      totalExpense += entry.value.$2;
      points.add(TrendPoint(
        label: entry.key.split('-').last,
        income: Money(entry.value.$1, baseCurrency),
        expense: Money(entry.value.$2, baseCurrency),
      ),);
    }

    return CashFlowReport(
      points: points,
      totalIncome: Money(totalIncome, baseCurrency),
      totalExpense: Money(totalExpense, baseCurrency),
    );
  }
}

final baseCurrencyProvider = Provider<Currency>((ref) => Currency.try_);

final ledgerRepositoryProvider = Provider<LedgerRepository>((ref) {
  return InMemoryLedgerRepository(baseCurrency: ref.watch(baseCurrencyProvider));
});

/// Bumped after every write so the dependent providers refetch.
final ledgerRevisionProvider = StateProvider<int>((ref) => 0);

final accountsProvider = FutureProvider<List<Account>>((ref) {
  ref.watch(ledgerRevisionProvider);
  return ref.watch(ledgerRepositoryProvider).accounts();
});

final categoriesProvider = FutureProvider<List<Category>>((ref) {
  return ref.watch(ledgerRepositoryProvider).categories();
});

final transactionsProvider = FutureProvider<List<Transaction>>((ref) {
  ref.watch(ledgerRevisionProvider);
  return ref.watch(ledgerRepositoryProvider).transactions();
});

final summaryProvider = FutureProvider<DashboardSummary>((ref) {
  ref.watch(ledgerRevisionProvider);
  return ref.watch(ledgerRepositoryProvider).summary();
});

final budgetsProvider = FutureProvider<List<Budget>>((ref) {
  ref.watch(ledgerRevisionProvider);
  return ref.watch(ledgerRepositoryProvider).budgets();
});

final cashFlowProvider = FutureProvider<CashFlowReport>((ref) {
  ref.watch(ledgerRevisionProvider);
  return ref.watch(ledgerRepositoryProvider).cashFlow();
});
