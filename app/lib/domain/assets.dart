import '../core/date/date_formatter.dart';
import '../core/money/allocation.dart';
import '../core/money/money.dart';

/// Mirrors `Asset::KINDS`.
enum AssetKind { house, land, car, gold, watch, art, nft, other }

/// Mirrors `Asset::DEPRECIATION_METHODS`.
enum DepreciationMethod { none, linear, declining }

/// One row of the backend's `GET /assets/{id}/depreciation`.
final class DepreciationYear {
  const DepreciationYear({
    required this.year,
    required this.opening,
    required this.charge,
    required this.closing,
  });

  final int year;
  final Money opening;
  final Money charge;
  final Money closing;
}

enum InsuranceState { none, expired, expiringSoon, covered }

final class FixedAsset {
  const FixedAsset({
    required this.id,
    required this.name,
    required this.kind,
    required this.purchasePrice,
    required this.currentValue,
    required this.salvageValue,
    required this.purchaseDate,
    required this.method,
    this.ratePerMillion,
    this.usefulLifeYears,
    this.insuranceProvider,
    this.insuranceExpiresAt,
  });

  final String id;
  final String name;
  final AssetKind kind;
  final Money purchasePrice;
  final Money currentValue;
  final Money salvageValue;
  final DateTime purchaseDate;
  final DepreciationMethod method;

  /// The declining rate in millionths, matching the backend's integer-only
  /// `RATE_SCALE`. 200000 is 20 %/yr. Never a float, so the client and the
  /// server walk the same curve.
  final int? ratePerMillion;
  final int? usefulLifeYears;
  final String? insuranceProvider;
  final DateTime? insuranceExpiresAt;

  Money get changeSincePurchase => currentValue - purchasePrice;

  bool get hasGained => changeSincePurchase.minorUnits >= 0;

  Money get depreciableBase => purchasePrice - salvageValue;

  /// Insurance lapsing is the one thing on this screen that costs real money if
  /// missed, so it gets its own state rather than a formatted date.
  InsuranceState insuranceStateOn(DateTime now) {
    final expiry = insuranceExpiresAt;
    if (expiry == null) return InsuranceState.none;
    final days = DateFormatter.daysUntil(expiry, now);
    if (days < 0) return InsuranceState.expired;
    if (days <= 45) return InsuranceState.expiringSoon;
    return InsuranceState.covered;
  }

  int? insuranceDaysLeft(DateTime now) => insuranceExpiresAt == null
      ? null
      : DateFormatter.daysUntil(insuranceExpiresAt!, now);

  /// Reproduces `CalculateDepreciation::handle` — the book value never drops
  /// below salvage, and the charges over the full life sum to exactly
  /// `purchasePrice − salvageValue`.
  List<DepreciationYear> depreciationCurve() {
    final life = usefulLifeYears;
    if (method == DepreciationMethod.none || life == null || life < 1) {
      return const [];
    }

    final currency = purchasePrice.currency;
    final rows = <DepreciationYear>[];
    var book = purchasePrice.minorUnits;
    final salvage = salvageValue.minorUnits;

    if (method == DepreciationMethod.linear) {
      final charges = MoneyAllocation.evenly(depreciableBase, life);
      for (var i = 0; i < life; i++) {
        final charge = charges[i].minorUnits;
        rows.add(DepreciationYear(
          year: i + 1,
          opening: Money(book, currency),
          charge: Money(charge, currency),
          closing: Money(book - charge, currency),
        ),);
        book -= charge;
      }
      return rows;
    }

    final rate = ratePerMillion ?? 0;
    for (var i = 0; i < life; i++) {
      final byRate = book * rate ~/ 1000000;
      final toSalvage = book - salvage;
      // The final year lands exactly on salvage instead of leaving a stub the
      // asset would carry forever.
      final charge = i == life - 1
          ? toSalvage
          : (byRate < toSalvage ? byRate : toSalvage);
      rows.add(DepreciationYear(
        year: i + 1,
        opening: Money(book, currency),
        charge: Money(charge, currency),
        closing: Money(book - charge, currency),
      ),);
      book -= charge;
    }
    return rows;
  }
}
