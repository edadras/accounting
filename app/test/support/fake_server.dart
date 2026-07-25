import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:collection/collection.dart';
import 'package:dio/dio.dart';

/// A Dio adapter that answers from a function instead of a socket.
///
/// `dio` lets us replace the whole transport, which is what keeps every test in
/// this suite free of real network I/O.
final class MockAdapter implements HttpClientAdapter {
  MockAdapter(this.handler);

  final FutureOr<ResponseBody> Function(RequestOptions options) handler;

  final List<RequestOptions> requests = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return handler(options);
  }

  @override
  void close({bool force = false}) {}

  static ResponseBody json(Object? body, {int status = 200}) =>
      ResponseBody.fromString(
        jsonEncode(body),
        status,
        headers: {
          Headers.contentTypeHeader: ['application/json; charset=utf-8'],
        },
      );

  static ResponseBody error(
    String code, {
    int status = 422,
    String message = 'nope',
    Map<String, Object?> details = const {},
  }) =>
      json(
        {
          'error': {
            'code': code,
            'message': message,
            'details': details,
            'request_id': '01TESTREQUEST',
          },
        },
        status: status,
      );
}

/// Raised where a real client would see a dead socket.
DioException connectionFailure(RequestOptions options) => DioException(
      requestOptions: options,
      type: DioExceptionType.connectionError,
      error: 'no route to host',
    );

/// An in-memory stand-in for the sync endpoints, with the version bookkeeping
/// docs/09-sync-offline.md §6 describes — enough to tell applied from conflict
/// and to prove a replayed batch is idempotent.
final class FakeSyncServer {
  FakeSyncServer({this.serverTime = '2026-07-25T09:00:00Z'});

  final String serverTime;

  /// entity → id → payload
  final Map<String, Map<String, Map<String, Object?>>> records = {};
  final Map<String, int> versions = {};

  final List<String> seenChangeIds = [];
  int pushCalls = 0;
  int pullCalls = 0;

  /// Ids the server will answer with `conflict`, and the payload it claims to
  /// hold for them.
  final Map<String, Map<String, Object?>> conflictFor = {};

  final List<Map<String, Object?>> pendingPullChanges = [];

  String _key(String entity, String id) => '$entity:$id';

  void seed(String entity, String id, Map<String, Object?> payload, int version) {
    (records[entity] ??= {})[id] = payload;
    versions[_key(entity, id)] = version;
  }

  int count(String entity) => (records[entity] ?? const {}).length;

  ResponseBody handle(RequestOptions options) {
    if (options.path.endsWith('/sync/push')) return _push(options);
    if (options.path.endsWith('/sync/pull')) return _pull();
    return MockAdapter.json({'data': const <String, Object?>{}});
  }

  ResponseBody _push(RequestOptions options) {
    pushCalls++;
    final body = (options.data as Map).cast<String, Object?>();
    final results = <Map<String, Object?>>[];

    for (final raw in body['changes']! as List) {
      final change = (raw as Map).cast<String, Object?>();
      final entity = change['entity']! as String;
      final id = change['id']! as String;
      final key = _key(entity, id);
      seenChangeIds.add(id);

      final conflict = conflictFor[id];
      if (conflict != null) {
        results.add({
          'id': id,
          'status': 'conflict',
          'server_version': versions[key] ?? 5,
          'server_payload': conflict,
        });
        continue;
      }

      final payload =
          (change['payload'] as Map?)?.cast<String, Object?>() ?? const {};

      // Re-sending a change the server already stored is not a second record:
      // the ULID came from the device, so the server recognises it and replies
      // with the version it already assigned.
      final existing = records[entity]?[id];
      if (existing != null &&
          const DeepCollectionEquality().equals(existing, payload)) {
        results.add({
          'id': id,
          'status': 'applied',
          'server_version': versions[key],
        });
        continue;
      }

      final next = (versions[key] ?? 0) + 1;
      (records[entity] ??= {})[id] = payload;
      versions[key] = next;

      results.add({'id': id, 'status': 'applied', 'server_version': next});
    }

    return MockAdapter.json({
      'data': {'results': results},
      'meta': {'server_time': serverTime},
    });
  }

  ResponseBody _pull() {
    pullCalls++;
    final changes = List<Map<String, Object?>>.from(pendingPullChanges);
    pendingPullChanges.clear();

    return MockAdapter.json({
      'data': {'changes': changes},
      'meta': {'server_time': serverTime, 'next_cursor': null},
    });
  }
}
