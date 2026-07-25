import '../core/money/currency.dart';
import '../core/money/money.dart';

enum TransactionType { income, expense, transfer }

enum AccountType { cash, bank, card, wallet, fund, pettyCash, crypto, gold, fx }

final class Workspace {
  const Workspace({
    required this.id,
    required this.name,
    required this.type,
    required this.baseCurrency,
    this.icon,
  });

  final String id;
  final String name;
  final String type;
  final Currency baseCurrency;
  final String? icon;
}

final class Account {
  const Account({
    required this.id,
    required this.name,
    required this.type,
    required this.balance,
    this.icon,
  });

  final String id;
  final String name;
  final AccountType type;
  final Money balance;
  final String? icon;

  Currency get currency => balance.currency;
}

final class Category {
  const Category({
    required this.id,
    required this.name,
    required this.path,
    required this.depth,
    this.parentId,
    this.icon,
  });

  final String id;
  final String name;
  final String path;
  final int depth;
  final String? parentId;
  final String? icon;

  bool get isRoot => parentId == null;
}

final class Transaction {
  const Transaction({
    required this.id,
    required this.type,
    required this.amount,
    required this.baseAmount,
    required this.accountId,
    required this.occurredAt,
    this.categoryId,
    this.description,
    this.payee,
    this.pendingSync = false,
  });

  final String id;
  final TransactionType type;
  final Money amount;
  final Money baseAmount;
  final String accountId;
  final String? categoryId;
  final DateTime occurredAt;
  final String? description;
  final String? payee;

  /// True while the record still sits in the local outbox. The UI marks these
  /// so the user can see what has not reached the server yet.
  final bool pendingSync;

  /// Signed against the base currency: expenses reduce, income adds. Transfers
  /// are zero — moving your own money is not spending.
  int get signedBaseMinorUnits => switch (type) {
        TransactionType.expense => -baseAmount.minorUnits,
        TransactionType.income => baseAmount.minorUnits,
        TransactionType.transfer => 0,
      };

  Transaction copyWith({bool? pendingSync}) => Transaction(
        id: id,
        type: type,
        amount: amount,
        baseAmount: baseAmount,
        accountId: accountId,
        categoryId: categoryId,
        occurredAt: occurredAt,
        description: description,
        payee: payee,
        pendingSync: pendingSync ?? this.pendingSync,
      );
}

/// What the dashboard needs, computed in one pass over the ledger rather than
/// six separate queries.
final class DashboardSummary {
  const DashboardSummary({
    required this.netWorth,
    required this.spentToday,
    required this.spentThisMonth,
    required this.earnedThisMonth,
    required this.byCategory,
    required this.recent,
  });

  final Money netWorth;
  final Money spentToday;
  final Money spentThisMonth;
  final Money earnedThisMonth;

  /// Category id → total spent, already sorted descending.
  final List<CategorySlice> byCategory;
  final List<Transaction> recent;

  double get topCategoryShare {
    if (byCategory.isEmpty) return 0;
    final total = byCategory.fold<int>(0, (sum, slice) => sum + slice.amount.minorUnits);
    if (total == 0) return 0;
    return byCategory.first.amount.minorUnits / total;
  }
}

final class CategorySlice {
  const CategorySlice({
    required this.categoryId,
    required this.label,
    required this.amount,
  });

  final String categoryId;
  final String label;
  final Money amount;
}
