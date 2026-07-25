import '../core/date/date_formatter.dart';
import '../core/money/allocation.dart';
import '../core/money/money.dart';

/// Mirrors `Check::DIRECTIONS` in the Banking module.
enum ChequeDirection { received, issued, guarantee }

/// Mirrors `Check::STATUSES`. `voided` renames the backend's `void`, which is a
/// Dart keyword.
enum ChequeStatus { draft, issued, inProgress, cleared, bounced, voided }

extension ChequeStatusX on ChequeStatus {
  /// The backend's `TERMINAL_STATUSES` — these never move money again, so a due
  /// date on them carries no urgency.
  bool get isTerminal =>
      this == ChequeStatus.bounced || this == ChequeStatus.voided;

  bool get isSettled => this == ChequeStatus.cleared || isTerminal;
}

/// How loudly a cheque's due date should read.
enum ChequeUrgency { overdue, dueSoon, upcoming, settled }

final class Cheque {
  const Cheque({
    required this.id,
    required this.number,
    required this.direction,
    required this.status,
    required this.amount,
    required this.dueDate,
    required this.partyName,
    this.bankName,
  });

  final String id;
  final String number;
  final ChequeDirection direction;
  final ChequeStatus status;
  final Money amount;
  final DateTime dueDate;
  final String partyName;
  final String? bankName;

  int daysUntilDue(DateTime now) => DateFormatter.daysUntil(dueDate, now);

  /// A week is the window in which a cheque still needs funding action; past
  /// the date it is a problem, not a reminder.
  ChequeUrgency urgencyOn(DateTime now) {
    if (status.isSettled) return ChequeUrgency.settled;
    final days = daysUntilDue(now);
    if (days < 0) return ChequeUrgency.overdue;
    if (days <= 7) return ChequeUrgency.dueSoon;
    return ChequeUrgency.upcoming;
  }
}

/// Mirrors `Loan::STATUSES`.
enum LoanStatus { active, closed, defaulted }

/// Mirrors `Loan` interest types: flat vs annuity.
enum LoanInterestType { simple, compound }

/// Mirrors `LoanInstallment::STATUSES`.
enum InstallmentStatus { due, paid, late, partial }

final class LoanInstallment {
  const LoanInstallment({
    required this.number,
    required this.dueDate,
    required this.principalPart,
    required this.interestPart,
    required this.paidAmount,
    required this.status,
  });

  final int number;
  final DateTime dueDate;
  final Money principalPart;
  final Money interestPart;
  final Money paidAmount;
  final InstallmentStatus status;

  Money get totalAmount => principalPart + interestPart;

  Money get remaining {
    final left = totalAmount - paidAmount;
    return left.isNegative ? Money(0, left.currency) : left;
  }
}

final class Loan {
  const Loan({
    required this.id,
    required this.title,
    required this.lender,
    required this.principal,
    required this.annualRatePercent,
    required this.interestType,
    required this.startDate,
    required this.status,
    required this.schedule,
  });

  final String id;
  final String title;
  final String lender;
  final Money principal;

  /// Nominal annual percent, e.g. 18.5. A rate is not an amount, so a double is
  /// the right type here — every derived *amount* stays an integer.
  final double annualRatePercent;
  final LoanInterestType interestType;
  final DateTime startDate;
  final LoanStatus status;
  final List<LoanInstallment> schedule;

  Money get totalPayable => schedule.fold(
        Money(0, principal.currency),
        (sum, i) => sum + i.totalAmount,
      );

  Money get totalInterest => totalPayable - principal;

  Money get paidToDate => schedule.fold(
        Money(0, principal.currency),
        (sum, i) => sum + i.paidAmount,
      );

  Money get outstanding => totalPayable - paidToDate;

  int get paidInstallments =>
      schedule.where((i) => i.status == InstallmentStatus.paid).length;

  /// Progress is measured in money repaid, not instalments ticked off — with an
  /// annuity schedule those two diverge sharply in the early years.
  double get progress {
    final total = totalPayable.minorUnits;
    if (total <= 0) return 0;
    return paidToDate.minorUnits / total;
  }

  LoanInstallment? nextDue() {
    for (final installment in schedule) {
      if (installment.status != InstallmentStatus.paid) return installment;
    }
    return null;
  }
}

/// Rebuilds the schedule the backend's `GenerateAmortizationSchedule` would.
///
/// Kept client-side so a seeded or offline loan shows the same table the server
/// would return, to the minor unit.
List<LoanInstallment> amortize({
  required Money principal,
  required double annualRatePercent,
  required int installmentsCount,
  required DateTime startDate,
  required LoanInterestType interestType,
  int paidCount = 0,
}) {
  if (installmentsCount < 1 || principal.minorUnits <= 0) return const [];

  final currency = principal.currency;
  final principalParts = <int>[];
  final interestParts = <int>[];

  if (interestType == LoanInterestType.simple || annualRatePercent <= 0) {
    final interestUnits = annualRatePercent <= 0
        ? 0
        : (principal.minorUnits *
                annualRatePercent /
                100 *
                installmentsCount /
                12)
            .round();
    for (final part
        in MoneyAllocation.evenly(principal, installmentsCount)) {
      principalParts.add(part.minorUnits);
    }
    for (final part in MoneyAllocation.evenly(
      Money(interestUnits, currency),
      installmentsCount,
    )) {
      interestParts.add(part.minorUnits);
    }
  } else {
    final periodic = annualRatePercent / 100 / 12;
    final factor = 1 - _pow(1 + periodic, -installmentsCount);
    final payment = (principal.minorUnits * periodic / factor).round();

    var balance = principal.minorUnits;
    for (var i = 0; i < installmentsCount; i++) {
      final interest = (balance * periodic).round();
      // The last instalment repays whatever is left, so the principal parts sum
      // to exactly the principal instead of leaving a rounding stub.
      final principalPart = i == installmentsCount - 1
          ? balance
          : (payment - interest).clamp(0, balance);
      principalParts.add(principalPart);
      interestParts.add(interest);
      balance -= principalPart;
    }
  }

  return [
    for (var i = 0; i < installmentsCount; i++)
      LoanInstallment(
        number: i + 1,
        dueDate: _addMonths(startDate, i + 1),
        principalPart: Money(principalParts[i], currency),
        interestPart: Money(interestParts[i], currency),
        paidAmount: i < paidCount
            ? Money(principalParts[i] + interestParts[i], currency)
            : Money(0, currency),
        status: i < paidCount ? InstallmentStatus.paid : InstallmentStatus.due,
      ),
  ];
}

double _pow(double base, int exponent) {
  var result = 1.0;
  final positive = exponent.abs();
  for (var i = 0; i < positive; i++) {
    result *= base;
  }
  return exponent < 0 ? 1 / result : result;
}

/// `addMonthsNoOverflow`: 31 January plus one month is 28 February, not 3 March.
DateTime _addMonths(DateTime from, int months) {
  final target = DateTime(from.year, from.month + months);
  final lastDay = DateTime(target.year, target.month + 1, 0).day;
  return DateTime(target.year, target.month, from.day.clamp(1, lastDay));
}
