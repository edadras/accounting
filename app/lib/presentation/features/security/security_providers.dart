import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../data/finora_backend.dart';
import '../../../data/remote/api_exception.dart';
import '../../../data/security_repository.dart';

/// The repository the security screens talk to.
///
/// Built from the live backend rather than owned by it, so the demo build —
/// which has no server to secure — fails loudly here instead of quietly
/// pretending two-factor authentication is off.
final securityRepositoryProvider = Provider<SecurityRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw UnimplementedError(
      'securityRepositoryProvider needs FinoraBackend.overrides, or an '
      'override of its own in tests.',
    );
  }

  return SecurityRepository(
    client: backend.client,
    tokens: backend.auth.tokens,
  );
});

/// Wording for a failure, always from the dictionary.
///
/// The server's `message` is deliberately ignored: it arrives in English, so
/// showing it would put an English sentence in the middle of a Persian screen
/// and make the copy impossible to test or to fix without a server deploy.
String securityErrorText(Translator t, Object? error) {
  final code = switch (error) {
    null => '',
    SecurityException failure => failure.code,
    ApiException failure => failure.code,
    _ => ApiException.codeUnexpected,
  };
  if (code.isEmpty) return '';

  final key = 'error.$code';
  final text = t(key);

  // Translator echoes the key back when nothing matches, which would put
  // `error.some_new_code` on screen; a generic sentence is better.
  return text == key ? t('common.error') : text;
}
