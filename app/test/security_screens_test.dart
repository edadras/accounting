import 'dart:io';

import 'package:dio/dio.dart';
import 'package:finora/core/date/date_formatter.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translations.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/local/token_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/security_repository.dart';
import 'package:finora/presentation/features/security/forgot_password_screen.dart';
import 'package:finora/presentation/features/security/reset_password_screen.dart';
import 'package:finora/presentation/features/security/security_providers.dart';
import 'package:finora/presentation/features/security/two_factor_challenge_screen.dart';
import 'package:finora/presentation/features/security/two_factor_screen.dart';
import 'package:finora/presentation/features/security/two_factor_setup_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

/// The security screens, driven the way a person meets them.
///
/// Two rules keep this file from hanging instead of failing, both inherited
/// from `offline_ui_test.dart`:
///
/// **Every request settles inside `tester.runAsync`.** `testWidgets` runs on a
/// fake clock that Dio's real-timer completions never reach, so an `await` on a
/// request would simply never return — a silent freeze `--timeout` cannot cut.
///
/// **Nothing calls `pumpAndSettle`.** These screens show a
/// `CircularProgressIndicator` while they load, which never stops animating, so
/// `pumpAndSettle` would wait out its whole budget. Frames are pumped by hand.
typedef Route = ResponseBody Function(RequestOptions options);

String en(String key) => BundledTranslations.byLocale['en']![key]!;
String fa(String key) => BundledTranslations.byLocale['fa']![key]!;

/// Lets both the real and the fake clock make progress between assertions.
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

final class Backend {
  Backend(Route handler, {MemoryTokenStore? tokens})
      : adapter = MockAdapter(handler),
        tokens = tokens ?? MemoryTokenStore();

  final MockAdapter adapter;
  final MemoryTokenStore tokens;

  late final SecurityRepository repository = SecurityRepository(
    client: ApiClient.create(
      session: ApiSession(deviceId: '01JDEVICE', token: 'session-token'),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1),
    ),
    tokens: tokens,
  );

  List<RequestOptions> get requests => adapter.requests;

  Map<String, Object?> bodyOf(String path) =>
      (requests.lastWhere((r) => r.path == path).data! as Map)
          .cast<String, Object?>();
}

const _statusOff = {
  'data': {
    'enabled': false,
    'pending_confirmation': false,
    'recovery_codes_remaining': 0,
  },
};

const _statusOn = {
  'data': {
    'enabled': true,
    'pending_confirmation': false,
    'confirmed_at': '2026-07-20T10:00:00+00:00',
    'recovery_codes_remaining': 8,
  },
};

const _enrollment = {
  'data': {
    'secret': 'JBSWY3DPEHPK3PXP',
    'otpauth_uri':
        'otpauth://totp/Finora:ana@example.co?secret=JBSWY3DPEHPK3PXP&issuer=Finora',
    'confirmed': false,
  },
};

const _recoveryCodes = ['A1B2C-D3E4F', 'G5H6I-J7K8L', 'M9N0O-P1Q2R'];

const _confirmed = {
  'data': {
    'confirmed_at': '2026-07-25T09:00:00+00:00',
    'recovery_codes': _recoveryCodes,
  },
};

const _verified = {
  'data': {
    'token': 'second-factor-token',
    'user': {'id': '01JUSER', 'name': 'Ana', 'email': 'ana@example.co'},
    'workspaces': [
      {'id': '01JWORKSPACE', 'name': 'Ana', 'base_currency': 'IRR'},
    ],
  },
};

