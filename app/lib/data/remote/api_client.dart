import 'package:dio/dio.dart';
import 'package:ulid/ulid.dart';

import 'api_exception.dart';

/// Everything the transport needs to know about who is talking and to which
/// workspace. Mutable, because signing in or switching workspace must change
/// the next request without rebuilding the Dio stack.
final class ApiSession {
  ApiSession({
    required this.deviceId,
    this.token,
    this.workspaceId,
    this.localeCode = 'fa',
  });

  final String deviceId;
  String? token;
  String? workspaceId;
  String localeCode;

  bool get isAuthenticated => token != null && token!.isNotEmpty;

  void clear() {
    token = null;
    workspaceId = null;
  }
}

/// Exponential backoff for requests that can still succeed.
///
/// The waiter is injectable so tests can assert the schedule without spending
/// the wall-clock time it describes.
final class RetryPolicy {
  const RetryPolicy({
    this.maxAttempts = 4,
    this.initialDelay = const Duration(milliseconds: 400),
    this.multiplier = 2,
    this.maxDelay = const Duration(seconds: 30),
    this.wait = Future.delayed,
  });

  final int maxAttempts;
  final Duration initialDelay;
  final int multiplier;
  final Duration maxDelay;
  final Future<void> Function(Duration) wait;

  /// [attempt] is 1 for the delay before the first retry.
  Duration delayFor(int attempt) {
    var micros = initialDelay.inMicroseconds;
    for (var i = 1; i < attempt; i++) {
      micros *= multiplier;
      if (micros >= maxDelay.inMicroseconds) return maxDelay;
    }
    return Duration(microseconds: micros);
  }

  static const none = RetryPolicy(maxAttempts: 1);
}

/// Adds the headers docs/05-api-conventions.md §2 makes mandatory.
final class SessionInterceptor extends Interceptor {
  SessionInterceptor(this.session, {String Function()? idempotencyKey})
      : _idempotencyKey = idempotencyKey ?? _defaultKey;

  final ApiSession session;
  final String Function() _idempotencyKey;

  static const _writeMethods = {'POST', 'PUT', 'PATCH', 'DELETE'};

  static String _defaultKey() => Ulid().toString();

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    final headers = options.headers;

    if (session.isAuthenticated) {
      headers['Authorization'] = 'Bearer ${session.token}';
    }
    if (session.workspaceId != null) {
      headers['X-Workspace-Id'] = session.workspaceId;
    }
    headers['Accept-Language'] = session.localeCode;
    headers['X-Device-Id'] = session.deviceId;

    // Minted once per logical write and preserved across retries — that is the
    // whole point: a retried POST must not create a second transaction.
    if (_writeMethods.contains(options.method.toUpperCase()) &&
        headers['Idempotency-Key'] == null) {
      headers['Idempotency-Key'] = _idempotencyKey();
    }

    handler.next(options);
  }
}

/// Turns transport failures into [ApiException] before anything else sees them.
final class ErrorMappingInterceptor extends Interceptor {
  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    handler.next(
      DioException(
        requestOptions: err.requestOptions,
        response: err.response,
        type: err.type,
        error: ApiException.fromDio(err),
        stackTrace: err.stackTrace,
      ),
    );
  }
}

/// Retries network failures and 5xx with exponential backoff. A 4xx is never
/// retried: the request is wrong, and repeating it only burns the rate limit.
final class RetryInterceptor extends Interceptor {
  RetryInterceptor({required this.dio, required this.policy});

  final Dio dio;
  final RetryPolicy policy;

  static const _attemptKey = 'retry_attempt';

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final failure = ApiException.fromDio(err);
    final attempt = (err.requestOptions.extra[_attemptKey] as int? ?? 0) + 1;

    if (!failure.isRetryable || attempt >= policy.maxAttempts) {
      handler.next(err);
      return;
    }

    await policy.wait(policy.delayFor(attempt));

    final options = err.requestOptions..extra[_attemptKey] = attempt;

    try {
      handler.resolve(await dio.fetch<Object?>(options));
    } on DioException catch (retryError) {
      handler.next(retryError);
    }
  }
}

/// The one place the app speaks HTTP.
final class ApiClient {
  ApiClient({required this.dio, required this.session});

  factory ApiClient.create({
    required ApiSession session,
    String baseUrl = 'http://localhost/api/v1',
    HttpClientAdapter? adapter,
    RetryPolicy policy = const RetryPolicy(),
    String Function()? idempotencyKey,
  }) {
    final dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 30),
        responseType: ResponseType.json,
        headers: const {'Accept': 'application/json'},
        // Non-2xx must reach the error interceptors so the envelope is parsed
        // in exactly one place.
        validateStatus: (status) => status != null && status < 400,
      ),
    );

    if (adapter != null) dio.httpClientAdapter = adapter;

    dio.interceptors.addAll([
      SessionInterceptor(session, idempotencyKey: idempotencyKey),
      ErrorMappingInterceptor(),
      RetryInterceptor(dio: dio, policy: policy),
    ]);

    return ApiClient(dio: dio, session: session);
  }

  final Dio dio;
  final ApiSession session;

  Future<Map<String, Object?>> get(
    String path, {
    Map<String, Object?>? query,
  }) =>
      _send(() => dio.get<Object?>(path, queryParameters: query));

  Future<Map<String, Object?>> post(
    String path, {
    Object? body,
    Map<String, String>? headers,
  }) =>
      _send(() => dio.post<Object?>(
            path,
            data: body,
            options: headers == null ? null : Options(headers: headers),
          ),);

  Future<Map<String, Object?>> patch(String path, {Object? body}) =>
      _send(() => dio.patch<Object?>(path, data: body));

  Future<Map<String, Object?>> delete(String path, {Object? body}) =>
      _send(() => dio.delete<Object?>(path, data: body));

  Future<Map<String, Object?>> _send(
    Future<Response<Object?>> Function() call,
  ) async {
    try {
      final response = await call();
      final data = response.data;
      if (data is Map) return data.cast<String, Object?>();
      // 204 and other empty bodies are a success with nothing to read.
      return const {};
    } on DioException catch (error) {
      throw ApiException.fromDio(error);
    }
  }
}
