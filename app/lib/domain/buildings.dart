import '../core/money/currency.dart';
import '../core/money/money.dart';

/// Mirrors `Building::FORMULAS`.
enum ChargeFormula { fixed, perArea, perResident, mixed }

/// Mirrors `BuildingCharge::STATUSES`.
enum ChargeStatus { unpaid, partial, paid }

final class BuildingUnit {
  const BuildingUnit({
    required this.id,
    required this.unitNo,
    required this.floor,
    required this.areaSquareMetres,
    required this.residentsCount,
    required this.isOccupied,
    this.ownerName,
    this.tenantName,
  });

  final String id;
  final String unitNo;
  final int floor;
  final int areaSquareMetres;
  final int residentsCount;
  final bool isOccupied;
  final String? ownerName;
  final String? tenantName;

  /// Whoever actually gets the bill.
  String? get residentLabel => tenantName ?? ownerName;
}

final class BuildingCharge {
  const BuildingCharge({
    required this.id,
    required this.unitId,
    required this.period,
    required this.amount,
    required this.paid,
    required this.status,
    this.dueDate,
  });

  final String id;
  final String unitId;

  /// `YYYY-MM`, exactly as the backend stores it.
  final String period;
  final Money amount;
  final Money paid;
  final ChargeStatus status;
  final DateTime? dueDate;

  Money get remaining {
    final left = amount - paid;
    return left.isNegative ? Money(0, left.currency) : left;
  }
}

/// One line of the backend's debtors report.
final class UnitDebt {
  const UnitDebt({
    required this.unit,
    required this.owed,
    required this.chargesCount,
    required this.oldestPeriod,
  });

  final BuildingUnit unit;
  final Money owed;
  final int chargesCount;
  final String oldestPeriod;
}

final class Building {
  const Building({
    required this.id,
    required this.name,
    required this.address,
    required this.formula,
    required this.currency,
    required this.fundBalance,
    required this.units,
    required this.charges,
    required this.currentPeriod,
  });

  final String id;
  final String name;
  final String address;
  final ChargeFormula formula;
  final Currency currency;
  final Money fundBalance;
  final List<BuildingUnit> units;
  final List<BuildingCharge> charges;
  final String currentPeriod;

  List<BuildingCharge> chargesFor(String unitId) =>
      [for (final c in charges) if (c.unitId == unitId) c];

  BuildingCharge? currentChargeFor(String unitId) {
    for (final charge in charges) {
      if (charge.unitId == unitId && charge.period == currentPeriod) {
        return charge;
      }
    }
    return null;
  }

  ChargeStatus statusFor(String unitId) =>
      currentChargeFor(unitId)?.status ?? ChargeStatus.unpaid;

  Money get billed => charges
      .where((c) => c.period == currentPeriod)
      .fold(Money(0, currency), (sum, c) => sum + c.amount);

  Money get collected => charges
      .where((c) => c.period == currentPeriod)
      .fold(Money(0, currency), (sum, c) => sum + c.paid);

  Money get outstanding =>
      charges.fold(Money(0, currency), (sum, c) => sum + c.remaining);

  double get collectionRate {
    final total = billed.minorUnits;
    if (total <= 0) return 0;
    return collected.minorUnits / total;
  }

  /// Largest debt first — the list exists to answer "who do I call today".
  List<UnitDebt> debtors() {
    final rows = <UnitDebt>[];

    for (final unit in units) {
      final owing = [
        for (final charge in charges)
          if (charge.unitId == unit.id && charge.remaining.minorUnits > 0)
            charge,
      ]..sort((a, b) => a.period.compareTo(b.period));
      if (owing.isEmpty) continue;

      rows.add(UnitDebt(
        unit: unit,
        owed: owing.fold(Money(0, currency), (sum, c) => sum + c.remaining),
        chargesCount: owing.length,
        oldestPeriod: owing.first.period,
      ),);
    }

    return rows
      ..sort((a, b) {
        final byAmount = b.owed.minorUnits.compareTo(a.owed.minorUnits);
        return byAmount != 0
            ? byAmount
            : a.unit.unitNo.compareTo(b.unit.unitNo);
      });
  }
}
