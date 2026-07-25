import '../../core/money/currency.dart';
import '../../core/money/money.dart';
import '../../domain/analytics.dart';
import '../../domain/entities.dart';
import 'money_codec.dart';

/// Maps the server's resource JSON onto the domain model and back.
///
/// Unknown fields are carried through untouched rather than dropped: the API
/// contract (docs/05-api-conventions.md §12) says a new field is not a breaking
/// change, so an old build must not erase data a newer server sent.
abstract final class TransactionCodec {
  static Transaction decode(
    Map<String, Object?> json, {
    required Currency baseCurrency,
    bool pendingSync = false,
  }) {
    final amount = MoneyCodec.decode(
      json['amount'],
      fallbackCurrency: baseCurrency,
    );

    return Transaction(
      id: json['id']! as String,
      type: _typeFrom(json['type']),
      amount: amount,
      baseAmount: MoneyCodec.tryDecode(
            json['base'],
            fallbackCurrency: baseCurrency,
          ) ??
          amount,
      accountId: json['account_id'] as String? ?? '',
      categoryId: json['category_id'] as String?,
      occurredAt: DateTime.tryParse(json['occurred_at'] as String? ?? '')
              ?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      description: json['description'] as String?,
      payee: json['payee'] as String?,
      pendingSync: pendingSync,
    );
  }

  /// The canonical record shape — the same one the server returns, which is
  /// what makes a field-by-field conflict comparison meaningful.
  static Map<String, Object?> encode(Transaction transaction) => {
        'id': transaction.id,
        'type': transaction.type.name,
        'amount': MoneyCodec.encode(transaction.amount),
        'base': MoneyCodec.encode(transaction.baseAmount),
        'account_id': transaction.accountId,
        'counter_account_id': null,
        'category_id': transaction.categoryId,
        'occurred_at': transaction.occurredAt.toUtc().toIso8601String(),
        'description': transaction.description,
        'payee': transaction.payee,
      };

  /// Flattens a record into what `POST /api/v1/transactions` validates: an
  /// integer minor-unit `amount` plus a currency code. The endpoint refuses a
  /// float, which is exactly the guarantee we want.
  static Map<String, Object?> toWriteBody(Map<String, Object?> record) {
    final amount = record['amount'];
    final base = record['base'];

    return {
      'id': record['id'],
      'type': record['type'],
      'account_id': record['account_id'],
      if (record['counter_account_id'] != null)
        'counter_account_id': record['counter_account_id'],
      if (record['category_id'] != null) 'category_id': record['category_id'],
      'amount': amount is Map ? amount['value'] : amount,
      'currency': amount is Map ? amount['currency'] : record['currency'],
      if (base is Map && base['fx_rate'] != null) 'fx_rate': base['fx_rate'],
      if (record['occurred_at'] != null) 'occurred_at': record['occurred_at'],
      if (record['description'] != null) 'description': record['description'],
      if (record['payee'] != null) 'payee': record['payee'],
    };
  }

  static TransactionType _typeFrom(Object? raw) => switch (raw) {
        'income' => TransactionType.income,
        'transfer' => TransactionType.transfer,
        _ => TransactionType.expense,
      };
}

abstract final class AccountCodec {
  static Account decode(
    Map<String, Object?> json, {
    required Currency baseCurrency,
  }) =>
      Account(
        id: json['id']! as String,
        name: json['name'] as String? ?? '',
        type: _typeFrom(json['type']),
        balance: MoneyCodec.tryDecode(
              json['balance'],
              fallbackCurrency: baseCurrency,
            ) ??
            Money(0, Currency.byCode(json['currency'] as String? ?? '') ?? baseCurrency),
        icon: json['icon'] as String?,
      );

  static Map<String, Object?> encode(Account account) => {
        'id': account.id,
        'name': account.name,
        'type': _nameFor(account.type),
        'currency': account.balance.currency.code,
        'balance': MoneyCodec.encode(account.balance),
        'icon': account.icon,
      };

  static AccountType _typeFrom(Object? raw) => switch (raw) {
        'bank' => AccountType.bank,
        'card' => AccountType.card,
        'wallet' => AccountType.wallet,
        'fund' => AccountType.fund,
        'petty_cash' => AccountType.pettyCash,
        'crypto' => AccountType.crypto,
        'gold' => AccountType.gold,
        'fx' => AccountType.fx,
        _ => AccountType.cash,
      };

  static String _nameFor(AccountType type) => switch (type) {
        AccountType.pettyCash => 'petty_cash',
        _ => type.name,
      };
}

abstract final class CategoryCodec {
  static Category decode(Map<String, Object?> json) => Category(
        id: json['id']! as String,
        name: json['name'] as String? ?? '',
        path: json['path'] as String? ?? '',
        depth: json['depth'] as int? ?? 0,
        parentId: json['parent_id'] as String?,
        icon: json['icon'] as String?,
      );

  static Map<String, Object?> encode(Category category) => {
        'id': category.id,
        'parent_id': category.parentId,
        'name': category.name,
        'path': category.path,
        'depth': category.depth,
        'icon': category.icon,
      };
}

abstract final class BudgetCodec {
  static Budget decode(
    Map<String, Object?> json, {
    required Currency baseCurrency,
    Money? spent,
  }) {
    final amount = MoneyCodec.decode(
      json['amount'],
      fallbackCurrency: baseCurrency,
    );

    return Budget(
      id: json['id']! as String,
      name: json['name'] as String? ?? '',
      amount: amount,
      spent: spent ??
          MoneyCodec.tryDecode(json['spent'], fallbackCurrency: baseCurrency) ??
          Money(0, amount.currency),
      period: switch (json['period']) {
        'yearly' => BudgetPeriod.yearly,
        'custom' => BudgetPeriod.custom,
        _ => BudgetPeriod.monthly,
      },
      categoryId: json['category_id'] as String?,
      rollover: json['rollover'] as bool? ?? false,
      carriedOver: MoneyCodec.tryDecode(
        json['carried_over'],
        fallbackCurrency: baseCurrency,
      ),
    );
  }
}
