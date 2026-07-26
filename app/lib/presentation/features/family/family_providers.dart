import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/family_repository.dart';
import '../../../data/finora_backend.dart';
import '../../../data/modules_repository.dart' show clockProvider;

/// The household always talks to the server.
///
/// Allowances move real money and spending caps are measured against the
/// ledger; neither can be answered from a cache that another device has already
/// invalidated. The demo build has no server at all, which the screens render
/// as a translated explanation rather than a crash.
final familyRepositoryProvider = Provider<FamilyRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const FamilyFailure(FamilyFailure.noBackend);
  }
  return FamilyRepository(
    client: backend.client,
    baseCurrency: backend.baseCurrency,
  );
});

/// The `YYYY-MM` month every family screen is looking at.
///
/// A single source so the list, the detail and the payment dialog cannot drift
/// onto different months while the user is looking at them.
final familyPeriodProvider = StateProvider<String>(
  (ref) => monthKey(ref.watch(clockProvider)),
);

/// The members and what each of them spent this month, fetched together.
final class Household {
  const Household({required this.members, required this.report});

  final List<HouseholdMember> members;
  final SpendingReport report;

  MemberSpending? spendingFor(String memberId) => report.forMember(memberId);

  /// Who could pay [member] their allowance: anybody else in the household with
  /// an account for the money to come out of. The server refuses a payer who is
  /// the recipient, and refuses one with no account, so neither is offered.
  List<HouseholdMember> payersFor(HouseholdMember member) => [
        for (final candidate in members)
          if (candidate.id != member.id && candidate.accountId != null)
            candidate,
      ];
}

final householdProvider = FutureProvider.autoDispose<Household>((ref) async {
  final repository = ref.watch(familyRepositoryProvider);
  final period = ref.watch(familyPeriodProvider);

  // Both reads are issued before either is awaited so they overlap, and
  // Future.wait listens to both immediately — a refusal of one is never an
  // unhandled error while the other is still in flight.
  final results = await Future.wait<Object>([
    repository.members(),
    repository.spending(period: period),
  ]);

  return Household(
    members: results[0] as List<HouseholdMember>,
    report: results[1] as SpendingReport,
  );
});

/// One member, their spending this month, and every allowance they have been
/// paid.
final class MemberDetail {
  const MemberDetail({
    required this.member,
    required this.spending,
    required this.payments,
  });

  final HouseholdMember member;
  final MemberSpending? spending;
  final List<AllowancePayment> payments;

  /// Whether this month has already been settled. The server enforces it with a
  /// unique index; the UI reads it to stop offering a button that can only
  /// fail.
  bool isPaidFor(String period) =>
      payments.any((payment) => payment.period == period);
}

final memberDetailProvider =
    FutureProvider.autoDispose.family<MemberDetail, String>((ref, id) async {
  final repository = ref.watch(familyRepositoryProvider);
  final period = ref.watch(familyPeriodProvider);

  final results = await Future.wait<Object>([
    repository.member(id),
    repository.spending(period: period, memberId: id),
    repository.allowances(memberId: id),
  ]);

  return MemberDetail(
    member: results[0] as HouseholdMember,
    spending: (results[1] as SpendingReport).forMember(id),
    payments: results[2] as List<AllowancePayment>,
  );
});
