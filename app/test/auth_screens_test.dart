import 'dart:io';

import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translations.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/auth_repository.dart';
import 'package:finora/data/local/token_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/security_repository.dart';
import 'package:finora/presentation/features/auth/auth_controller.dart';
import 'package:finora/presentation/features/auth/auth_gate.dart';
import 'package:finora/presentation/features/auth/register_screen.dart';
import 'package:finora/presentation/features/auth/sign_in_screen.dart';
import 'package:finora/presentation/features/auth/sign_out_button.dart';
import 'package:finora/presentation/features/auth/workspace_picker_screen.dart';
import 'package:finora/presentation/features/security/forgot_password_screen.dart';
import 'package:finora/presentation/features/security/security_providers.dart';
import 'package:finora/presentation/features/security/two_factor_challenge_screen.dart';
import 'package:finora/presentation/widgets/neon_button.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

/// Signing in, from the outside.
///
/// Two rules keep this file from hanging instead of failing, inherited from
/// `security_screens_test.dart`:
///
/// **Every request settles inside `tester.runAsync`.** `testWidgets` runs on a
/// fake clock Dio's real timers never reach, so awaiting a request under the
/// fake clock would simply never return.
///
/// **Nothing calls `pumpAndSettle`.** The gate shows a spinner while it reads
/// the stored token, and a spinner never settles.
typedef Route = ResponseBody Function(RequestOptions options);

String en(String key) => BundledTranslations.byLocale['en']![key]!;
String fa(String key) => BundledTranslations.byLocale['fa']![key]!;

Future<void> settle(WidgetTester tester, {int rounds = 4}) async {
  for (var i = 0; i < rounds; i++) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 5)),
    );
    await tester.pump(const Duration(milliseconds: 30));
  }
}

Route server(Map<String, Route> routes) => (options) {
      final route = routes[options.path];
      if (route == null) {
        return MockAdapter.json({'data': const <String, Object?>{}});
      }
      return route(options);
    };

Route always(ResponseBody body) => (_) => body;

/// One session shared by the auth and security repositories, exactly as
/// `FinoraBackend` wires them — the two-factor hand-off only works because the
/// token the challenge buys lands in the session the auth repository reads.
final class Harness {
  Harness(Route handler, {MemoryTokenStore? tokens})
      : adapter = MockAdapter(handler),
        tokens = tokens ?? MemoryTokenStore();

  final MockAdapter adapter;
  final MemoryTokenStore tokens;

  late final ApiClient client = ApiClient.create(
    session: ApiSession(deviceId: '01JDEVICE'),
    adapter: adapter,
    policy: const RetryPolicy(maxAttempts: 1),
  );

  late final AuthRepository auth =
      AuthRepository(client: client, tokens: tokens);

  late final SecurityRepository security =
      SecurityRepository(client: client, tokens: tokens);

  List<RequestOptions> get requests => adapter.requests;

  List<String> get paths => [for (final r in requests) r.path];

  Map<String, Object?> bodyOf(String path) =>
      (requests.lastWhere((r) => r.path == path).data! as Map)
          .cast<String, Object?>();
}

const _user = {'id': '01JUSER', 'name': 'Ana', 'email': 'ana@example.co'};

const _oneWorkspace = {
  'data': {
    'token': 'session-token',
    'user': _user,
    'workspaces': [
      {
        'id': '01JWORKSPACE',
        'name': 'خانه',
        'type': 'personal',
        'base_currency': 'IRR',
      },
    ],
  },
};

const _twoWorkspaces = {
  'data': {
    'token': 'session-token',
    'user': _user,
    'workspaces': [
      {
        'id': '01JWORKSPACE',
        'name': 'خانه',
        'type': 'personal',
        'base_currency': 'IRR',
      },
      {
        'id': '01JSHOP',
        'name': 'Shop',
        'type': 'business',
        'base_currency': 'TRY',
      },
    ],
  },
};

const _challengeIssued = {
  'data': {
    'two_factor_required': true,
    'challenge': 'challenge-token',
    'expires_at': '2026-07-25T09:05:00+00:00',
  },
};

const _verified = {
  'data': {
    'token': 'second-factor-token',
    'user': _user,
    'workspaces': [
      {'id': '01JWORKSPACE', 'name': 'خانه', 'base_currency': 'IRR'},
    ],
  },
};

const _registered = {
  'data': {
    'token': 'fresh-token',
    'user': _user,
    'workspace': {'id': '01JWORKSPACE', 'name': 'Ana'},
  },
};