void main() {
  setUpAll(() async {
    // Without the shipped font every glyph is measured as a full em square,
    // which reports overflows that do not exist on a device.
    await _loadFont('Vazirmatn', [
      'assets/fonts/Vazirmatn-Regular.ttf',
      'assets/fonts/Vazirmatn-Bold.ttf',
    ]);
    await _loadFont('MaterialIcons', [
      '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
    ]);
  });

  Future<void> pumpScreen(
    WidgetTester tester,
    Widget screen, {
    required Backend backend,
    AppLocale locale = AppLocale.en,
  }) async {
    tester.view.physicalSize = const Size(430, 932);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => locale),
          securityRepositoryProvider.overrideWithValue(backend.repository),
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
    await settle(tester);
  }

  testWidgets('enabling shows the key to type and the link to open',
      (tester) async {
    final backend = Backend(
      server({'/auth/2fa/enable': always(MockAdapter.json(_enrollment))}),
    );

    await pumpScreen(tester, const TwoFactorSetupScreen(), backend: backend);

    // Grouped, because this is typed by hand when the phone cannot scan.
    expect(find.text('JBSW Y3DP EHPK 3PXP'), findsOneWidget);
    expect(find.textContaining('otpauth://totp/Finora'), findsOneWidget);
    expect(find.text(en('security.secretLabel')), findsOneWidget);
    expect(find.text(en('security.uriLabel')), findsOneWidget);
  });

  testWidgets('confirming shows the recovery codes once and only once',
      (tester) async {
    final backend = Backend(
      server({
        '/auth/2fa/enable': always(MockAdapter.json(_enrollment)),
        '/auth/2fa/confirm': always(MockAdapter.json(_confirmed)),
      }),
    );

    await pumpScreen(tester, const TwoFactorSetupScreen(), backend: backend);

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.pump();
    await tester.tap(find.text(en('security.confirm')));
    await settle(tester);

    for (final code in _recoveryCodes) {
      expect(find.text(code), findsOneWidget, reason: 'code $code not shown');
    }
    expect(find.text(en('security.recoveryOnce')), findsOneWidget);
    expect(backend.bodyOf('/auth/2fa/confirm')['code'], '123456');

    await tester.tap(find.text(en('security.recoverySaved')));
    await settle(tester);

    // Acknowledged, and therefore gone: the server cannot show them again.
    for (final code in _recoveryCodes) {
      expect(find.text(code), findsNothing, reason: 'code $code lingered');
    }
    expect(find.text(en('security.twoFactorEnabled')), findsOneWidget);
  });

  testWidgets('a wrong code is refused in the app wording, not the server one',
      (tester) async {
    final backend = Backend(
      server({
        '/auth/2fa/enable': always(MockAdapter.json(_enrollment)),
        '/auth/2fa/confirm': always(
          MockAdapter.error(
            'invalid_two_factor_code',
            message: 'That code is not valid.',
          ),
        ),
      }),
    );

    await pumpScreen(tester, const TwoFactorSetupScreen(), backend: backend);

    await tester.enterText(find.byType(TextField).first, '000000');
    await tester.pump();
    await tester.tap(find.text(en('security.confirm')));
    await settle(tester);

    expect(find.text(en('error.invalid_two_factor_code')), findsOneWidget);
    expect(find.text('That code is not valid.'), findsNothing);
    expect(find.text(_recoveryCodes.first), findsNothing);
  });

  testWidgets('the same refusal is Persian on a Persian screen', (tester) async {
    final backend = Backend(
      server({
        '/auth/2fa/enable': always(MockAdapter.json(_enrollment)),
        '/auth/2fa/confirm': always(
          MockAdapter.error(
            'invalid_two_factor_code',
            message: 'That code is not valid.',
          ),
        ),
      }),
    );

    await pumpScreen(
      tester,
      const TwoFactorSetupScreen(),
      backend: backend,
      locale: AppLocale.fa,
    );

    await tester.enterText(find.byType(TextField).first, '000000');
    await tester.pump();
    await tester.tap(find.text(fa('security.confirm')));
    await settle(tester);

    expect(find.text(fa('error.invalid_two_factor_code')), findsOneWidget);
    expect(find.text('That code is not valid.'), findsNothing);
  });

  testWidgets('the challenge screen trades a code for a token', (tester) async {
    final backend = Backend(
      server({'/auth/2fa/verify': always(MockAdapter.json(_verified))}),
    );
    TwoFactorVerification? verified;

    await pumpScreen(
      tester,
      TwoFactorChallengeScreen(
        challenge: const LoginChallenge(token: 'challenge-token'),
        onVerified: (result) => verified = result,
      ),
      backend: backend,
    );

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.pump();
    await tester.tap(find.text(en('security.verify')));
    await settle(tester);

    expect(verified?.token, 'second-factor-token');
    expect(await backend.tokens.readToken(), 'second-factor-token');
    expect(backend.bodyOf('/auth/2fa/verify')['challenge'], 'challenge-token');
    expect(find.text(en('security.signedIn')), findsOneWidget);
  });

  testWidgets('a wrong code at sign-in counts down the tries left',
      (tester) async {
    final backend = Backend(
      server({
        '/auth/2fa/verify': always(
          MockAdapter.error(
            'invalid_two_factor_code',
            message: 'That code is not valid.',
          ),
        ),
      }),
    );

    await pumpScreen(
      tester,
      const TwoFactorChallengeScreen(
        challenge: LoginChallenge(token: 'challenge-token'),
      ),
      backend: backend,
    );

    await tester.enterText(find.byType(TextField).first, '000000');
    await tester.pump();
    await tester.tap(find.text(en('security.verify')));
    await settle(tester);

    expect(find.text(en('error.invalid_two_factor_code')), findsOneWidget);
    expect(
      find.text(
        en('security.attemptsLeft')
            .replaceAll(':count', DateFormatter.number(4, 'en')),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a dead challenge says so and sends the user back to sign in',
      (tester) async {
    final backend = Backend(
      server({
        '/auth/2fa/verify': always(
          MockAdapter.error(
            'two_factor_challenge_invalid',
            status: 401,
            message: 'This sign-in attempt is no longer valid.',
          ),
        ),
      }),
    );

    await pumpScreen(
      tester,
      const TwoFactorChallengeScreen(
        challenge: LoginChallenge(token: 'expired-token'),
      ),
      backend: backend,
    );

    await tester.enterText(find.byType(TextField).first, '123456');
    await tester.pump();
    await tester.tap(find.text(en('security.verify')));
    await settle(tester);

    expect(find.text(en('security.challengeDead')), findsOneWidget);
    expect(find.text(en('security.backToSignIn')), findsOneWidget);
    expect(find.text('This sign-in attempt is no longer valid.'), findsNothing);
    // Nothing left to guess against, so the code field is gone.
    expect(find.byType(TextField), findsNothing);
  });

  testWidgets('a recovery code gets the user in when the app is gone',
      (tester) async {
    final backend = Backend(
      server({'/auth/2fa/verify': always(MockAdapter.json(_verified))}),
    );

    await pumpScreen(
      tester,
      const TwoFactorChallengeScreen(
        challenge: LoginChallenge(token: 'challenge-token'),
      ),
      backend: backend,
    );

    await tester.tap(find.text(en('security.useRecoveryCode')));
    await tester.pump();

    expect(find.text(en('security.recoveryCodeLabel')), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'a1b2c-d3e4f');
    await tester.pump();
    await tester.tap(find.text(en('security.verify')));
    await settle(tester);

    // Typed in either case, sent the way the codes were issued.
    expect(backend.bodyOf('/auth/2fa/verify')['code'], 'A1B2C-D3E4F');
    expect(find.text(en('security.signedIn')), findsOneWidget);
  });

  testWidgets('forgot-password answers a known and an unknown address alike',
      (tester) async {
    Future<void> ask(WidgetTester tester, String email) async {
      final backend = Backend(
        server({
          '/auth/forgot-password': always(
            MockAdapter.json({
              'data': {'status': 'password_reset_link_sent'},
            }),
          ),
        }),
      );

      // A fresh key, or the second run would reuse the first screen's state
      // and its already-sent confirmation.
      await pumpScreen(
        tester,
        ForgotPasswordScreen(key: ValueKey(email)),
        backend: backend,
      );

      await tester.enterText(find.byType(TextField).first, email);
      await tester.pump();
      await tester.tap(find.text(en('security.forgotSend')));
      await settle(tester);

      expect(find.text(en('security.forgotSent')), findsOneWidget);
      // And the screen says why the answer is deliberately uninformative.
      expect(find.text(en('security.forgotSameAnswer')), findsOneWidget);
      expect(backend.bodyOf('/auth/forgot-password')['email'], email);
    }

    await ask(tester, 'ana@example.co');
    await ask(tester, 'nobody-at-all@example.co');
  });

  testWidgets('a spent reset code is refused in the app wording',
      (tester) async {
    final backend = Backend(
      server({
        '/auth/reset-password': always(
          MockAdapter.error(
            'invalid_reset_token',
            message: 'This password reset link is not valid or has expired.',
          ),
        ),
      }),
    );

    await pumpScreen(
      tester,
      const ResetPasswordScreen(email: 'ana@example.co', token: 'spent-code'),
      backend: backend,
    );

    await tester.enterText(find.byType(TextField).last, 'a-long-new-password');
    await tester.pump();
    await tester.tap(find.text(en('security.resetSubmit')));
    await settle(tester);

    expect(find.text(en('error.invalid_reset_token')), findsOneWidget);
    expect(find.text(en('security.resetDone')), findsNothing);
  });

  testWidgets('a short new password is caught before it reaches the server',
      (tester) async {
    final backend = Backend(server(const {}));

    await pumpScreen(
      tester,
      const ResetPasswordScreen(email: 'ana@example.co', token: 'code'),
      backend: backend,
    );

    await tester.enterText(find.byType(TextField).last, 'short');
    await tester.pump();
    await tester.tap(find.text(en('security.resetSubmit')));
    await settle(tester);

    expect(find.text(en('security.passwordTooShort')), findsOneWidget);
    expect(backend.requests, isEmpty);
  });

  testWidgets('the status screen warns while the second factor is off',
      (tester) async {
    final backend = Backend(
      server({'/auth/2fa': always(MockAdapter.json(_statusOff))}),
    );

    await pumpScreen(tester, const TwoFactorScreen(), backend: backend);

    expect(find.text(en('security.twoFactorOff')), findsOneWidget);
    expect(find.text(en('security.twoFactorOffHint')), findsOneWidget);
    expect(find.text(en('security.turnOn')), findsOneWidget);
  });

  testWidgets('turning the second factor off costs a password', (tester) async {
    var enabled = true;
    final backend = Backend(
      server({
        '/auth/2fa': (_) => MockAdapter.json(enabled ? _statusOn : _statusOff),
        '/auth/2fa/disable': (options) {
          final body = (options.data! as Map).cast<String, Object?>();
          if (body['password'] == null && body['code'] == null) {
            return MockAdapter.error('two_factor_confirmation_required');
          }
          enabled = false;
          return MockAdapter.json({
            'data': {'two_factor_enabled': false},
          });
        },
      }),
    );

    await pumpScreen(tester, const TwoFactorScreen(), backend: backend);

    expect(find.text(en('security.twoFactorOn')), findsOneWidget);

    await tester.tap(find.text(en('security.turnOff')));
    await tester.pump();

    expect(find.text(en('security.disableHint')), findsOneWidget);

    // Nothing typed: the server refuses, and the refusal is translated.
    await tester.tap(find.text(en('security.disableConfirm')));
    await settle(tester);
    expect(
      find.text(en('error.two_factor_confirmation_required')),
      findsOneWidget,
    );

    await tester.enterText(find.byType(TextField).first, 'hunter22');
    await tester.pump();
    await tester.tap(find.text(en('security.disableConfirm')));
    await settle(tester);

    expect(find.text(en('security.twoFactorDisabled')), findsOneWidget);
    expect(find.text(en('security.twoFactorOff')), findsOneWidget);
  });

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    testWidgets('every security screen builds in ${locale.code}',
        (tester) async {
      final screens = <Widget>[
        const TwoFactorScreen(),
        const TwoFactorSetupScreen(),
        const TwoFactorChallengeScreen(
          challenge: LoginChallenge(token: 'challenge-token'),
        ),
        const ForgotPasswordScreen(),
        const ResetPasswordScreen(),
      ];

      for (final screen in screens) {
        final backend = Backend(
          server({
            '/auth/2fa': always(MockAdapter.json(_statusOff)),
            '/auth/2fa/enable': always(MockAdapter.json(_enrollment)),
          }),
        );

        await pumpScreen(tester, screen, backend: backend, locale: locale);

        expect(
          tester.takeException(),
          isNull,
          reason: '${screen.runtimeType} threw in ${locale.code}',
        );
        expect(
          Directionality.of(tester.element(find.byType(Scaffold).first)),
          locale.textDirection,
          reason: '${screen.runtimeType} laid out the wrong way',
        );
      }
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
