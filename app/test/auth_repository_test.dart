import 'package:dio/dio.dart';
import 'package:finora/data/auth_repository.dart';
import 'package:finora/data/local/token_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/remote/api_exception.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

void main() {
  late MemoryTokenStore tokens;
  late List<RequestOptions> requests;

  AuthRepository build(ResponseBody Function(RequestOptions) handler) {
    final adapter = MockAdapter((options) => handler(options));
    requests = adapter.requests;

    return AuthRepository(
      client: ApiClient.create(
        session: ApiSession(deviceId: '01JDEVICE'),
        adapter: adapter,
        policy: const RetryPolicy(maxAttempts: 1),
      ),
      tokens: tokens,
    );
  }

  setUp(() => tokens = MemoryTokenStore());

  test('register stores the token and the workspace it created', () async {
    final auth = build(
      (_) => MockAdapter.json(
        {
          'data': {
            'token': 'new-token',
            'user': {'id': '01JUSER', 'name': 'Ana', 'email': 'a@b.co'},
            'workspace': {'id': '01JWORKSPACE', 'name': 'Ana'},
          },
        },
        status: 201,
      ),
    );

    final result = await auth.register(
      name: 'Ana',
      email: 'a@b.co',
      password: 'password123',
    );

    expect(result.user.email, 'a@b.co');
    expect(result.activeWorkspaceId, '01JWORKSPACE');
    expect(await tokens.readToken(), 'new-token');
    expect(await tokens.readWorkspaceId(), '01JWORKSPACE');
    expect(auth.session.token, 'new-token');
  });

  test('login adopts the first workspace and parses its base currency',
      () async {
    final auth = build(
      (_) => MockAdapter.json({
        'data': {
          'token': 'session-token',
          'user': {'id': '01JUSER', 'name': 'Ana', 'email': 'a@b.co'},
          'workspaces': [
            {
              'id': '01JWORKSPACE',
              'name': 'خانه',
              'type': 'personal',
              'base_currency': 'IRR',
            },
            {
              'id': '01JOTHER',
              'name': 'Shop',
              'type': 'business',
              'base_currency': 'TRY',
            },
          ],
        },
      }),
    );

    final result = await auth.login(email: 'a@b.co', password: 'x');

    expect(result.workspaces.length, 2);
    expect(result.workspaces.first.baseCurrency.code, 'IRR');
    expect(result.workspaces.last.baseCurrency.minorUnit, 2);
    expect(auth.session.workspaceId, '01JWORKSPACE');
  });

  test('wrong credentials surface as a translatable code', () async {
    final auth = build(
      (_) => MockAdapter.error('invalid_credentials', status: 401),
    );

    await expectLater(
      auth.login(email: 'a@b.co', password: 'nope'),
      throwsA(
        isA<ApiException>()
            .having((e) => e.code, 'code', 'invalid_credentials')
            .having((e) => e.translationKey, 'key', 'error.invalid_credentials'),
      ),
    );
    expect(await tokens.readToken(), isNull);
  });

  test('selecting a workspace scopes every later request', () async {
    final auth = build((_) => MockAdapter.json({'data': const <String, Object?>{}}));

    auth.session.token = 'session-token';
    await auth.selectWorkspace('01JCHOSEN');
    await auth.me();

    expect(requests.single.headers['X-Workspace-Id'], '01JCHOSEN');
    expect(await tokens.readWorkspaceId(), '01JCHOSEN');
  });

  test('workspaces lists what the account may send as a workspace id',
      () async {
    final auth = build(
      (_) => MockAdapter.json({
        'data': [
          {
            'id': '01JWORKSPACE',
            'name': 'ساختمان',
            'type': 'building',
            'base_currency': 'IRR',
            'role': 'owner',
          },
        ],
      }),
    );

    final workspaces = await auth.workspaces();

    expect(workspaces.single.type, 'building');
    expect(workspaces.single.name, 'ساختمان');
  });

  test('logout clears the device even when the server is unreachable',
      () async {
    final auth = build((options) => throw connectionFailure(options));

    await tokens.writeToken('old-token');
    await tokens.writeWorkspaceId('01JWORKSPACE');
    auth.session
      ..token = 'old-token'
      ..workspaceId = '01JWORKSPACE';

    await expectLater(auth.logout(), throwsA(isA<ApiException>()));

    expect(auth.session.token, isNull);
    expect(await tokens.readToken(), isNull);
  });

  test('a stored token is restored on cold start', () async {
    await tokens.writeToken('persisted');
    await tokens.writeWorkspaceId('01JWORKSPACE');

    final auth = build((_) => MockAdapter.json({'data': const <String, Object?>{}}));

    expect(await auth.restore(), isTrue);
    expect(auth.session.token, 'persisted');
    expect(auth.session.workspaceId, '01JWORKSPACE');
  });

  test('no stored token is not an error — the app still opens', () async {
    final auth = build((_) => MockAdapter.json({'data': const <String, Object?>{}}));

    expect(await auth.restore(), isFalse);
    expect(auth.session.isAuthenticated, isFalse);
  });
}
