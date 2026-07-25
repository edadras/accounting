import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/documents_repository.dart';
import '../../../data/finora_backend.dart';

/// The Documents module, or null in the demo build where there is no server
/// holding any files.
///
/// Built from the assembled backend rather than stored on it, in the same shape
/// as the data-ops repository, so wiring these screens in costs nothing
/// anywhere else.
final documentsRepositoryProvider = Provider<DocumentsRepository?>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  return backend == null ? null : DocumentsRepository(client: backend.client);
});
