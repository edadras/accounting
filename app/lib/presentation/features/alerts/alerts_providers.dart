import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/alerts_repository.dart';
import '../../../data/finora_backend.dart';

/// Alerts always talk to the server.
///
/// There is no offline copy on purpose: an inbox served from a cache would keep
/// showing an alert the user already read on their phone, and "already dealt
/// with" is the one thing a list of warnings has to get right. The demo build
/// has no server at all, which the screens render as an explanation rather than
/// a crash.
final alertsRepositoryProvider = Provider<AlertsRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const AlertsException(AlertsException.noBackend);
  }
  return AlertsRepository(client: backend.client);
});

/// Whether the inbox is asking the server for unread alerts only. Filtering
/// server-side rather than hiding rows locally keeps the list honest about how
/// many alerts there are.
final alertUnreadFilterProvider = StateProvider<bool>((ref) => false);

/// Null means every kind.
final alertTypeFilterProvider = StateProvider<AlertRuleType?>((ref) => null);

final alertInboxProvider = FutureProvider.autoDispose<List<AlertItem>>(
  (ref) async {
    final alerts = await ref.watch(alertsRepositoryProvider).inbox(
          unreadOnly: ref.watch(alertUnreadFilterProvider),
          type: ref.watch(alertTypeFilterProvider),
        );

    // The server orders by when the alert was scheduled. Unread comes first on
    // top of that, because an inbox is a queue of things still owed a decision,
    // not a chronology.
    return [...alerts]..sort((a, b) {
        if (a.isUnread != b.isUnread) return a.isUnread ? -1 : 1;
        return b.scheduledAt.compareTo(a.scheduledAt);
      });
  },
);

final alertRulesProvider = FutureProvider.autoDispose<List<AlertRule>>(
  (ref) => ref.watch(alertsRepositoryProvider).rules(),
);

final alertPreferencesProvider = FutureProvider.autoDispose<AlertPreferences>(
  (ref) => ref.watch(alertsRepositoryProvider).preferences(),
);
