import 'package:collection/collection.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ulid/ulid.dart';

import '../../../data/ledger_repository.dart';
import '../../../domain/ai/ai_models.dart';
import '../../../domain/ai/ai_repository.dart';
import '../../../domain/ai/voice_recorder.dart';
import '../../../domain/entities.dart' as domain;

/// The AI layer the UI talks to.
///
/// This is the seam: the server-backed implementation lives in
/// `lib/data/remote/` and is dropped in with
/// `aiRepositoryProvider.overrideWith(...)`. Nothing above this line knows or
/// cares which one is running, which is also why every AI screen is testable
/// with no network.
final aiRepositoryProvider = Provider<AiRepository>((ref) {
  return FakeAiRepository(
    ledger: ref.watch(ledgerRepositoryProvider),
    baseCurrency: ref.watch(baseCurrencyProvider),
  );
});

/// Microphone port. Fake until a native recorder plugin is added — see
/// [VoiceRecorder] for why the flow is built against an interface.
final voiceRecorderProvider = Provider<VoiceRecorder>((ref) {
  return FakeVoiceRecorder();
});

final aiInsightsProvider = FutureProvider<List<AiInsight>>((ref) {
  ref.watch(ledgerRevisionProvider);
  return ref.watch(aiRepositoryProvider).insights();
});

/// Insights the user has swiped away. Kept in memory only: an insight is
/// recomputed from the ledger, so dismissing one is a "not now", not a delete.
final dismissedInsightsProvider = StateProvider<Set<String>>((ref) => const {});

final chatLogProvider = StateProvider<List<ChatMessage>>((ref) => const []);

/// The one place a draft becomes real money.
///
/// Returns false when there is no account to book it against, so the caller can
/// say so rather than silently dropping the user's input.
Future<bool> recordDraft(WidgetRef ref, TransactionDraft draft) async {
  final ledger = ref.read(ledgerRepositoryProvider);
  final accounts = await ledger.accounts();
  final accountId = draft.accountId ?? accounts.firstOrNull?.id;
  if (accountId == null) return false;

  await ledger.record(domain.Transaction(
    id: Ulid().toString(),
    type: draft.type.value,
    amount: draft.amount.value,
    // A draft in a foreign currency still needs a rate before it can be stated
    // in the base currency; until the rates service lands (M6) the face value
    // is carried across unconverted rather than invented at 1:1.
    baseAmount: draft.amount.value,
    accountId: accountId,
    categoryId: draft.type.value == domain.TransactionType.transfer
        ? null
        : draft.categoryId?.value,
    occurredAt: draft.occurredAt.value,
    description: draft.description,
  ),);

  ref.read(ledgerRevisionProvider.notifier).state++;
  return true;
}
