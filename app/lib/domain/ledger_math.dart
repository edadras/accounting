import '../core/money/currency.dart';
import '../core/money/money.dart';
import 'analytics.dart';
import 'entities.dart';

/// Dashboard and report arithmetic over a list of transactions.
///
/// Pure and integer-only, so the offline repository computes exactly what the
/// server would — every total is a sum of minor units, never of doubles.
abstract final class LedgerMath {
  static DashboardSummary summarise({
    required List<Transaction> transactions,
    required List<Account> accounts,
    required List<Category> categories,
    required Currency baseCurrency,
    required DateTime now,
  }) {
    final startOfDay = DateTime(now.year, now.month, now.day);
    final startOfMonth = DateTime(now.year, now.month);

    var today = 0;
    var month = 0;
    var earned = 0;
    final perCategory = <String, int>{};

    for (final transaction in transactions) {
      final units = transaction.baseAmount.minorUnits;

      if (transaction.type == TransactionType.expense) {
        if (!transaction.occurredAt.isBefore(startOfDay)) today += units;
        if (!transaction.occurredAt.isBefore(startOfMonth)) {
          month += units;
          final key = transaction.categoryId ?? 'uncategorised';
          perCategory[key] = (perCategory[key] ?? 0) + units;
        }
      } else if (transaction.type == TransactionType.income &&
          !transaction.occurredAt.isBefore(startOfMonth)) {
        earned += units;
      }
    }

    final names = {for (final category in categories) category.id: category.name};

    final slices = [
      for (final entry in perCategory.entries)
        CategorySlice(
          categoryId: entry.key,
          label: names[entry.key] ?? entry.key,
          amount: Money(entry.value, baseCurrency),
        ),
    ]..sort((a, b) => b.amount.minorUnits.compareTo(a.amount.minorUnits));

    // Accounts may be in different currencies; only same-currency balances can
    // be summed without a rate, so foreign ones are left out rather than
    // silently mis-added.
    final netWorth = accounts
        .where((account) => account.balance.currency == baseCurrency)
        .fold<int>(0, (sum, account) => sum + account.balance.minorUnits);

    return DashboardSummary(
      netWorth: Money(netWorth, baseCurrency),
      spentToday: Money(today, baseCurrency),
      spentThisMonth: Money(month, baseCurrency),
      earnedThisMonth: Money(earned, baseCurrency),
      byCategory: slices,
      recent: transactions.take(6).toList(),
    );
  }

  static CashFlowReport cashFlow({
    required List<Transaction> transactions,
    required Currency baseCurrency,
    required int months,
    required DateTime now,
  }) {
    final buckets = <String, (int income, int expense)>{};

    for (var i = months - 1; i >= 0; i--) {
      final month = DateTime(now.year, now.month - i);
      buckets[_bucketKey(month.year, month.month)] = (0, 0);
    }

    for (final transaction in transactions) {
      final key = _bucketKey(
        transaction.occurredAt.year,
        transaction.occurredAt.month,
      );
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

  /// The balance change one transaction makes to the account it belongs to.
  static int accountDelta(Transaction transaction) => switch (transaction.type) {
        TransactionType.expense => -transaction.amount.minorUnits,
        TransactionType.income => transaction.amount.minorUnits,
        TransactionType.transfer => 0,
      };

  static String _bucketKey(int year, int month) =>
      '$year-${month.toString().padLeft(2, '0')}';
}
