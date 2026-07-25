import 'package:dio/dio.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/remote/api_exception.dart';
import 'package:finora/data/remote/entity_codec.dart';
import 'package:finora/data/remote/money_codec.dart';
import 'package:finora/domain/entities.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

void main() {
  group('MoneyCodec', () {
    // A currency with no decimals, one with two and one with eight: if the
    // integer round trip survives all three, it survives the rest.
    const cases = <(String, int, int, String)>[
      ('IRR', 0, 500000, '500000'),
      ('USD', 2, 123456, '1234.56'),
      ('BTC', 8, 12345, '0.00012345'),
    ];

    for (final (code, minorUnit, value, decimal) in cases) {
      test('$code survives server JSON → Money → outgoing JSON', () {
        final wire = {
          'value': value,
          'currency': code,
          'minor_unit': minorUnit,
          'decimal': decimal,
        };

        final money = MoneyCodec.decode(wire);

        expect(money.minorUnits, value);
        expect(money.currency.code, code);
        expect(money.currency.minorUnit, minorUnit);
        expect(MoneyCodec.decimalString(money), decimal);
        expect(MoneyCodec.encode(money), wire);
      });
    }

    test('reads the integer value, never the decimal string', () {
      // 0.1 + 0.2 territory: the decimal is deliberately wrong here, and the
      // codec must not care.
      final money = MoneyCodec.decode({
        'value': 1234,
        'currency': 'USD',
        'minor_unit': 2,
        'decimal': '99.99',
      });

      expect(money.minorUnits, 1234);
    });

    test('accepts a currency this build has never heard of', () {
      final money = MoneyCodec.decode({
        'value': 7,
        'currency': 'XAU',
        'minor_unit': 4,
      });

      expect(money.currency.code, 'XAU');
      expect(money.currency.minorUnit, 4);
      expect(MoneyCodec.decimalString(money), '0.0007');
    });

    test('renders negatives without losing the sign', () {
      expect(
        MoneyCodec.decimalString(const Money(-5, Currency.usd)),
        '-0.05',
      );
    });
  });

  group('TransactionCodec', () {
    final serverJson = <String, Object?>{
      'id': '01J0TRANSACTION0000000000',
      'type': 'expense',
      'amount': {
        'value': 35000,
        'currency': 'TRY',
        'minor_unit': 2,
        'decimal': '350.00',
      },
      'base': {
        'value': 105000,
        'currency': 'IRR',
        'minor_unit': 0,
        'decimal': '105000',
        'fx_rate': '3.0',
      },
      'account_id': '01J0ACCOUNT00000000000000',
      'category_id': '01J0CATEGORY0000000000000',
      'occurred_at': '2026-07-25T09:30:00Z',
      'description': 'شام',
      'version': 3,
    };

    test('decodes both amounts by their integer value', () {
      final transaction = TransactionCodec.decode(
        serverJson,
        baseCurrency: Currency.irr,
      );

      expect(transaction.type, TransactionType.expense);
      expect(transaction.amount, const Money(35000, Currency.try_));
      expect(transaction.baseAmount, const Money(105000, Currency.irr));
      expect(transaction.occurredAt.toUtc().hour, 9);
      expect(transaction.description, 'شام');
    });

    test('a write body carries integer minor units, not a decimal', () {
      final body = TransactionCodec.toWriteBody(serverJson);

      expect(body['amount'], 35000);
      expect(body['amount'], isA<int>());
      expect(body['currency'], 'TRY');
    });
  });

  group('ApiException', () {
    test('maps the error envelope onto the stable code', () {
      final failure = ApiException.fromEnvelope(
        {
          'error': {
            'code': 'validation_failed',
            'message': 'مبلغ باید بزرگ‌تر از صفر باشد.',
            'details': {
              'amount': ['min_value'],
            },
            'request_id': '01JREQ',
          },
        },
        statusCode: 422,
      );

      expect(failure.code, 'validation_failed');
      expect(failure.details['amount'], ['min_value']);
      expect(failure.requestId, '01JREQ');
      expect(failure.translationKey, 'error.validation_failed');
      expect(failure.isRetryable, isFalse);
    });

    test('falls back to a status-derived code when there is no envelope', () {
      expect(
        ApiException.fromEnvelope('<html>502</html>', statusCode: 502).code,
        'server_error',
      );
      expect(
        ApiException.fromEnvelope(null, statusCode: 401).code,
        'unauthenticated',
      );
    });

    test('a 4xx thrown by the client carries the server code', () async {
      final client = _client(
        (options) => MockAdapter.error('workspace_forbidden', status: 403),
      );

      await expectLater(
        client.get('/accounts'),
        throwsA(
          isA<ApiException>()
              .having((e) => e.code, 'code', 'workspace_forbidden')
              .having((e) => e.statusCode, 'status', 403),
        ),
      );
    });
  });

  group('headers', () {
    test('every request is scoped, localised and identified', () async {
      final adapter = MockAdapter((_) => MockAdapter.json({'data': const {}}));
      final session = ApiSession(
        deviceId: '01JDEVICE',
        token: 'secret-token',
        workspaceId: '01JWORKSPACE',
        localeCode: 'fa',
      );
      final client = ApiClient.create(session: session, adapter: adapter);

      await client.post('/transactions', body: const {'amount': 1});

      final headers = adapter.requests.single.headers;
      expect(headers['Authorization'], 'Bearer secret-token');
      expect(headers['X-Workspace-Id'], '01JWORKSPACE');
      expect(headers['Accept-Language'], 'fa');
      expect(headers['X-Device-Id'], '01JDEVICE');
      expect(headers['Idempotency-Key'], isNotNull);
    });

    test('a GET carries no idempotency key', () async {
      final adapter = MockAdapter((_) => MockAdapter.json({'data': const {}}));
      final client = ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE'),
        adapter: adapter,
      );

      await client.get('/transactions');

      expect(adapter.requests.single.headers['Idempotency-Key'], isNull);
    });
  });

  group('retry', () {
    test('a 500 is retried with exponential backoff', () async {
      final delays = <Duration>[];
      var calls = 0;

      final adapter = MockAdapter((_) {
        calls++;
        if (calls < 3) return MockAdapter.error('server_error', status: 500);
        return MockAdapter.json({'data': const {'ok': true}});
      });

      final client = ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE'),
        adapter: adapter,
        policy: RetryPolicy(
          initialDelay: const Duration(milliseconds: 100),
          wait: (delay) async => delays.add(delay),
        ),
      );

      final response = await client.get('/transactions');

      expect(calls, 3);
      expect(response['data'], {'ok': true});
      expect(delays, const [
        Duration(milliseconds: 100),
        Duration(milliseconds: 200),
      ]);
    });

    test('a network failure is retried, then surfaced as offline', () async {
      final delays = <Duration>[];
      final adapter = MockAdapter((options) => throw connectionFailure(options));

      final client = ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE'),
        adapter: adapter,
        policy: RetryPolicy(
          maxAttempts: 3,
          initialDelay: const Duration(milliseconds: 50),
          wait: (delay) async => delays.add(delay),
        ),
      );

      await expectLater(
        client.get('/transactions'),
        throwsA(
          isA<ApiException>().having(
            (e) => e.code,
            'code',
            ApiException.codeNetworkUnreachable,
          ),
        ),
      );

      expect(adapter.requests.length, 3);
      expect(delays, const [
        Duration(milliseconds: 50),
        Duration(milliseconds: 100),
      ]);
    });

    test('a 422 is never retried', () async {
      final delays = <Duration>[];
      final adapter = MockAdapter(
        (_) => MockAdapter.error('validation_failed'),
      );

      final client = ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE'),
        adapter: adapter,
        policy: RetryPolicy(wait: (delay) async => delays.add(delay)),
      );

      await expectLater(
        client.post('/transactions', body: const {}),
        throwsA(
          isA<ApiException>()
              .having((e) => e.code, 'code', 'validation_failed'),
        ),
      );

      expect(adapter.requests.length, 1);
      expect(delays, isEmpty);
    });

    test('a retried write reuses its idempotency key', () async {
      var calls = 0;
      final adapter = MockAdapter((_) {
        calls++;
        if (calls == 1) return MockAdapter.error('server_error', status: 500);
        return MockAdapter.json({'data': const {}});
      });

      final client = ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE'),
        adapter: adapter,
        policy: const RetryPolicy(wait: _noWait),
      );

      await client.post('/transactions', body: const {'amount': 1});

      expect(adapter.requests.length, 2);
      expect(
        adapter.requests.first.headers['Idempotency-Key'],
        adapter.requests.last.headers['Idempotency-Key'],
      );
    });

    test('the backoff schedule is capped', () {
      const policy = RetryPolicy(
        initialDelay: Duration(seconds: 1),
        maxDelay: Duration(seconds: 4),
      );

      expect(policy.delayFor(1), const Duration(seconds: 1));
      expect(policy.delayFor(2), const Duration(seconds: 2));
      expect(policy.delayFor(3), const Duration(seconds: 4));
      expect(policy.delayFor(9), const Duration(seconds: 4));
    });
  });
}

Future<void> _noWait(Duration duration) async {}

ApiClient _client(ResponseBody Function(RequestOptions) handler) =>
    ApiClient.create(
      session: ApiSession(deviceId: '01JDEVICE'),
      adapter: MockAdapter(handler),
      policy: const RetryPolicy(maxAttempts: 1),
    );
