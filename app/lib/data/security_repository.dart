import 'dart:math' as math;

import 'package:collection/collection.dart';

import 'auth_repository.dart';
import 'local/token_store.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';

/// A refusal by the server's security rules, carrying its stable, English,
/// machine-readable `code`.
///
/// Same contract as [ApiException]: the UI renders [translationKey] and never
/// the server's prose, so a Persian user is never shown an English sentence and
/// the wording stays testable.
final class SecurityException implements Exception {
  const SecurityException({
    required this.code,
    this.details = const {},
    this.statusCode,
  });

  factory SecurityException.from(ApiException error) => SecurityException(
        code: error.code,
        details: error.details,
        statusCode: error.statusCode,
      );

  final String code;
  final Map<String, List<String>> details;
  final int? statusCode;

  static const codeAlreadyEnabled = 'two_factor_already_enabled';
  static const codeNotEnabled = 'two_factor_not_enabled';
  static const codeInvalidCode = 'invalid_two_factor_code';
  static const codeConfirmationRequired = 'two_factor_confirmation_required';
  static const codeChallengeInvalid = 'two_factor_challenge_invalid';
  static const codeTooManyAttempts = 'too_many_attempts';
  static const codeInvalidResetToken = 'invalid_reset_token';

  String get translationKey => 'error.$code';

  /// The challenge is gone — expired, spent, or out of attempts. The server
  /// answers the same way for all three on purpose, so the UI must too: there
  /// is nothing left to retry against, only a fresh sign-in.
  bool get isChallengeDead => code == codeChallengeInvalid;

  /// A wrong code that still leaves the user something to try again with.
  bool get isRetryableCode => code == codeInvalidCode;

  @override
  String toString() => 'SecurityException($code, status: $statusCode)';
}

final class TwoFactorStatus {
  const TwoFactorStatus({
    required this.enabled,
    required this.pendingConfirmation,
    this.confirmedAt,
    this.recoveryCodesRemaining = 0,
  });

  factory TwoFactorStatus.fromJson(Map<String, Object?> json) =>
      TwoFactorStatus(
        enabled: json['enabled'] == true,
        pendingConfirmation: json['pending_confirmation'] == true,
        confirmedAt: DateTime.tryParse(json['confirmed_at'] as String? ?? ''),
        recoveryCodesRemaining:
            (json['recovery_codes_remaining'] as num?)?.toInt() ?? 0,
      );

  final bool enabled;

  /// A secret was minted but never confirmed: sign-in is unchanged, and the
  /// enrolment can be finished or thrown away.
  final bool pendingConfirmation;
  final DateTime? confirmedAt;
  final int recoveryCodesRemaining;

  static const off = TwoFactorStatus(enabled: false, pendingConfirmation: false);
}

final class TwoFactorEnrollment {
  const TwoFactorEnrollment({required this.secret, required this.otpauthUri});

  factory TwoFactorEnrollment.fromJson(Map<String, Object?> json) =>
      TwoFactorEnrollment(
        secret: json['secret'] as String? ?? '',
        otpauthUri: json['otpauth_uri'] as String? ?? '',
      );

  final String secret;
  final String otpauthUri;

  /// The secret in blocks, because it is typed by hand whenever the phone
  /// cannot scan and an unbroken 32-character run is where people slip.
  String get groupedSecret => group(secret);

  static String group(String value, {int size = 4}) {
    final buffer = StringBuffer();
    for (var i = 0; i < value.length; i += size) {
      if (i > 0) buffer.write(' ');
      buffer.write(value.substring(i, math.min(i + size, value.length)));
    }
    return buffer.toString();
  }
}

final class TwoFactorConfirmation {
  const TwoFactorConfirmation({required this.recoveryCodes, this.confirmedAt});

  factory TwoFactorConfirmation.fromJson(Map<String, Object?> json) =>
      TwoFactorConfirmation(
        recoveryCodes: [
          for (final code in json['recovery_codes'] as List? ?? const [])
            '$code',
        ],
        confirmedAt: DateTime.tryParse(json['confirmed_at'] as String? ?? ''),
      );

  /// Returned by the server exactly once. Nothing here persists them, because
  /// storing them next to the password they protect would defeat them.
  final List<String> recoveryCodes;
  final DateTime? confirmedAt;
}

/// The half-finished sign-in `POST /auth/login` answers with when the account
/// has a confirmed second factor.
final class LoginChallenge {
  const LoginChallenge({required this.token, this.expiresAt});

