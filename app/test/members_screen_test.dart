import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/data/members_repository.dart';
import 'package:finora/presentation/features/members/accept_invitation_screen.dart';
import 'package:finora/presentation/features/members/invite_screen.dart';
import 'package:finora/presentation/features/members/members_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/membership_harness.dart';

/// The members screens, driven against a mocked socket.
///
/// Nothing here calls `pumpAndSettle`: these screens open on a
/// `CircularProgressIndicator`, and a tree with a live progress indicator never
/// goes quiet, so `pumpAndSettle` waits out its whole budget instead of
/// failing. Frames are pumped explicitly.
void main() {
  setUpAll(loadTestFonts);

  final seededMembers = [
    memberJson(
      id: 'm1',
      role: 'owner',
      name: 'زهرا کریمی',
      email: 'zahra@example.com',
    ),
    memberJson(
      id: 'm2',
      role: 'admin',
      name: 'Ali Rezaei',
      email: 'ali@example.com',
    ),
    memberJson(
      id: 'm3',
      role: 'viewer',
      name: 'Mina Sharifi',
      email: 'mina@example.com',
    ),
  ];

  /// Answers the two GETs the members screen makes, and hands anything else to
  /// [onWrite] so a test can decide what a POST or DELETE does.
  MockAdapter membersServer({
    List<Map<String, Object?>> Function()? members,
    List<Map<String, Object?>> Function()? invitations,
    ResponseBody Function(RequestOptions options)? onWrite,
  }) {
    return MockAdapter((options) {
      if (options.method == 'GET' && options.path == '/members') {
        return MockAdapter.json({'data': members?.call() ?? seededMembers});
      }
      if (options.method == 'GET' && options.path == '/invitations') {
        return MockAdapter.json({'data': invitations?.call() ?? const []});
      }
      if (onWrite != null) return onWrite(options);
      return MockAdapter.json(const <String, Object?>{}, status: 204);
    });
  }

  testWidgets('the list names every role and protects the owner row',
      (tester) async {
    await pumpMembershipApp(
      tester,
      const MembersScreen(),
      locale: AppLocale.en,
      adapter: membersServer(),
    );

    // The role is the thing that matters, so it is said in words on every row.
    expect(find.text('Owner'), findsOneWidget);
    expect(find.text('Admin'), findsOneWidget);
    expect(find.text('Viewer'), findsOneWidget);
    expect(find.text('zahra@example.com'), findsOneWidget);

    // A UI that offers an action the server will reject is a UI that lies:
    // the owner row carries neither control, it does not merely fail on tap.
    expect(find.byKey(const ValueKey('member-role-m1')), findsNothing);
    expect(find.byKey(const ValueKey('member-remove-m1')), findsNothing);
    expect(
      find.text('The workspace owner cannot be demoted or removed.'),
      findsOneWidget,
    );

    // Every other row has both.
    for (final id in ['m2', 'm3']) {
      expect(find.byKey(ValueKey('member-role-$id')), findsOneWidget);
      expect(find.byKey(ValueKey('member-remove-$id')), findsOneWidget);
    }
  });

  testWidgets('the role picker never offers owner as a destination',
      (tester) async {
    await pumpMembershipApp(
      tester,
      const MembersScreen(),
      locale: AppLocale.en,
      adapter: membersServer(),
    );

    await tester.tap(find.byKey(const ValueKey('member-role-m3')));
    await pumpFrames(tester);

    expect(find.byKey(const ValueKey('role-option-admin')), findsOneWidget);
    expect(find.byKey(const ValueKey('role-option-viewer')), findsOneWidget);
    expect(find.byKey(const ValueKey('role-option-owner')), findsNothing);
  });

  testWidgets('removing a member asks first, then refetches the list',
      (tester) async {
    var current = [...seededMembers];
    final adapter = membersServer(
      members: () => current,
      onWrite: (options) {
        if (options.method == 'DELETE' && options.path == '/members/m3') {
          current = [for (final m in current) if (m['id'] != 'm3') m];
        }
        return MockAdapter.json(const <String, Object?>{}, status: 204);
      },
    );

    await pumpMembershipApp(
      tester,
      const MembersScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    await tester.tap(find.byKey(const ValueKey('member-remove-m3')));
    await pumpFrames(tester);

    // Nothing has happened yet — the confirmation is a real gate.
    expect(find.text('Remove from workspace'), findsOneWidget);
    expect(current.length, 3);

    await tester.tap(find.text('Remove'));
    await pumpFrames(tester);

    expect(current.length, 2);
    expect(find.byKey(const ValueKey('member-m3')), findsNothing);
    expect(find.byKey(const ValueKey('member-m2')), findsOneWidget);
  });

  testWidgets('revoking removes the invitation from the list', (tester) async {
    var open = [
      invitationJson(
        id: 'inv-1',
        email: 'guest@example.com',
        role: 'member',
        status: 'pending',
      ),
    ];

    final adapter = membersServer(
      invitations: () => open,
      onWrite: (options) {
        if (options.method == 'DELETE' && options.path == '/invitations/inv-1') {
          open = const [];
        }
        return MockAdapter.json(const <String, Object?>{}, status: 204);
      },
    );

    await pumpMembershipApp(
      tester,
      const MembersScreen(),
      locale: AppLocale.en,
      adapter: adapter,
    );

    expect(find.text('guest@example.com'), findsOneWidget);
    expect(find.text('Pending'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('invitation-revoke-inv-1')));
    await pumpFrames(tester);
    await tester.tap(find.text('Revoke'));
    await pumpFrames(tester);

    expect(find.byKey(const ValueKey('invitation-inv-1')), findsNothing);
    expect(find.text('guest@example.com'), findsNothing);
    expect(find.text('No open invitations.'), findsOneWidget);
  });

  testWidgets('a plain member sees the permission state, not a crash',
      (tester) async {
    // What `MemberController::assertCanManage` actually returns: a bare 403
    // with no error envelope at all.
    await pumpMembershipApp(
      tester,
      const MembersScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(
        (options) => MockAdapter.json(
          const {'message': 'This action is unauthorized.'},
          status: 403,
        ),
      ),
    );

    expect(tester.takeException(), isNull);
    expect(find.text('You need admin access'), findsOneWidget);
    expect(
      find.text(
        'Only the workspace owner and admins can see members and invitations.',
      ),
      findsOneWidget,
    );
    // No list, and nothing offering an action the caller cannot take.
    expect(find.byKey(const ValueKey('members-invite')), findsNothing);
    expect(find.text('This action is unauthorized.'), findsNothing);
  });

  group('inviting', () {
    testWidgets('shows the token once and drops it on acknowledgement',
        (tester) async {
      const token = 'ZK4tokenPLAINTEXTshownOnce8899';

      await pumpMembershipApp(
        tester,
        const InviteMemberScreen(),
        locale: AppLocale.en,
        adapter: MockAdapter(
          (options) => MockAdapter.json(
            {
              'data': {
                'id': 'inv-9',
                'email': 'new@example.com',
                'role': 'admin',
                'status': 'pending',
                'expires_at': '2026-08-08T08:30:00Z',
                'token': token,
              },
            },
            status: 201,
          ),
        ),
      );

      await tester.enterText(
        find.byKey(const ValueKey('invite-email')),
        'new@example.com',
      );
      await tester.tap(find.byKey(const ValueKey('invite-role-admin')));
      await pumpFrames(tester);

      await tester.tap(find.byKey(const ValueKey('invite-send')));
      await pumpFrames(tester);

      expect(find.byKey(const ValueKey('invite-token')), findsOneWidget);
      expect(find.text(token), findsOneWidget);
      expect(
        find.text('This token is shown once. Copy it now — it cannot be '
            'recovered.'),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('invite-token-copy')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('invite-token-ack')));
      await pumpFrames(tester);

      // Once acknowledged it is gone from the screen, which is the only place
      // the plaintext ever existed — the server kept a hash.
      expect(find.byKey(const ValueKey('invite-token')), findsNothing);
      expect(find.text(token), findsNothing);
    });

    testWidgets('an already_member refusal renders translated, not in English',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const InviteMemberScreen(),
        locale: AppLocale.fa,
        adapter: MockAdapter(
          (options) => MockAdapter.error(
            MembershipException.alreadyMember,
            status: 409,
            message: 'SERVER-ENGLISH-PROSE about being already a member.',
          ),
        ),
      );

      await tester.enterText(
        find.byKey(const ValueKey('invite-email')),
        'zahra@example.com',
      );
      await tester.tap(find.byKey(const ValueKey('invite-send')));
      await pumpFrames(tester);

      expect(
        find.text('این ایمیل هم‌اکنون عضو این فضای کاری است.'),
        findsOneWidget,
      );
      expect(find.textContaining('SERVER-ENGLISH-PROSE'), findsNothing);
    });

    testWidgets('an already_invited refusal reads in the app language too',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const InviteMemberScreen(),
        locale: AppLocale.en,
        adapter: MockAdapter(
          (options) => MockAdapter.error(
            MembershipException.alreadyInvited,
            status: 409,
            message: 'SERVER-ENGLISH-PROSE',
          ),
        ),
      );

      await tester.enterText(
        find.byKey(const ValueKey('invite-email')),
        'guest@example.com',
      );
      await tester.tap(find.byKey(const ValueKey('invite-send')));
      await pumpFrames(tester);

      expect(
        find.text('That email address already has an open invitation.'),
        findsOneWidget,
      );
      expect(find.textContaining('SERVER-ENGLISH-PROSE'), findsNothing);
    });

    testWidgets('a malformed address never reaches the server', (tester) async {
      final adapter = membersServer();

      await pumpMembershipApp(
        tester,
        const InviteMemberScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      await tester.enterText(
        find.byKey(const ValueKey('invite-email')),
        'not-an-address',
      );
      await tester.tap(find.byKey(const ValueKey('invite-send')));
      await pumpFrames(tester);

      expect(find.text('That email address is not valid.'), findsOneWidget);
      expect(adapter.requests, isEmpty);
    });
  });

  testWidgets('an invalid token renders the translated refusal', (tester) async {
    await pumpMembershipApp(
      tester,
      const AcceptInvitationScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(
        (options) => MockAdapter.error(
          MembershipException.invitationInvalid,
          status: 404,
          message: 'SERVER-ENGLISH-PROSE',
        ),
      ),
    );

    await tester.enterText(
      find.byKey(const ValueKey('accept-token')),
      'whatever',
    );
    await tester.tap(find.byKey(const ValueKey('accept-submit')));
    await pumpFrames(tester);

    expect(
      find.text('That invitation is not valid. It may have been revoked or '
          'expired.'),
      findsOneWidget,
    );
    expect(find.textContaining('SERVER-ENGLISH-PROSE'), findsNothing);
  });

  testWidgets('accepting a valid token says which role was granted',
      (tester) async {
    await pumpMembershipApp(
      tester,
      const AcceptInvitationScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(
        (options) => MockAdapter.json(
          {
            'data': {'workspace_id': 'w-1', 'role': 'accountant'},
          },
          status: 201,
        ),
      ),
    );

    await tester.enterText(
      find.byKey(const ValueKey('accept-token')),
      'a-real-token',
    );
    await tester.tap(find.byKey(const ValueKey('accept-submit')));
    await pumpFrames(tester);

    expect(
      find.text('You joined the workspace as Accountant.'),
      findsOneWidget,
    );
  });

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    final screens = <String, Widget>{
      'members': const MembersScreen(),
      'invite': const InviteMemberScreen(),
      'accept': const AcceptInvitationScreen(),
    };

    for (final entry in screens.entries) {
      testWidgets('${entry.key} builds in ${locale.code}', (tester) async {
        await pumpMembershipApp(
          tester,
          entry.value,
          locale: locale,
          adapter: membersServer(
            invitations: () => [
              invitationJson(
                id: 'inv-1',
                email: 'guest@example.com',
                role: 'member',
                status: 'pending',
              ),
              invitationJson(
                id: 'inv-2',
                email: 'gone@example.com',
                role: 'viewer',
                status: 'revoked',
              ),
            ],
          ),
        );

        expect(tester.takeException(), isNull);
        expect(
          Directionality.of(tester.element(find.byType(Scaffold).first)),
          locale.textDirection,
        );
      });
    }
  }
}