/// Stands in for the shell: what a signed-in person is supposed to reach, and
/// nobody else.
class DemoHome extends StatelessWidget {
  const DemoHome({super.key});

  static const label = 'demo shell';

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [Text(label), SignOutButton()],
        ),
      ),
    );
  }
}

void main() {
  setUpAll(() async {
    await _loadFont('Vazirmatn', [
      'assets/fonts/Vazirmatn-Regular.ttf',
      'assets/fonts/Vazirmatn-Bold.ttf',
    ]);
    await _loadFont('MaterialIcons', [
      '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
    ]);
  });

  Future<void> pumpGate(
    WidgetTester tester, {
    Harness? harness,
    AppLocale locale = AppLocale.en,
  }) async {
    tester.view.physicalSize = const Size(430, 932);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => locale),
          // Nothing is overridden when there is no harness: that is the default,
          // backend-less app, and it must reach DemoHome on its own.
          if (harness != null) ...[
            authBackendProvider.overrideWithValue(harness.auth),
            securityRepositoryProvider.overrideWithValue(harness.security),
          ],
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
          home: const AuthGate(child: DemoHome()),
        ),
      ),
    );
    await settle(tester);
  }

  Future<void> signIn(
    WidgetTester tester, {
    String email = 'ana@example.co',
    String password = 'password123',
    AppLocale locale = AppLocale.en,
  }) async {
    final t = locale == AppLocale.fa ? fa : en;

    await tester.enterText(find.byType(TextField).at(0), email);
    await tester.enterText(find.byType(TextField).at(1), password);
    await tester.pump();
    await tester.tap(find.widgetWithText(NeonButton, t('auth.signIn')));
    await settle(tester);
  }

  testWidgets('with no backend configured there is no sign-in screen at all',
      (tester) async {
    await pumpGate(tester);

    expect(find.text(DemoHome.label), findsOneWidget);
    expect(find.byType(SignInScreen), findsNothing);
    expect(find.byType(AuthSplash), findsNothing);
    // Nothing to sign out of, so the button is not there to be tapped.
    expect(find.text(en('auth.signOut')), findsNothing);
  });

  testWidgets('a fresh install is asked who it is', (tester) async {
    await pumpGate(tester, harness: Harness(always(MockAdapter.json({}))));

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(DemoHome.label), findsNothing);
    expect(find.text(en('auth.signInHint')), findsOneWidget);
  });

  testWidgets('a wrong password is refused in the app wording, not the server',
      (tester) async {
    final harness = Harness(
      server({
        '/auth/login': always(
          MockAdapter.error(
            'invalid_credentials',
            status: 401,
            message: 'Server-side English nobody should ever read.',
          ),
        ),
      }),
    );

    await pumpGate(tester, harness: harness);
    await signIn(tester, password: 'nope');

    expect(find.text(en('error.invalid_credentials')), findsOneWidget);
    expect(
      find.text('Server-side English nobody should ever read.'),
      findsNothing,
    );
    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(DemoHome.label), findsNothing);
    expect(await harness.tokens.readToken(), isNull);
  });

  testWidgets('the same refusal is Persian on a Persian screen', (tester) async {
    final harness = Harness(
      server({
        '/auth/login': always(
          MockAdapter.error(
            'invalid_credentials',
            status: 401,
            message: 'Server-side English nobody should ever read.',
          ),
        ),
      }),
    );

    await pumpGate(tester, harness: harness, locale: AppLocale.fa);
    await signIn(tester, password: 'nope', locale: AppLocale.fa);

    expect(find.text(fa('error.invalid_credentials')), findsOneWidget);
    expect(
      find.text('Server-side English nobody should ever read.'),
      findsNothing,
    );
    expect(
      Directionality.of(tester.element(find.byType(Scaffold).first)),
      TextDirection.rtl,
    );
  });

  testWidgets('an empty email is refused without asking the server',
      (tester) async {
    final harness = Harness(always(MockAdapter.json(_oneWorkspace)));

    await pumpGate(tester, harness: harness);
    await tester.tap(find.widgetWithText(NeonButton, en('auth.signIn')));
    await settle(tester);

    expect(find.text(en('auth.emailRequired')), findsOneWidget);
    expect(harness.paths, isEmpty);
  });

  testWidgets('a correct password with one workspace opens the app',
      (tester) async {
    final harness = Harness(
      server({'/auth/login': always(MockAdapter.json(_oneWorkspace))}),
    );

    await pumpGate(tester, harness: harness);
    await signIn(tester);

    expect(find.text(DemoHome.label), findsOneWidget);
    expect(find.byType(SignInScreen), findsNothing);
    expect(harness.bodyOf('/auth/login')['email'], 'ana@example.co');
    expect(await harness.tokens.readToken(), 'session-token');
    // The workspace header is set before anything else can need it.
    expect(harness.auth.session.workspaceId, '01JWORKSPACE');
  });

  testWidgets('more than one workspace has to be picked before the app opens',
      (tester) async {
    final harness = Harness(
      server({'/auth/login': always(MockAdapter.json(_twoWorkspaces))}),
    );

    await pumpGate(tester, harness: harness);
    await signIn(tester);

    expect(find.byType(WorkspacePickerScreen), findsOneWidget);
    expect(find.text(DemoHome.label), findsNothing);
    expect(find.text('Shop'), findsOneWidget);

    await tester.tap(find.text('Shop'));
    await settle(tester);

    expect(find.text(DemoHome.label), findsOneWidget);
    expect(harness.auth.session.workspaceId, '01JSHOP');
    expect(await harness.tokens.readWorkspaceId(), '01JSHOP');
  });

  testWidgets('a second factor hands off to the challenge screen and back',
      (tester) async {
    final harness = Harness(
      server({
        '/auth/login': always(MockAdapter.json(_challengeIssued)),
        '/auth/2fa/verify': always(MockAdapter.json(_verified)),
      }),
    );

    await pumpGate(tester, harness: harness);
    await signIn(tester);

    // The password alone bought nothing: no token was stored.
    expect(find.byType(TwoFactorChallengeScreen), findsOneWidget);
    expect(find.text(DemoHome.label), findsNothing);
    expect(await harness.tokens.readToken(), isNull);

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.pump();
    await tester.tap(find.widgetWithText(NeonButton, en('security.verify')));
    await settle(tester);

    expect(harness.bodyOf('/auth/2fa/verify')['challenge'], 'challenge-token');
    expect(find.text(DemoHome.label), findsOneWidget);
    expect(await harness.tokens.readToken(), 'second-factor-token');
    expect(harness.auth.session.workspaceId, '01JWORKSPACE');
  });

  testWidgets('a dead challenge sends the person back to sign in',
      (tester) async {
    final harness = Harness(
      server({
        '/auth/login': always(MockAdapter.json(_challengeIssued)),
        '/auth/2fa/verify': always(
          MockAdapter.error('two_factor_challenge_invalid', status: 422),
        ),
      }),
    );

    await pumpGate(tester, harness: harness);
    await signIn(tester);

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.pump();
    await tester.tap(find.widgetWithText(NeonButton, en('security.verify')));
    await settle(tester);

    expect(find.text(en('security.challengeDead')), findsOneWidget);

    await tester.tap(
      find.widgetWithText(NeonButton, en('security.backToSignIn')),
    );
    await settle(tester);

    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.byType(TwoFactorChallengeScreen), findsNothing);
  });

  testWidgets('a stored token opens the app on cold start, offline',
      (tester) async {
    final tokens = MemoryTokenStore();
    await tokens.writeToken('persisted');
    await tokens.writeWorkspaceId('01JWORKSPACE');

    final harness = Harness(
      (options) => throw connectionFailure(options),
      tokens: tokens,
    );

    await pumpGate(tester, harness: harness);

    expect(find.text(DemoHome.label), findsOneWidget);
    expect(find.byType(SignInScreen), findsNothing);
    expect(harness.auth.session.token, 'persisted');
    // Restoring is a local act: the app opens even with no server to ask.
    expect(harness.paths, isEmpty);
  });

  testWidgets('a stored token with no workspace asks which one', (tester) async {
    final tokens = MemoryTokenStore();
    await tokens.writeToken('persisted');

    final harness = Harness(
      server({
        '/workspaces': always(
          MockAdapter.json({
            'data': [
              {
                'id': '01JWORKSPACE',
                'name': 'خانه',
                'type': 'personal',
                'base_currency': 'IRR',
              },
              {
                'id': '01JSHOP',
                'name': 'Shop',
                'type': 'business',
                'base_currency': 'TRY',
              },
            ],
          }),
        ),
      }),
      tokens: tokens,
    );

    await pumpGate(tester, harness: harness);

    expect(find.byType(WorkspacePickerScreen), findsOneWidget);
    expect(harness.paths, contains('/workspaces'));
  });

  testWidgets('registering creates the account and opens the app',
      (tester) async {
    final harness = Harness(
      server({
        '/auth/register': always(MockAdapter.json(_registered, status: 201)),
      }),
    );

    await pumpGate(tester, harness: harness);

    await tester.tap(find.widgetWithText(NeonButton, en('auth.createAccount')));
    await settle(tester);
    expect(find.byType(RegisterScreen), findsOneWidget);

    final fields = find.descendant(
      of: find.byType(RegisterScreen),
      matching: find.byType(TextField),
    );
    await tester.enterText(fields.at(0), 'Ana');
    await tester.enterText(fields.at(1), 'ana@example.co');
    await tester.enterText(fields.at(2), 'password123');
    await tester.enterText(fields.at(3), 'password123');
    await tester.pump();

    await tester.tap(
      find.descendant(
        of: find.byType(RegisterScreen),
        matching: find.widgetWithText(NeonButton, en('auth.createAccount')),
      ),
    );
    await settle(tester, rounds: 16);

    expect(harness.bodyOf('/auth/register')['name'], 'Ana');
    expect(find.byType(RegisterScreen), findsNothing);
    expect(find.text(DemoHome.label), findsOneWidget);
    expect(await harness.tokens.readToken(), 'fresh-token');
  });

  testWidgets('two passwords that differ never reach the server',
      (tester) async {
    final harness = Harness(always(MockAdapter.json(_registered, status: 201)));

    await pumpGate(tester, harness: harness);
    await tester.tap(find.widgetWithText(NeonButton, en('auth.createAccount')));
    await settle(tester);

    final fields = find.descendant(
      of: find.byType(RegisterScreen),
      matching: find.byType(TextField),
    );
    await tester.enterText(fields.at(0), 'Ana');
    await tester.enterText(fields.at(1), 'ana@example.co');
    await tester.enterText(fields.at(2), 'password123');
    await tester.enterText(fields.at(3), 'password124');
    await tester.pump();

    await tester.tap(
      find.descendant(
        of: find.byType(RegisterScreen),
        matching: find.widgetWithText(NeonButton, en('auth.createAccount')),
      ),
    );
    await settle(tester);

    expect(find.text(en('auth.passwordMismatch')), findsOneWidget);
    expect(harness.paths, isEmpty);
  });

  testWidgets('signing out ends the session on the server and on the device',
      (tester) async {
    final harness = Harness(
      server({
        '/auth/login': always(MockAdapter.json(_oneWorkspace)),
        '/auth/logout': always(
          MockAdapter.json({'data': const <String, Object?>{}}, status: 204),
        ),
      }),
    );

    await pumpGate(tester, harness: harness);
    await signIn(tester);
    expect(find.text(DemoHome.label), findsOneWidget);

    await tester.tap(find.widgetWithText(NeonButton, en('auth.signOut')));
    await settle(tester);

    expect(harness.paths, contains('/auth/logout'));
    expect(await harness.tokens.readToken(), isNull);
    expect(harness.auth.session.token, isNull);
    expect(find.byType(SignInScreen), findsOneWidget);
    expect(find.text(DemoHome.label), findsNothing);
  });

  testWidgets('a server that cannot be reached still signs the device out',
      (tester) async {
    final harness = Harness((options) {
      if (options.path == '/auth/login') {
        return MockAdapter.json(_oneWorkspace);
      }
      throw connectionFailure(options);
    });

    await pumpGate(tester, harness: harness);
    await signIn(tester);
    await tester.tap(find.widgetWithText(NeonButton, en('auth.signOut')));
    await settle(tester);

    expect(await harness.tokens.readToken(), isNull);
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('the forgotten-password screen is reachable from sign-in',
      (tester) async {
    await pumpGate(tester, harness: Harness(always(MockAdapter.json({}))));

    await tester.tap(
      find.widgetWithText(NeonButton, en('auth.forgotPassword')),
    );
    await settle(tester);

    expect(find.byType(ForgotPasswordScreen), findsOneWidget);
    expect(find.text(en('security.forgotTitle')), findsWidgets);
  });

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    testWidgets('every auth screen builds in ${locale.code}', (tester) async {
      final harness = Harness(
        server({'/auth/login': always(MockAdapter.json(_twoWorkspaces))}),
      );

      await pumpGate(tester, harness: harness, locale: locale);
      expect(tester.takeException(), isNull, reason: 'sign-in threw');

      await signIn(tester, locale: locale);
      expect(tester.takeException(), isNull, reason: 'the picker threw');
      expect(find.byType(WorkspacePickerScreen), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.byType(Scaffold).first)),
        locale.textDirection,
      );
    });
  }
}

/// Locates the SDK so the icon font can be loaded without hard-coding a path.
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
