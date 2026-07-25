import 'package:dio/dio.dart';
import 'package:finora/data/local/token_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/security_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

/// The transport half of the security screens: what the server says becomes a
/// stable [SecurityException] code, never a sentence for the UI to print.
void main() {
  late MemoryTokenStore tokens;
  late List<RequestOptions> requests;

  SecurityRepository build(ResponseBody Function(RequestOptions) handler) {
    final adapter = MockAdapter(handler);
    requests = adapter.requests;

    return SecurityRepository(
      client: ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE', token: 'session-token'),
        adapter: adapter,
        policy: const RetryPolicy(maxAttempts: 1),
      ),
      tokens: tokens,
    );
  }

  setUp(() => tokens = MemoryTokenStore());

  test('status reads the enrolment state', () async {
    final security = build(
      (_) => MockAdapter.json({
        'data': {
          'enabled': true,
          'pending_confirmation': false,
          'confirmed_at': '2026-07-20T10:00:00+00:00',
          'recovery_codes_remaining': 7,
        },
      }),
    );

    final status = await security.twoFactorStatus();

    expect(status.enabled, isTrue);
    expect(status.recoveryCodesRemaining, 7);
    expect(status.confirmedAt, DateTime.utc(2026, 7, 20, 10));
    expect(requests.single.path, '/auth/2fa');
  });

  test('enable groups the secret so it can be typed', () async {
    final security = build(
      (_) => MockAdapter.json({
        'data': {
          'secret': 'JBSWY3DPEHPK3PXP',
          'otpauth_uri': 'otpauth://totp/Finora:ana@example.co?secret=JBSWY3DP',
          'confirmed': false,
        },
      }),
    );

    final enrollment = await security.enableTwoFactor();

    expect(enrollment.secret, 'JBSWY3DPEHPK3PXP');
    expect(enrollment.groupedSecret, 'JBSW Y3DP EHPK 3PXP');
    expect(enrollment.otpauthUri, startsWith('otpauth://'));
  });

  test('a rejected code arrives as a code, not as the server sentence',
      () async {
    final security = build(
      (_) => MockAdapter.error(
        'invalid_two_factor_code',
        message: 'That code is not valid.',
      ),
    );

    await expectLater(
      security.confirmTwoFactor('000000'),
      throwsA(
        isA<SecurityException>()
            .having((e) => e.code, 'code', 'invalid_two_factor_code')
            .having((e) => e.translationKey, 'key',
                'error.invalid_two_factor_code',)
            .having((e) => e.isRetryableCode, 'retryable', isTrue)
            .having((e) => e.isChallengeDead, 'dead', isFalse),
      ),
    );
  });

  test('a dead challenge is one code for every way it can die', () async {
    final security = build(
      (_) => MockAdapter.error(
        'two_factor_challenge_invalid',
        status: 401,
        message: 'This sign-in attempt is no longer valid.',
      ),
    );

    await expectLater(
      security.verifyChallenge(challenge: 'ch', code: '123456'),
      throwsA(
        isA<SecurityException>()
            .having((e) => e.isChallengeDead, 'dead', isTrue)
            .having((e) => e.statusCode, 'status', 401),
      ),
    );
  });

  test('verifying a challenge adopts the token it bought', () async {
    final security = build(
      (_) => MockAdapter.json({
        'data': {
          'token': 'second-factor-token',
          'user': {'id': '01JUSER', 'name': 'Ana', 'email': 'ana@example.co'},
          'workspaces': [
            {'id': '01JWORKSPACE', 'name': 'Ana', 'base_currency': 'IRR'},
          ],
        },
      }),
    );

    final verification = await security.verifyChallenge(
      challenge: 'challenge-token',
      code: '123456',
    );

    expect(verification.token, 'second-factor-token');
    expect(verification.user.email, 'ana@example.co');
    expect(await tokens.readToken(), 'second-factor-token');
    expect(await tokens.readWorkspaceId(), '01JWORKSPACE');
    expect(security.client.session.token, 'second-factor-token');

    final body = requests.single.data! as Map;
    expect(body['challenge'], 'challenge-token');
    expect(body['code'], '123456');
  });

  test('disable sends only the proof the user actually gave', () async {
    final security = build(
      (_) => MockAdapter.json({
        'data': {'two_factor_enabled': false},
      }),
    );

    await security.disableTwoFactor(password: 'hunter22', code: '');

    final body = requests.single.data! as Map;
    expect(body['password'], 'hunter22');
    expect(body.containsKey('code'), isFalse);
  });

  test('forgot-password reports nothing about the address it was given',
      () async {
    final security = build(
      (_) => MockAdapter.json({
        'data': {'status': 'password_reset_link_sent'},
      }),
    );

    await security.forgotPassword('nobody@example.co');

    expect(requests.single.path, '/auth/forgot-password');
    expect((requests.single.data! as Map)['email'], 'nobody@example.co');
  });

  test('a spent reset token keeps its code', () async {
    final security = build(
      (_) => MockAdapter.error('invalid_reset_token', message: 'Not valid.'),
    );

    await expectLater(
      security.resetPassword(
        email: 'ana@example.co',
        token: 'spent',
        password: 'a-new-password',
      ),
      throwsA(
        isA<SecurityException>()
            .having((e) => e.code, 'code', 'invalid_reset_token'),
      ),
    );
  });

  test('a login answered with a challenge is recognised as one', () {
    final challenge = LoginChallenge.fromLogin({
      'data': {
        'two_factor_required': true,
        'challenge': 'opaque-token',
        'expires_at': '2026-07-25T09:05:00+00:00',
      },
    });

    expect(challenge, isNotNull);
    expect(challenge!.token, 'opaque-token');
    expect(challenge.expiresAt, DateTime.utc(2026, 7, 25, 9, 5));

    expect(
      LoginChallenge.fromLogin({
        'data': {'token': 'a-real-token'},
      }),
      isNull,
    );
  });

  test('a transport failure still leaves as a security code', () async {
    final security = build((options) => throw connectionFailure(options));

    await expectLater(
      security.twoFactorStatus(),
      throwsA(
        isA<SecurityException>()
            .having((e) => e.code, 'code', 'network_unreachable'),
      ),
    );
  });
}
