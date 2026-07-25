import 'package:dio/dio.dart';
import 'package:finora/data/members_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/membership_harness.dart';

/// The mapping layer between `MemberController` and the screens.
///
/// Plain `test`, not `testWidgets`: there is no fake clock here, so awaiting
/// Dio is safe without `runAsync`.
void main() {
  MembersRepository repositoryFor(MockAdapter adapter) =>
      MembersRepository(client: membershipClient(adapter));

  group('reading', () {
    test('parses members with their role, name and join date', () async {
      final repository = repositoryFor(
        MockAdapter(
          (options) => MockAdapter.json({
            'data': [
              memberJson(
                id: 'm1',
                role: 'owner',
                name: 'زهرا کریمی',
                email: 'zahra@example.com',
              ),
              memberJson(
                id: 'm2',
                role: 'accountant',
                name: 'Ali',
                email: 'ali@example.com',
                joinedAt: null,
              ),
            ],
          }),
        ),
      );

      final members = await repository.members();

      expect(members.map((m) => m.role).toList(), [
        WorkspaceRole.owner,
        WorkspaceRole.accountant,
      ]);
      expect(members.first.email, 'zahra@example.com');
      expect(members.first.isProtected, isTrue);
      expect(members.first.joinedAt, isNotNull);
      expect(members.last.joinedAt, isNull);
      expect(members.last.isProtected, isFalse);
    });

    test('a role this build has never heard of degrades to viewer', () {
      // Guessing upwards would hand someone buttons the server will refuse.
      expect(WorkspaceRole.parse('superuser'), WorkspaceRole.viewer);
      expect(WorkspaceRole.parse(null), WorkspaceRole.viewer);
    });

    test('owner is never offered as a destination role', () {
      expect(WorkspaceRole.assignable, isNot(contains(WorkspaceRole.owner)));
      expect(WorkspaceRole.assignable.length, 4);
    });

    test('parses every invitation status the server can report', () async {
      final repository = repositoryFor(
        MockAdapter(
          (options) => MockAdapter.json({
            'data': [
              for (final status in ['pending', 'accepted', 'revoked', 'expired'])
                invitationJson(
                  id: 'i-$status',
                  email: '$status@example.com',
                  role: 'member',
                  status: status,
                ),
            ],
          }),
        ),
      );

      final invitations = await repository.invitations();

      expect(invitations.map((i) => i.status).toList(), [
        InvitationStatus.pending,
        InvitationStatus.accepted,
        InvitationStatus.revoked,
        InvitationStatus.expired,
      ]);
      expect(invitations.where((i) => i.isPending).length, 1);
    });
  });

  group('writing', () {
    test('inviting posts the email and role and returns the one-time token',
        () async {
      final adapter = MockAdapter(
        (options) => MockAdapter.json(
          {
            'data': {
              'id': 'inv-1',
              'email': 'new@example.com',
              'role': 'admin',
              'status': 'pending',
              'expires_at': '2026-08-08T08:30:00Z',
              'token': 'PLAINTEXT-TOKEN-SHOWN-ONCE',
            },
          },
          status: 201,
        ),
      );

      final issued = await repositoryFor(adapter).invite(
        email: '  NEW@Example.com ',
        role: WorkspaceRole.admin,
      );

      expect(issued.token, 'PLAINTEXT-TOKEN-SHOWN-ONCE');
      expect(issued.invitation.role, WorkspaceRole.admin);

      final body = adapter.requests.single.data! as Map;
      // Trimmed and lower-cased before it leaves, so "A@x.com" and "a@x.com"
      // cannot both hold an open invitation.
      expect(body['email'], 'new@example.com');
      expect(body['role'], 'admin');
    });

    test('revoke and remove address the right resource', () async {
      final adapter = MockAdapter(
        (options) => MockAdapter.json(const <String, Object?>{}, status: 204),
      );
      final repository = repositoryFor(adapter);

      await repository.revokeInvitation('inv-9');
      await repository.removeMember('mem-4');

      expect(adapter.requests[0].method, 'DELETE');
      expect(adapter.requests[0].path, '/invitations/inv-9');
      expect(adapter.requests[1].method, 'DELETE');
      expect(adapter.requests[1].path, '/members/mem-4');
    });

    test('accepting posts the token on the unscoped route', () async {
      final adapter = MockAdapter(
        (options) => MockAdapter.json(
          {
            'data': {'workspace_id': 'w-1', 'role': 'member'},
          },
          status: 201,
        ),
      );

      final accepted =
          await repositoryFor(adapter).acceptInvitation(' tok en/plus ');

      expect(accepted.workspaceId, 'w-1');
      expect(accepted.role, WorkspaceRole.member);
      // A token is opaque text, so anything in it that would otherwise be read
      // as a path separator has to be escaped.
      expect(adapter.requests.single.path, '/invitations/tok%20en%2Fplus/accept');
    });
  });

  group('failures', () {
    Future<MembershipException> failureFor(ResponseBody response) async {
      final repository = repositoryFor(MockAdapter((options) => response));

      try {
        await repository.invite(
          email: 'a@example.com',
          role: WorkspaceRole.member,
        );
      } on MembershipException catch (error) {
        return error;
      }
      fail('expected a MembershipException');
    }

    test('carries the stable code and drops the server sentence', () async {
      final failure = await failureFor(
        MockAdapter.error(
          MembershipException.alreadyMember,
          status: 409,
          message: 'SERVER-ENGLISH-PROSE',
        ),
      );

      expect(failure.code, 'already_member');
      expect(failure.statusCode, 409);
      expect(failure.translationKey, 'error.already_member');
      // The English sentence is not merely unused — there is nowhere to put it.
      expect(failure.toString(), isNot(contains('SERVER-ENGLISH-PROSE')));
    });

    test('every membership refusal maps to a key the app translates', () async {
      const codes = [
        MembershipException.alreadyMember,
        MembershipException.alreadyInvited,
        MembershipException.invitationInvalid,
        MembershipException.invitationEmailMismatch,
        MembershipException.cannotRemoveOwner,
        MembershipException.cannotChangeOwnerRole,
        MembershipException.cannotInviteAsOwner,
      ];

      for (final code in codes) {
        final failure = await failureFor(MockAdapter.error(code, status: 422));
        expect(failure.code, code);
        expect(failure.isPermissionDenied, isFalse);
      }
    });

    test('a bare 403 is a permission problem, not a validation one', () async {
      final failure = await failureFor(
        MockAdapter.json(
          const {'message': 'This action is unauthorized.'},
          status: 403,
        ),
      );

      expect(failure.code, 'forbidden');
      expect(failure.isPermissionDenied, isTrue);
    });

    test('a mismatched invitation email is a refusal, not a 403 wall',
        () async {
      final failure = await failureFor(
        MockAdapter.error(
          MembershipException.invitationEmailMismatch,
          status: 403,
        ),
      );

      // Same status as the wall above, but the envelope names the reason, so
      // the screen can say which one it is.
      expect(failure.isPermissionDenied, isFalse);
      expect(failure.translationKey, 'error.invitation_email_mismatch');
    });

    test('losing the network reads as offline, not as a refusal', () async {
      final failure = await failureFor(MockAdapter.json(null, status: 599));
      expect(failure.code, 'server_error');

      final repository = repositoryFor(
        MockAdapter((options) => throw connectionFailure(options)),
      );

      try {
        await repository.members();
        fail('expected a MembershipException');
      } on MembershipException catch (error) {
        expect(error.isOffline, isTrue);
      }
    });
  });
}
