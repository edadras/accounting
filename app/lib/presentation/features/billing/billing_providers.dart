import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../data/billing_repository.dart';
import '../../../data/finora_backend.dart';

final billingRepositoryProvider = Provider<BillingRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const BillingFailure(BillingFailure.noBackend);
  }
  return BillingRepository(client: backend.client);
});

/// The catalogue. Public plans only — that is all the endpoint returns.
final billingPlansProvider = FutureProvider.autoDispose<List<BillingPlan>>(
  (ref) => ref.watch(billingRepositoryProvider).plans(),
);

/// The subscription, its entitlements and the usage behind them, in one read.
///
/// Never cached across a write: after a cancel the row means something
/// different, and a stale copy would keep showing a renewal date that is now a
/// cut-off date.
final subscriptionProvider = FutureProvider.autoDispose<BillingSnapshot>(
  (ref) => ref.watch(billingRepositoryProvider).subscription(),
);

final invoicesProvider = FutureProvider.autoDispose<List<BillingInvoice>>(
  (ref) => ref.watch(billingRepositoryProvider).invoices(),
);

/// The app's own name for a plan, falling back to the catalogue's English one
/// for a plan this build has never heard of.
String planLabel(Translator t, String code, {String? fallback}) {
  final key = 'billing.plan.$code';
  final translated = t(key);
  if (translated != key) return translated;
  return fallback == null || fallback.isEmpty ? code : fallback;
}
