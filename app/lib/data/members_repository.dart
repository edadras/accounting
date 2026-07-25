import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';

/// The five roles `WorkspaceMember::ROLES` defines, in descending authority.
///
/// Order matters: the UI lists roles in this order so the picker reads as a
/// ladder rather than an alphabet.
enum WorkspaceRole {
  owner,
  admin,
  accountant,
  member,
  viewer;

  /// An unknown role from a newer server degrades to the least privileged one.
  /// Guessing upwards would hand someone buttons the server will refuse.
  static WorkspaceRole parse(String? raw) => WorkspaceRole.values.firstWhere(
        (role) => role.name == raw,
        orElse: () => WorkspaceRole.viewer,
      );

  /// A workspace has exactly one owner and the server refuses to mint another,
  /// so owner is never offered as a destination role.
  static const assignable = [admin, accountant, member, viewer];

  bool get canManageMembers => this == owner || this == admin;
}

enum InvitationStatus {
  pending,
  accepted,
  revoked,
  expired;

  static InvitationStatus parse(String? raw) =>
      InvitationStatus.values.firstWhere(
        (status) => status.name == raw,
        orElse: () => InvitationStatus.expired,
      );
}

final class WorkspaceMember {
  const WorkspaceMember({
    required this.id,
    required this.role,
    this.userId = '',
    this.name = '',
    this.email = '',
    this.joinedAt,
  });

  final String id;
  final WorkspaceRole role;
  final String userId;
  final String name;
  final String email;
  final DateTime? joinedAt;

  /// The owner is protected by `ManageMembership`: their role cannot change and
  /// they cannot be removed. The UI reads this to decide what to *offer*, not
  /// merely what to allow.
  bool get isProtected => role == WorkspaceRole.owner;

  static WorkspaceMember fromJson(Map<String, Object?> json) {
    final user = (json['user'] as Map?)?.cast<String, Object?>() ?? const {};

    return WorkspaceMember(
      id: json['id'] as String? ?? '',
      role: WorkspaceRole.parse(json['role'] as String?),
      userId: user['id'] as String? ?? '',
      name: user['name'] as String? ?? '',
      email: user['email'] as String? ?? '',
      joinedAt: _dateOf(json['joined_at']),
    );
  }
}

final class WorkspaceInvitation {
  const WorkspaceInvitation({
    required this.id,
    required this.email,
    required this.role,
    required this.status,
    this.expiresAt,
    this.createdAt,
  });

  final String id;
  final String email;
  final WorkspaceRole role;
  final InvitationStatus status;
  final DateTime? expiresAt;
  final DateTime? createdAt;

  bool get isPending => status == InvitationStatus.pending;

  static WorkspaceInvitation fromJson(Map<String, Object?> json) {
    return WorkspaceInvitation(
      id: json['id'] as String? ?? '',
      email: json['email'] as String? ?? '',
      role: WorkspaceRole.parse(json['role'] as String?),
      status: InvitationStatus.parse(json['status'] as String?),
      expiresAt: _dateOf(json['expires_at']),
      createdAt: _dateOf(json['created_at']),
    );
  }
}

/// An invitation plus the plaintext token, which exists only in the response
/// that created it. The server stores a hash; nothing can produce this string a
/// second time.
final class IssuedInvitation {
  const IssuedInvitation({required this.invitation, required this.token});

  final WorkspaceInvitation invitation;
  final String token;
}

final class AcceptedInvitation {
  const AcceptedInvitation({required this.workspaceId, required this.role});

  final String workspaceId;
  final WorkspaceRole role;
}

/// Every refusal `MembershipException` (PHP) can raise, plus the transport
/// codes, reduced to a code the UI translates.
final class MembershipException extends CodedFailure {
  const MembershipException(super.code, {super.statusCode});

  static const alreadyMember = 'already_member';
  static const alreadyInvited = 'already_invited';
  static const invitationInvalid = 'invitation_invalid';
  static const invitationEmailMismatch = 'invitation_email_mismatch';
  static const cannotRemoveOwner = 'cannot_remove_owner';
  static const cannotChangeOwnerRole = 'cannot_change_owner_role';
  static const cannotInviteAsOwner = 'cannot_invite_as_owner';

  /// Raised before a request is even attempted, when the build has no server
  /// behind it at all.
  static const noBackend = 'members_unavailable';
}

/// Workspace membership: who is in, who is invited, and what they may do.
///
/// A thin mapping over the endpoints in `routes/api.php` — no caching, because
/// an access list read from a stale copy is exactly the kind of thing that
/// hides a member who should have been removed an hour ago.
final class MembersRepository {
  const MembersRepository({required this.client});

  final ApiClient client;

  Future<List<WorkspaceMember>> members() async {
    final response = await _guard(() => client.get('/members'));
    return _listOf(response, WorkspaceMember.fromJson);
  }

  Future<List<WorkspaceInvitation>> invitations() async {
    final response = await _guard(() => client.get('/invitations'));
    return _listOf(response, WorkspaceInvitation.fromJson);
  }

  Future<IssuedInvitation> invite({
    required String email,
    required WorkspaceRole role,
  }) async {
    final response = await _guard(
      () => client.post('/invitations', body: {
        'email': email.trim().toLowerCase(),
        'role': role.name,
      },),
    );

    final data = _data(response);

    return IssuedInvitation(
      invitation: WorkspaceInvitation.fromJson(data),
      token: data['token'] as String? ?? '',
    );
  }

  Future<void> revokeInvitation(String invitationId) =>
      _guard(() => client.delete('/invitations/$invitationId'));

  Future<WorkspaceMember> changeRole(
    String memberId,
    WorkspaceRole role,
  ) async {
    final response = await _guard(
      () => client.patch('/members/$memberId', body: {'role': role.name}),
    );

    final data = _data(response);

    return WorkspaceMember(
      id: data['id'] as String? ?? memberId,
      role: WorkspaceRole.parse(data['role'] as String?),
    );
  }

  Future<void> removeMember(String memberId) =>
      _guard(() => client.delete('/members/$memberId'));

  /// Accepting happens before membership exists, so this one is not scoped to
  /// the active workspace — the token names the workspace.
  Future<AcceptedInvitation> acceptInvitation(String token) async {
    final response = await _guard(
      () => client.post('/invitations/${Uri.encodeComponent(token.trim())}'
          '/accept',),
    );

    final data = _data(response);

    return AcceptedInvitation(
      workspaceId: data['workspace_id'] as String? ?? '',
      role: WorkspaceRole.parse(data['role'] as String?),
    );
  }

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw MembershipException(error.code, statusCode: error.statusCode);
    }
  }

  static Map<String, Object?> _data(Map<String, Object?> response) =>
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {};

  static List<T> _listOf<T>(
    Map<String, Object?> response,
    T Function(Map<String, Object?>) parse,
  ) =>
      [
        for (final item in response['data'] as List? ?? const [])
          if (item is Map) parse(item.cast<String, Object?>()),
      ];
}

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
