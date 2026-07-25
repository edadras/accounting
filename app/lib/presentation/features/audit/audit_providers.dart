import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/audit_repository.dart';
import '../../../data/finora_backend.dart';
import '../../../data/modules_repository.dart' show clockProvider;

/// The date filter, as the four spans anyone actually asks for.
///
/// A free date-range picker would be more general and less useful: "what
/// happened lately" is the question, and every extra tap between it and the
/// answer is a reason not to look.
enum AuditRange {
  week(7),
  month(30),
  quarter(90),
  all(null);

  const AuditRange(this.days);

  final int? days;

  DateTime? since(DateTime now) =>
      days == null ? null : now.subtract(Duration(days: days!));

  String get labelKey => switch (this) {
        AuditRange.week => 'audit.range7',
        AuditRange.month => 'audit.range30',
        AuditRange.quarter => 'audit.range90',
        AuditRange.all => 'audit.rangeAll',
      };
}

final auditRepositoryProvider = Provider<AuditRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const AuditException(AuditException.noBackend);
  }
  return AuditRepository(client: backend.client);
});

final auditRangeProvider = StateProvider<AuditRange>((ref) => AuditRange.month);

/// The chosen action, or null for all of them.
///
/// Applied to the fetched page rather than sent to the server: the chips are
/// built from the actions that are actually present, and asking the server for
/// one action at a time would leave the chip list describing a page it no
/// longer matches.
final auditActionProvider = StateProvider<String?>((ref) => null);

final auditTrailProvider = FutureProvider.autoDispose<List<AuditEntry>>((ref) {
  final range = ref.watch(auditRangeProvider);

  return ref.watch(auditRepositoryProvider).workspaceTrail(
        from: range.since(ref.watch(clockProvider)),
      );
});

final securityLogProvider = FutureProvider.autoDispose<List<AuditEntry>>(
  (ref) => ref.watch(auditRepositoryProvider).securityLog(),
);
