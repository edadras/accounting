import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/budget_repository.dart';
import '../../../data/finora_backend.dart';

/// Budgets always talk to the server.
///
/// There is no offline copy: a ceiling read from a stale cache is how somebody
/// keeps being told they have room left in a budget another device spent an
/// hour ago. The demo build has no server at all, which the screens render as
/// a translated explanation rather than a crash.
final budgetRepositoryProvider = Provider<BudgetRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const BudgetException(BudgetException.noBackend);
  }
  return BudgetRepository(
    client: backend.client,
    baseCurrency: backend.baseCurrency,
  );
});

/// A budget's live consumption paired with the definition it was created from.
///
/// The two come from different endpoints because they answer different
/// questions: `/budgets/status` knows what has been spent, `/budgets` knows what
/// the user actually typed — including the start date and the currency the
/// ceiling was set in, neither of which survives the base-currency conversion
/// status performs. The edit form needs the second, so the list fetches both
/// rather than making the user wait for a round trip after tapping edit.
final class BudgetBoardEntry {
  const BudgetBoardEntry({required this.status, this.plan});

  final BudgetStatus status;
  final BudgetPlan? plan;

  String get id => status.id;

  /// Null when the two endpoints disagree — a budget created between the two
  /// requests. The row still renders; only editing is withheld, because there
  /// is nothing truthful to prefill the form with.
  BudgetDraft? get draft {
    final plan = this.plan;
    return plan == null ? null : BudgetDraft.from(plan);
  }
}

final class BudgetBoard {
  const BudgetBoard(this.entries);

  final List<BudgetBoardEntry> entries;

  bool get isEmpty => entries.isEmpty;
}

/// Everything the management screen renders, in one await.
final budgetBoardProvider = FutureProvider.autoDispose<BudgetBoard>((ref) async {
  final repository = ref.watch(budgetRepositoryProvider);

  // Issued together: the two reads are independent, and serialising them would
  // double the time the screen spends on its spinner.
  //
  // Future.wait rather than two awaits: it listens to both immediately, so a
  // refusal of the second read is never an unhandled error while the first is
  // still in flight — and it still throws the original CodedFailure, which is
  // what the screen translates.
  final results = await Future.wait<Object>([
    repository.status(),
    repository.plans(),
  ]);

  final status = results[0] as List<BudgetStatus>;
  final plans = results[1] as List<BudgetPlan>;

  final byId = {for (final plan in plans) plan.id: plan};

  return BudgetBoard([
    for (final entry in status)
      BudgetBoardEntry(status: entry, plan: byId[entry.id]),
  ]);
});
