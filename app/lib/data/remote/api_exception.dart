import 'package:dio/dio.dart';

/// Every failure the app can surface, carrying the server's machine-readable
/// `code` from `{error: {code, message, details}}`.
///
/// The UI translates from [code]; [message] is the server's already-localised
/// text and is only a fallback for codes we have no key for yet. Trusting the
/// server's prose would make the app's wording untestable and untranslatable
/// offline.
final class ApiException implements Exception {
  const ApiException({
    required this.code,
    this.message = '',
    this.details = const {},
    this.rawDetails = const {},
    this.statusCode,
    this.requestId,
  });

  /// Stable, English, machine-readable. See docs/05-api-conventions.md §4.
  final String code;
  final String message;

  /// Field name → list of validation codes, e.g. `{'amount': ['min_value']}`.
  ///
  /// Flattened for the common case, which is a 422. Anything structured is
  /// stringified on the way in, so read [rawDetails] instead when the server
  /// sends records rather than codes.
  final Map<String, List<String>> details;

  /// `details` exactly as the server sent it.
  ///
  /// Not every `details` is a validation map: `downgrade_blocked` carries a
  /// list of `{feature, limit, used}` records and `deletion_already_scheduled`
  /// carries a date. Flattening those to strings threw away the only part a
  /// screen could use, leaving it to show a generic refusal while the server
  /// had already said exactly which limit was hit.
  final Map<String, Object?> rawDetails;
  final int? statusCode;
  final String? requestId;

  static const codeNetworkUnreachable = 'network_unreachable';
  static const codeNetworkTimeout = 'network_timeout';
  static const codeCancelled = 'request_cancelled';
  static const codeUnexpected = 'unexpected_error';
  static const codeVersionConflict = 'version_conflict';
  static const codeValidationFailed = 'validation_failed';
  static const codeUnauthenticated = 'unauthenticated';

  /// The i18n key the UI shows. Unknown codes fall back to a generic key so a
  /// new server code never renders as raw English in a Persian app.
  String get translationKey => 'error.$code';

  bool get isNetworkFailure =>
      code == codeNetworkUnreachable || code == codeNetworkTimeout;

  /// Only network trouble and server-side faults can succeed on a second try;
  /// a 4xx means the request itself is wrong and will stay wrong.
  bool get isRetryable =>
      isNetworkFailure || (statusCode != null && statusCode! >= 500);

  /// A permanent failure of a queued write — it needs a human, not a retry.
  bool get isPermanent => !isRetryable && code != codeCancelled;

  /// Reads the server's error envelope. Anything that does not match the
  /// documented shape degrades to a status-derived code rather than throwing a
  /// second, less useful error.
  factory ApiException.fromEnvelope(
    Object? body, {
    int? statusCode,
  }) {
    if (body is Map) {
      final error = body['error'];
      if (error is Map) {
        return ApiException(
          code: error['code'] as String? ?? _codeForStatus(statusCode),
          message: error['message'] as String? ?? '',
          details: _parseDetails(error['details']),
          rawDetails: _rawDetails(error['details']),
          statusCode: statusCode,
          requestId: error['request_id'] as String?,
        );
      }
    }
    return ApiException(
      code: _codeForStatus(statusCode),
      statusCode: statusCode,
    );
  }

  factory ApiException.fromDio(DioException error) {
    final existing = error.error;
    if (existing is ApiException) return existing;

    if (error.type == DioExceptionType.badResponse) {
      return ApiException.fromEnvelope(
        error.response?.data,
        statusCode: error.response?.statusCode,
      );
    }

    return switch (error.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.sendTimeout ||
      DioExceptionType.receiveTimeout =>
        const ApiException(code: codeNetworkTimeout),
      DioExceptionType.cancel => const ApiException(code: codeCancelled),
      // Everything else the transport can raise means the request did not get
      // an answer, which is the same thing as being offline.
      _ => const ApiException(code: codeNetworkUnreachable),
    };
  }

  /// Keeps the server's own structure so a caller can read a record, a number
  /// or a date out of it.
  static Map<String, Object?> _rawDetails(Object? raw) {
    if (raw is! Map) return const {};
    return {for (final entry in raw.entries) '${entry.key}': entry.value};
  }

  static Map<String, List<String>> _parseDetails(Object? raw) {
    if (raw is! Map) return const {};
    final parsed = <String, List<String>>{};
    raw.forEach((key, value) {
      parsed['$key'] = switch (value) {
        List<Object?> list => [for (final item in list) '$item'],
        _ => ['$value'],
      };
    });
    return parsed;
  }

  static String _codeForStatus(int? status) => switch (status) {
        400 => 'bad_request',
        401 => codeUnauthenticated,
        403 => 'forbidden',
        404 => 'not_found',
        409 => codeVersionConflict,
        422 => codeValidationFailed,
        429 => 'rate_limited',
        503 => 'service_unavailable',
        final int code when code >= 500 => 'server_error',
        _ => codeUnexpected,
      };

  @override
  String toString() => 'ApiException($code, status: $statusCode)';
}
