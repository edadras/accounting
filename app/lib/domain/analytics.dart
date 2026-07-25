import '../core/money/money.dart';

enum BudgetPeriod { monthly, yearly, custom }

final class Budget {
  const Budget({
    required this.id,
    required this.name,
    required this.amount,
    required this.spent,
    required this.period,
    this.categoryId,
    this.rollover = false,
    this.carriedOver,
  });

  final String id;
  final String name;
  final Money amount;
  final Money spent;
  final BudgetPeriod period;
  final String? categoryId;
  final bool rollover;

  /// Unspent remainder carried in from the previous period. Shown separately so
  /// the user can see why this month's ceiling is higher than the budget they
  /// set.
  final Money? carriedOver;

  Money get effectiveAmount =>
      carriedOver == null ? amount : amount + carriedOver!;

  Money get remaining => effectiveAmount - spent;

  double get progress {
    final ceiling = effectiveAmount.minorUnits;
    if (ceiling <= 0) return 0;
    return spent.minorUnits / ceiling;
  }

  bool get isBreached => spent.minorUnits > effectiveAmount.minorUnits;

  /// 80% is the warning line; the roadmap alerts at 80 and 100.
  bool get isNearLimit => !isBreached && progress >= 0.8;
}

/// One bucket in a trend chart — a day, week, month or year.
final class TrendPoint {
  const TrendPoint({
    required this.label,
    required this.income,
    required this.expense,
  });

  final String label;
  final Money income;
  final Money expense;

  int get net => income.minorUnits - expense.minorUnits;
}

final class CashFlowReport {
  const CashFlowReport({
    required this.points,
    required this.totalIncome,
    required this.totalExpense,
  });

  final List<TrendPoint> points;
  final Money totalIncome;
  final Money totalExpense;

  Money get net => totalIncome - totalExpense;

  /// Largest single bar in either direction — the scale every bar is drawn
  /// against, so income and expense stay visually comparable.
  int get peak {
    var peak = 0;
    for (final point in points) {
      if (point.income.minorUnits > peak) peak = point.income.minorUnits;
      if (point.expense.minorUnits > peak) peak = point.expense.minorUnits;
    }
    return peak;
  }
}
