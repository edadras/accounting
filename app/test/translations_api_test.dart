import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:finora/data/local/key_value_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/remote/translations_api.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

/// The app must stay usable when the dictionary endpoint is unreachable — the
/// bundled strings are the floor, and the server can only add to them.
void main() {
  late MemoryKeyValueStore store;
  late List<RequestOptions> seen;

  ApiClient clientReturning(Map<String, Object?> Function(RequestOptions) body) {
    return ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: MockAdapter((options) {
        seen.add(options);
        return ResponseBody.fromString(
          jsonEncode(body(options)),
          200,
          headers: {
            Headers.contentTypeHeader: [Headers.jsonContentType],
          },
        );
      }),
      policy: const RetryPolicy(maxAttempts: 1),
    );
  }

  setUp(() {
    store = MemoryKeyValueStore();
    seen = [];
  });

  test('a first fetch caches the whole dictionary', () async {
    final api = TranslationsApi(
      client: clientReturning((_) => {
            'data': {'nav.dashboard': 'داشبورد'},
            'meta': {'checked_at': '2026-07-25T10:00:00Z'},
          },),
      store: store,
    );

    final result = await api.refresh('fa');

    expect(result['nav.dashboard'], 'داشبورد');
    expect(await api.cached('fa'), {'nav.dashboard': 'داشبورد'});
  });

  test('the next fetch asks only for the delta and merges it', () async {
    final api = TranslationsApi(
      client: clientReturning((options) {
        if (options.queryParameters.containsKey('since')) {
          return {
            'data': {'nav.reports': 'گزارش‌ها'},
            'meta': {'checked_at': '2026-07-25T11:00:00Z'},
          };
        }
        return {
          'data': {'nav.dashboard': 'داشبورد'},
          'meta': {'checked_at': '2026-07-25T10:00:00Z'},
        };
      }),
      store: store,
    );

    await api.refresh('fa');
    final merged = await api.refresh('fa');

    // The second call carried the server's own timestamp, not the device's.
    expect(seen.last.queryParameters['since'], '2026-07-25T10:00:00Z');
    expect(merged, {
      'nav.dashboard': 'داشبورد',
      'nav.reports': 'گزارش‌ها',
    });
  });

  test('a corrupt cache falls back to empty rather than throwing', () async {
    await store.write('finora.i18n.fa', 'not json');

    final api = TranslationsApi(client: clientReturning((_) => {}), store: store);

    expect(await api.cached('fa'), isEmpty);
  });
}
