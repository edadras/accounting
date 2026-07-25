import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/theme/neon_palette.dart';
import 'package:finora/data/audit_repository.dart';
import 'package:finora/presentation/features/audit/audit_screen.dart';
import 'package:finora/presentation/features/audit/security_log_screen.dart';
import 'package:finora/presentation/widgets/neon_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/membership_harness.dart';

/// The audit screens. Same two rules as the members tests: no `pumpAndSettle`
/// while a progress indicator is live, and no bare `await` on Dio inside a
/// `testWidgets` body.
void main() {
  setUpAll(loadTestFonts);

  final roleChange = auditJson(
    id: 'a1',
    action: 'workspace_member.updated',
    subjectType: 'workspace_member',
    before: const {'role': 'member'},
    after: const {'role': 'admin'},
  );

  final invited = auditJson(
    id: 'a2',
    action: 'workspace.member_invited',
    after: const {'email': 'guest@example.com', 'role': 'viewer'},
    createdAt: '2026-07-23T09:12:00Z',
  );

  MockAdapter auditServer({
    List<Map<String, Object?>> trail = const [],
    List<Map<String, Object?>> security = const [],
    ResponseBody Function(RequestOptions options)? override,
  }) {
    return MockAdapter((options) {
      if (override != null) return override(options);
      if (options.path == '/audit-logs') {
        return MockAdapter.json({
          'data': trail,
          'meta': {'page': 1, 'per_page': 100, 'total': trail.length},
        });
      }
      return MockAdapter.json({'data': security});
    });
  }

  group('workspace trail', () {
    testWidgets('renders a before/after change as a readable diff',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const AuditTrailScreen(),
        locale: AppLocale.en,
        adapter: auditServer(trail: [roleChange]),
      );

      expect(find.text('Member role changed'), findsOneWidget);
      expect(find.text('by زهرا کریمی'), findsOneWidget);

      // The change itself, as words rather than as the JSON the server stored.
      expect(find.text('What changed'), findsOneWidget);
      expect(find.text('Role'), findsOneWidget);
      expect(find.text('Before'), findsOneWidget);
      expect(find.text('After'), findsOneWidget);
      expect(find.text('Member'), findsOneWidget);
      expect(find.text('Admin'), findsOneWidget);

      // Nothing raw leaks through.
      expect(find.textContaining('{'), findsNothing);
      expect(find.text('workspace_member.updated'), findsNothing);
    });

    testWidgets('an entry with no prior value still reads as an addition',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const AuditTrailScreen(),
        locale: AppLocale.en,
        adapter: auditServer(trail: [invited]),
      );

      expect(find.text('Member invited'), findsOneWidget);
      expect(find.text('guest@example.com'), findsOneWidget);
      expect(find.text('Viewer'), findsOneWidget);
      // Both sides are always shown, so a value appearing from nothing is
      // visibly an addition rather than an unexplained value.
      expect(find.text('empty'), findsNWidgets(2));
    });

    testWidgets('filters by action type and by date', (tester) async {
      final adapter = auditServer(trail: [roleChange, invited]);

      await pumpMembershipApp(
        tester,
        const AuditTrailScreen(),
        locale: AppLocale.en,
        adapter: adapter,
      );

      expect(find.byKey(const ValueKey('audit-a1')), findsOneWidget);
      expect(find.byKey(const ValueKey('audit-a2')), findsOneWidget);

      await tester.tap(
        find.byKey(const ValueKey('audit-action-workspace.member_invited')),
      );
      await pumpFrames(tester);

      expect(find.byKey(const ValueKey('audit-a1')), findsNothing);
      expect(find.byKey(const ValueKey('audit-a2')), findsOneWidget);

      // The date filter is the server's job, so it has to travel with the
      // request rather than being applied to what already arrived.
      final first = adapter.requests.first.queryParameters['from']! as String;
      expect(
        DateTime.parse(first),
        membershipClock.subtract(const Duration(days: 30)),
      );

      await tester.tap(find.byKey(const ValueKey('audit-range-week')));
      await pumpFrames(tester);

      final second = adapter.requests.last.queryParameters['from']! as String;
      expect(
        DateTime.parse(second),
        membershipClock.subtract(const Duration(days: 7)),
      );
    });

    testWidgets('a 403 is explained, not dumped', (tester) async {
      await pumpMembershipApp(
        tester,
        const AuditTrailScreen(),
        locale: AppLocale.en,
        adapter: auditServer(
          override: (options) => MockAdapter.json(
            const {'message': 'This action is unauthorized.'},
            status: 403,
          ),
        ),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('You need admin access'), findsOneWidget);
      expect(
        find.textContaining('Your own security log is still available.'),
        findsOneWidget,
      );
      expect(find.text('This action is unauthorized.'), findsNothing);
      expect(find.textContaining('Exception'), findsNothing);
    });
  });

  group('security log', () {
    final events = [
      auditJson(
        id: 's1',
        action: 'auth.login',
        userName: null,
        ip: '192.168.1.9',
        createdAt: '2026-07-25T08:02:00Z',
      ),
      auditJson(
        id: 's2',
        action: 'auth.login_failed',
        userName: null,
        ip: '203.0.113.42',
        after: const {'email': 'zahra@example.com'},
        createdAt: '2026-07-25T07:58:00Z',
      ),
      auditJson(
        id: 's3',
        action: 'auth.logout',
        userName: null,
        createdAt: '2026-07-24T21:40:00Z',
      ),
    ];

    testWidgets('a failed sign-in is visually distinct from a successful one',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const SecurityLogScreen(),
        locale: AppLocale.en,
        adapter: auditServer(security: events),
      );

      expect(find.text('Signed in'), findsOneWidget);
      expect(find.text('Failed sign-in'), findsOneWidget);
      expect(find.text('Signed out'), findsOneWidget);

      final ok = tester.widget<NeonCardShell>(
        find.byKey(const ValueKey('security-s1')),
      );
      final failed = tester.widget<NeonCardShell>(
        find.byKey(const ValueKey('security-s2')),
      );

      // Colour is semantic — magenta is danger — and the glow is the one thing
      // on this screen that carries information rather than decoration.
      expect(failed.accent, NeonPalette.magenta);
      expect(failed.glow, isTrue);
      expect(ok.accent, NeonPalette.lime);
      expect(ok.glow, isFalse);
      expect(failed.accent, isNot(ok.accent));

      // And it is distinguishable without relying on colour alone.
      expect(find.byIcon(Icons.gpp_bad_rounded), findsOneWidget);
      expect(find.byIcon(Icons.login_rounded), findsOneWidget);
      expect(find.text('Failed'), findsOneWidget);
    });

    testWidgets('an address is shown for the event that has one',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const SecurityLogScreen(),
        locale: AppLocale.en,
        adapter: auditServer(security: events),
      );

      expect(find.text('203.0.113.42'), findsOneWidget);
      expect(find.text('IP address'), findsNWidgets(2));
    });

    testWidgets('an empty log says so rather than showing nothing',
        (tester) async {
      await pumpMembershipApp(
        tester,
        const SecurityLogScreen(),
        locale: AppLocale.en,
        adapter: auditServer(),
      );

      expect(find.text('No security events recorded.'), findsOneWidget);
    });
  });

  group('entry model', () {
    test('only the fields that actually moved count as changes', () {
      final entry = AuditEntry.fromJson(
        auditJson(
          id: 'x',
          action: 'workspace_member.updated',
          before: const {'role': 'member', 'name': 'Ali'},
          after: const {'role': 'admin', 'name': 'Ali'},
        ),
      );

      expect(entry.changes.map((c) => c.field).toList(), ['role']);
      expect(entry.changes.single.before, 'member');
      expect(entry.changes.single.after, 'admin');
    });

    test('a failure is recognised by its action, not by a hard-coded list', () {
      for (final action in [
        'auth.login_failed',
        'auth.two_factor_failed',
        'data_export.failed',
      ]) {
        expect(
          AuditEntry.fromJson(auditJson(id: 'x', action: action)).isFailure,
          isTrue,
          reason: action,
        );
      }

      expect(
        AuditEntry.fromJson(auditJson(id: 'x', action: 'auth.login')).isFailure,
        isFalse,
      );
    });
  });

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    final screens = <String, Widget>{
      'trail': const AuditTrailScreen(),
      'security': const SecurityLogScreen(),
    };

    for (final entry in screens.entries) {
      testWidgets('${entry.key} builds in ${locale.code}', (tester) async {
        await pumpMembershipApp(
          tester,
          entry.value,
          locale: locale,
          adapter: auditServer(
            trail: [roleChange, invited],
            security: [
              auditJson(id: 's1', action: 'auth.login', userName: null),
              auditJson(
                id: 's2',
                action: 'auth.login_failed',
                userName: null,
                ip: '203.0.113.42',
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
