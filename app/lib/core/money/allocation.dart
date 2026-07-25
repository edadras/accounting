import 'money.dart';

/// Splitting an amount between people without losing or inventing minor units.
///
/// Dividing 100.00 three ways gives 33.33 three times and leaves a stray unit.
/// Dropping it is a rounding bug that shows up as "the settlement doesn't add
/// up"; these helpers hand every leftover unit to the earliest shares, which is
/// what the backend's `Money::allocateEvenly` / `allocateByWeights` do, so the
/// client and the server agree to the unit.
abstract final class MoneyAllocation {
  static List<Money> evenly(Money total, int parts) {
    if (parts <= 0) return const [];
    return byWeights(total, List<int>.filled(parts, 1));
  }

  static List<Money> byWeights(Money total, List<int> weights) {
    if (weights.isEmpty) return const [];

    final safeWeights = [for (final w in weights) w < 0 ? 0 : w];
    final totalWeight = safeWeights.fold<int>(0, (sum, w) => sum + w);
    if (totalWeight == 0) return evenly(total, weights.length);

    final negative = total.isNegative;
    final units = total.minorUnits.abs();

    final shares = <int>[];
    var distributed = 0;
    for (final weight in safeWeights) {
      final share = units * weight ~/ totalWeight;
      shares.add(share);
      distributed += share;
    }

    // Hand the remainder out one unit at a time, heaviest weight first, so the
    // result is deterministic rather than dependent on iteration luck.
    var remainder = units - distributed;
    final order = List<int>.generate(shares.length, (i) => i)
      ..sort((a, b) {
        final byWeight = safeWeights[b].compareTo(safeWeights[a]);
        return byWeight != 0 ? byWeight : a.compareTo(b);
      });
    var cursor = 0;
    while (remainder > 0) {
      shares[order[cursor % order.length]] += 1;
      remainder -= 1;
      cursor += 1;
    }

    return [
      for (final share in shares)
        Money(negative ? -share : share, total.currency),
    ];
  }
}
