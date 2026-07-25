import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/data_repository.dart';
import '../../../data/finora_backend.dart';

/// The data-ops repository, or null in the demo build where there is no server
/// to export from or delete an account on.
///
/// Built from the assembled backend rather than stored on it, so wiring these
/// screens in costs nothing anywhere else.
final dataRepositoryProvider = Provider<DataRepository?>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  return backend == null ? null : DataRepository(client: backend.client);
});

/// How long the screens wait between two status reads. Tests shrink it to
/// nothing so a poll sequence runs in a frame instead of in nine seconds.
final dataPollIntervalProvider =
    Provider<Duration>((ref) => const Duration(seconds: 3));

/// Set once the server confirms a scheduled deletion and cleared when it is
/// cancelled. Lives above the screens so the banner can be shown anywhere.
final scheduledDeletionProvider =
    StateProvider<ScheduledDeletion?>((ref) => null);
