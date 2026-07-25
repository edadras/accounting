import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/finora_backend.dart';
import '../../../data/members_repository.dart';

/// Membership always talks to the server.
///
/// There is no offline copy on purpose: an access list read from a stale cache
/// is how a member who was removed an hour ago keeps appearing to still be in.
/// The demo build has no server at all, which is a state the screens render as
/// an explanation rather than a crash.
final membersRepositoryProvider = Provider<MembersRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const MembershipException(MembershipException.noBackend);
  }
  return MembersRepository(client: backend.client);
});

final workspaceMembersProvider =
    FutureProvider.autoDispose<List<WorkspaceMember>>(
  (ref) => ref.watch(membersRepositoryProvider).members(),
);

final workspaceInvitationsProvider =
    FutureProvider.autoDispose<List<WorkspaceInvitation>>(
  (ref) => ref.watch(membersRepositoryProvider).invitations(),
);