  final String token;
  final DateTime? expiresAt;

  /// How many wrong codes the server accepts before the challenge dies.
  /// Mirrors `TwoFactorChallenge::MAX_ATTEMPTS`; the server never sends a
  /// remaining count, so the screen counts down from here.
  static const maxAttempts = 5;

  /// Reads a login response, returning null when the password alone was enough.
  static LoginChallenge? fromLogin(Map<String, Object?> response) {
    final data = (response['data'] as Map?)?.cast<String, Object?>();
    if (data == null || data['two_factor_required'] != true) return null;

    return LoginChallenge(
      token: data['challenge'] as String? ?? '',
      expiresAt: DateTime.tryParse(data['expires_at'] as String? ?? ''),
    );
  }
}

final class TwoFactorVerification {
  const TwoFactorVerification({
    required this.token,
    required this.user,
    this.workspaceIds = const [],
  });

  factory TwoFactorVerification.fromJson(Map<String, Object?> json) =>
      TwoFactorVerification(
        token: json['token'] as String? ?? '',
        user: AuthUser.fromJson(
          (json['user'] as Map?)?.cast<String, Object?>() ?? const {},
        ),
        workspaceIds: [
          for (final item in json['workspaces'] as List? ?? const [])
            if (item is Map && item['id'] != null) '${item['id']}',
        ],
      );

  final String token;
  final AuthUser user;
  final List<String> workspaceIds;
}

/// Two-factor enrolment and password recovery.
///
/// Every failure leaves here as a [SecurityException] so no screen has to know
/// what Dio or an HTTP status is, and no screen can accidentally print the
/// server's English message.
final class SecurityRepository {
  SecurityRepository({required this.client, this.tokens});

  final ApiClient client;

  /// Optional because the challenge screen is the only caller that ends up
  /// holding a token, and the reset screens run with no session at all.
  final TokenStore? tokens;

  Future<TwoFactorStatus> twoFactorStatus() async =>
      TwoFactorStatus.fromJson(await _data(() => client.get('/auth/2fa')));

  Future<TwoFactorEnrollment> enableTwoFactor() async =>
      TwoFactorEnrollment.fromJson(
        await _data(() => client.post('/auth/2fa/enable')),
      );

  Future<TwoFactorConfirmation> confirmTwoFactor(String code) async =>
      TwoFactorConfirmation.fromJson(
        await _data(
          () => client.post('/auth/2fa/confirm', body: {'code': code}),
        ),
      );

  Future<void> disableTwoFactor({String? password, String? code}) async {
    await _data(
      () => client.post('/auth/2fa/disable', body: {
        if (password != null && password.isNotEmpty) 'password': password,
        if (code != null && code.isNotEmpty) 'code': code,
      },),
    );
  }

  /// Trades a challenge plus a code — or a recovery code — for the token login
  /// withheld, and adopts it the way a sign-in would.
  Future<TwoFactorVerification> verifyChallenge({
    required String challenge,
    required String code,
  }) async {
    final verification = TwoFactorVerification.fromJson(
      await _data(
        () => client.post('/auth/2fa/verify', body: {
          'challenge': challenge,
          'code': code,
        },),
      ),
    );

    await _adopt(verification);
    return verification;
  }

  /// Answers the same way whether or not the address is registered — the server
  /// does too, and for the same reason: a different answer is a free list of
  /// which addresses bank here.
  Future<void> forgotPassword(String email) async {
    await _data(
      () => client.post('/auth/forgot-password', body: {'email': email}),
    );
  }

  Future<void> resetPassword({
    required String email,
    required String token,
    required String password,
  }) async {
    await _data(
      () => client.post('/auth/reset-password', body: {
        'email': email,
        'token': token,
        'password': password,
      },),
    );
  }

  Future<void> _adopt(TwoFactorVerification verification) async {
    if (verification.token.isEmpty) return;

    client.session.token = verification.token;
    await tokens?.writeToken(verification.token);

    final workspaceId = verification.workspaceIds.firstOrNull;
    if (workspaceId != null) {
      client.session.workspaceId = workspaceId;
      await tokens?.writeWorkspaceId(workspaceId);
    }
  }

  Future<Map<String, Object?>> _data(
    Future<Map<String, Object?>> Function() call,
  ) async {
    try {
      final response = await call();
      return (response['data'] as Map?)?.cast<String, Object?>() ?? const {};
    } on ApiException catch (error) {
      throw SecurityException.from(error);
    }
  }
}
