import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/finora_backend.dart';
import '../../../data/recurring_repository.dart';

/// Standing instructions live on the server.
///
/// `next_run_at` is computed there and nowhere else — a locally guessed date
/// would disagree with the poster the first time a month is short — so there is
/// nothing here worth caching offline. The demo build has no server, which the
/// screens render as an explanation rather than a crash.
final recurringRepositoryProvider = Provider<RecurringRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const RecurringRuleException(RecurringRuleException.noBackend);
  }
  return RecurringRepository(client: backend.client);
});

final recurringRulesProvider = FutureProvider.autoDispose<List<RecurringRule>>(
  (ref) => ref.watch(recurringRepositoryProvider).rules(),
);
