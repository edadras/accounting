import 'dart:io';

import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/audit_repository.dart';
import 'package:finora/data/members_repository.dart';
import 'package:finora/data/modules_repository.dart' show clockProvider;
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/presentation/features/audit/audit_providers.dart';
import 'package:finora/presentation/features/members/members_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fake_server.dart';

/// Everything the members and audit screens need to run against a fake socket.
///
/// Two rules govern every test that uses this file, and breaking either one
/// hangs the suite rather than failing it:
///
/// * anything that awaits Dio directly runs inside `tester.runAsync`, because
///   `testWidgets` drives a fake clock that never completes a real future;
/// * nothing calls `pumpAndSettle` — these screens spend their first frames
///   showing a `CircularProgressIndicator`, which never goes quiet.
///
/// Driving the widget tree itself needs neither: [MockAdapter] answers from a
/// function, so the response arrives on a microtask that an ordinary
/// [pumpFrames] flushes.
ApiClient membershipClient(MockAdapter adapter) => ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1),
    );

Future<void> pumpFrames(WidgetTester tester, {int frames = 6}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// A fixed clock so the audit date filter asks for the same window every run.
final membershipClock = DateTime.utc(2026, 7, 25, 12);

Future<void> pumpMembershipApp(
  WidgetTester tester,
  Widget screen, {
  required AppLocale locale,
  MockAdapter? adapter,
  List<Override> extraOverrides = const [],
}) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final client = adapter == null ? null : membershipClient(adapter);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        localeProvider.overrideWith((ref) => locale),
        clockProvider.overrideWithValue(membershipClock),
        if (client != null) ...[
          membersRepositoryProvider
              .overrideWithValue(MembersRepository(client: client)),
          auditRepositoryProvider
              .overrideWithValue(AuditRepository(client: client)),
        ],
        ...extraOverrides,
      ],
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: NeonTheme.dark(fontFamily: 'Vazirmatn'),
        locale: locale.locale,
        supportedLocales: [for (final l in AppLocale.supported) l.locale],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        builder: (context, child) => Directionality(
          textDirection: locale.textDirection,
          child: child ?? const SizedBox.shrink(),
        ),
        home: screen,
      ),
    ),
  );

  await pumpFrames(tester);
}

/// Without the shipped font the test engine measures every glyph as a full em
/// square, which reports Persian overflows that do not exist on a device.
Future<void> loadTestFonts() async {
  await _loadFont('Vazirmatn', [
    'assets/fonts/Vazirmatn-Regular.ttf',
    'assets/fonts/Vazirmatn-Bold.ttf',
  ]);
  await _loadFont('MaterialIcons', [
    '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
  ]);
}

String _flutterRoot() {
  final fromEnv = Platform.environment['FLUTTER_ROOT'];
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;

  var dir = File(Platform.resolvedExecutable).parent;
  while (dir.path != dir.parent.path) {
    if (Directory('${dir.path}/bin/cache/artifacts/material_fonts')
        .existsSync()) {
      return dir.path;
    }
    dir = dir.parent;
  }
  return '';
}

Future<void> _loadFont(String family, List<String> paths) async {
  final loader = FontLoader(family);

  for (final path in paths) {
    final file = File(path);
    if (!file.existsSync()) continue;
    loader.addFont(
      file.readAsBytes().then((bytes) => ByteData.view(bytes.buffer)),
    );
  }

  await loader.load();
}

// ------------------------------------------------------------------ fixtures

Map<String, Object?> memberJson({
  required String id,
  required String role,
  required String name,
  required String email,
  String? joinedAt = '2026-01-04T08:30:00Z',
}) =>
    {
      'id': id,
      'role': role,
      'joined_at': joinedAt,
      'user': {'id': 'u-$id', 'name': name, 'email': email},
    };

Map<String, Object?> invitationJson({
  required String id,
  required String email,
  required String role,
  required String status,
  String expiresAt = '2026-08-08T08:30:00Z',
}) =>
    {
      'id': id,
      'email': email,
      'role': role,
      'status': status,
      'expires_at': expiresAt,
      'created_at': '2026-07-25T08:30:00Z',
    };

Map<String, Object?> auditJson({
  required String id,
  required String action,
  Map<String, Object?>? before,
  Map<String, Object?>? after,
  String? userName = 'زهرا کریمی',
  String? ip,
  String createdAt = '2026-07-24T14:05:00Z',
  String? subjectType,
}) =>
    {
      'id': id,
      'action': action,
      'subject_type': subjectType,
      'subject_id': null,
      'before': before,
      'after': after,
      'ip': ip,
      'user_agent': null,
      'created_at': createdAt,
      'user': userName == null ? null : {'id': 'u-1', 'name': userName},
    };
