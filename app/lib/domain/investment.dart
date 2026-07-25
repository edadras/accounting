import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../core/money/quantity.dart';

/// Mirrors `Investment::KINDS`.
enum InvestmentKind { gold, fx, stock, etf, crypto, realEstate, vehicle, startup }

/// Mirrors `InvestmentTransaction::ACTIONS`.
enum TradeAction { buy, sell, dividend, fee, split }

final class InvestmentTrade {
  const InvestmentTrade({
    required this.id,
    required this.action,
    required this.quantity,
    required this.unitPrice,
    required this.fee,
    required this.realizedProfit,
    required this.occurredAt,
  });

  final String id;
  final TradeAction action;
  final Quantity quantity;

  /// Per unit, like the backend's `price`.
  final Money unitPrice;
  final Money fee;
  final Money realizedProfit;
  final DateTime occurredAt;
}

/// One open position.
///
/// `costBasis` and `currentValue` arrive already multiplied out — the client
/// never re-derives them from a quantity times a price, because that is where a
/// floating-point cent goes missing.
final class InvestmentPosition {
  const InvestmentPosition({
    required this.id,
    required this.name,
    required this.kind,
    required this.quantity,
    required this.unitLabelKey,
    required this.avgBuyPrice,
    required this.currentPrice,
    required this.costBasis,
    required this.currentValue,
    required this.realizedProfit,
    required this.trades,
    this.symbol,
  });

  final String id;
  final String name;
  final InvestmentKind kind;
  final Quantity quantity;

  /// Translation key for the unit — "g", "shares", "coins".
  final String unitLabelKey;
  final Money avgBuyPrice;
  final Money currentPrice;
  final Money costBasis;
  final Money currentValue;
  final Money realizedProfit;
  final List<InvestmentTrade> trades;
  final String? symbol;

  Money get unrealizedProfit => currentValue - costBasis;

  Money get totalProfit => unrealizedProfit + realizedProfit;

  /// Percent, matching `InvestmentResource.roi`. A fully closed position has no
  /// basis left to measure against, so it reports zero rather than infinity.
  double get roiPercent {
    final basis = costBasis.minorUnits;
    if (basis == 0) return 0;
    return totalProfit.minorUnits / basis * 100;
  }

  bool get isUp => totalProfit.minorUnits >= 0;
}

final class Portfolio {
  const Portfolio({required this.positions, required this.currency});

  final List<InvestmentPosition> positions;
  final Currency currency;

  Money get totalValue => positions.fold(
        Money(0, currency),
        (sum, p) => sum + p.currentValue,
      );

  Money get totalCost => positions.fold(
        Money(0, currency),
        (sum, p) => sum + p.costBasis,
      );

  Money get totalProfit => positions.fold(
        Money(0, currency),
        (sum, p) => sum + p.totalProfit,
      );

  double get roiPercent {
    final basis = totalCost.minorUnits;
    if (basis == 0) return 0;
    return totalProfit.minorUnits / basis * 100;
  }
}
