import '../core/money/allocation.dart';
import '../core/money/currency.dart';
import '../core/money/money.dart';

/// Mirrors `SplitShare::MODES`.
enum SplitMode { equal, percent, weight, exact }

final class TripMember {
  const TripMember({required this.id, required this.name, this.weight = 1});

  final String id;
  final String name;
  final int weight;
}

final class SplitExpense {
  const SplitExpense({
    required this.id,
    required this.title,
    required this.amount,
    required this.payerId,
    required this.participantIds,
    required this.occurredAt,
    this.mode = SplitMode.equal,
    this.exactShares = const {},
    this.latitude,
    this.longitude,
  });

  final String id;
  final String title;
  final Money amount;
  final String payerId;
  final List<String> participantIds;
  final DateTime occurredAt;
  final SplitMode mode;

  /// Member id → minor units, used only when [mode] is [SplitMode.exact].
  final Map<String, int> exactShares;

  /// Where the money was spent, as `split_expenses.latitude` /
  /// `.longitude` hold it. Both nullable and always read as a pair: half a
  /// coordinate places nothing, so [hasLocation] is the only way anything
  /// should ask.
  final double? latitude;
  final double? longitude;

  bool get hasLocation => latitude != null && longitude != null;

  /// Member id → what that member owes for this expense.
  ///
  /// Allocated rather than divided so the shares add back up to [amount] to the
  /// minor unit — the property the whole settlement rests on.
  Map<String, Money> shares(List<TripMember> members) {
    if (participantIds.isEmpty) return const {};

    final weights = switch (mode) {
      SplitMode.equal => List<int>.filled(participantIds.length, 1),
      SplitMode.weight => [
          for (final id in participantIds)
            members
                .where((m) => m.id == id)
                .map((m) => m.weight)
                .followedBy(const [1])
                .first,
        ],
      SplitMode.percent || SplitMode.exact => [
          for (final id in participantIds) exactShares[id] ?? 0,
        ],
    };

    final allocated = MoneyAllocation.byWeights(amount, weights);
    return {
      for (var i = 0; i < participantIds.length; i++)
        participantIds[i]: allocated[i],
    };
  }
}

/// What one member is up or down across the whole trip.
final class MemberBalance {
  const MemberBalance({
    required this.member,
    required this.paid,
    required this.owed,
  });

  final TripMember member;

  /// What this member laid out on everyone's behalf.
  final Money paid;

  /// This member's share of the trip.
  final Money owed;

  /// Positive means the member is owed money back.
  Money get balance => paid - owed;

  bool get isCreditor => balance.minorUnits > 0;
  bool get isDebtor => balance.minorUnits < 0;
}

/// "Mina pays Sara ₺2,700" — the whole point of the travel module.
final class SettlementTransfer {
  const SettlementTransfer({
    required this.from,
    required this.to,
    required this.amount,
  });

  final TripMember from;
  final TripMember to;
  final Money amount;
}

final class Trip {
  const Trip({
    required this.id,
    required this.name,
    required this.destination,
    required this.startsAt,
    required this.endsAt,
    required this.baseCurrency,
    required this.members,
    required this.expenses,
  });

  final String id;
  final String name;
  final String destination;
  final DateTime startsAt;
  final DateTime endsAt;
  final Currency baseCurrency;
  final List<TripMember> members;
  final List<SplitExpense> expenses;

  Money get total => expenses.fold(
        Money(0, baseCurrency),
        (sum, e) => sum + e.amount,
      );

  Money get perHead {
    if (members.isEmpty) return Money(0, baseCurrency);
    return Money(total.minorUnits ~/ members.length, baseCurrency);
  }

  TripMember? memberById(String id) {
    for (final member in members) {
      if (member.id == id) return member;
    }
    return null;
  }

  List<MemberBalance> balances() {
    final paid = <String, int>{for (final m in members) m.id: 0};
    final owed = <String, int>{for (final m in members) m.id: 0};

    for (final expense in expenses) {
      paid[expense.payerId] =
          (paid[expense.payerId] ?? 0) + expense.amount.minorUnits;
      expense.shares(members).forEach((memberId, share) {
        owed[memberId] = (owed[memberId] ?? 0) + share.minorUnits;
      });
    }

    return [
      for (final member in members)
        MemberBalance(
          member: member,
          paid: Money(paid[member.id] ?? 0, baseCurrency),
          owed: Money(owed[member.id] ?? 0, baseCurrency),
        ),
    ]..sort((a, b) => b.balance.minorUnits.compareTo(a.balance.minorUnits));
  }

  /// Greedy debt simplification, matching `SettleTrip::preview`.
  ///
  /// Repeatedly pays the largest debt to the largest credit. That yields at
  /// most n−1 transfers, which is what makes the screen readable: four people
  /// settle in three payments instead of twelve.
  List<SettlementTransfer> settlements() {
    final creditors = <(TripMember, int)>[];
    final debtors = <(TripMember, int)>[];

    for (final balance in balances()) {
      final units = balance.balance.minorUnits;
      if (units > 0) creditors.add((balance.member, units));
      if (units < 0) debtors.add((balance.member, -units));
    }

    // Ties broken by id so the suggested payments are stable between rebuilds
    // — a list that reshuffles itself is a list nobody trusts.
    int byAmountThenId((TripMember, int) a, (TripMember, int) b) {
      final byAmount = b.$2.compareTo(a.$2);
      return byAmount != 0 ? byAmount : a.$1.id.compareTo(b.$1.id);
    }

    creditors.sort(byAmountThenId);
    debtors.sort(byAmountThenId);

    final transfers = <SettlementTransfer>[];
    var creditIndex = 0;
    var debtIndex = 0;
    var credit = creditors.isEmpty ? 0 : creditors.first.$2;
    var debt = debtors.isEmpty ? 0 : debtors.first.$2;

    while (creditIndex < creditors.length && debtIndex < debtors.length) {
      final amount = credit < debt ? credit : debt;
      if (amount > 0) {
        transfers.add(SettlementTransfer(
          from: debtors[debtIndex].$1,
          to: creditors[creditIndex].$1,
          amount: Money(amount, baseCurrency),
        ),);
      }
      credit -= amount;
      debt -= amount;
      if (credit == 0) {
        creditIndex += 1;
        if (creditIndex < creditors.length) credit = creditors[creditIndex].$2;
      }
      if (debt == 0) {
        debtIndex += 1;
        if (debtIndex < debtors.length) debt = debtors[debtIndex].$2;
      }
    }

    return transfers;
  }
}
