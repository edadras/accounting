import '../core/money/currency.dart';
import '../domain/entities.dart';
import 'local/token_store.dart';
import 'remote/api_client.dart';

final class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    this.locale = 'fa',
  });

  final String id;
  final String name;
  final String email;
  final String locale;

  static AuthUser fromJson(Map<String, Object?> json) => AuthUser(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? '',
        email: json['email'] as String? ?? '',
        locale: json['locale'] as String? ?? 'fa',
      );
}

final class AuthResult {
  const AuthResult({
    required this.user,
    required this.workspaces,
    required this.activeWorkspaceId,
  });

  final AuthUser user;
  final List<Workspace> workspaces;
  final String? activeWorkspaceId;
}

/// Sign-in, sign-out and workspace selection.
///
/// The token goes to [TokenStore] (Keychain/Keystore in production) and into
/// the live [ApiSession] in one step, so no caller ever has to remember to do
/// both.
final class AuthRepository {
  AuthRepository({required this.client, required this.tokens});

  final ApiClient client;
  final TokenStore tokens;

  ApiSession get session => client.session;

  Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
    String locale = 'fa',
    String baseCurrency = 'IRR',
  }) async {
    final response = await client.post('/auth/register', body: {
      'name': name,
      'email': email,
      'password': password,
      'locale': locale,
      'base_currency': baseCurrency,
    },);

    final data = _data(response);
    final workspace = (data['workspace'] as Map?)?.cast<String, Object?>();

    await _adopt(
      token: data['token'] as String? ?? '',
      workspaceId: workspace?['id'] as String?,
    );

    return AuthResult(
      user: AuthUser.fromJson(
        (data['user'] as Map?)?.cast<String, Object?>() ?? const {},
      ),
      workspaces: [
        if (workspace != null)
          _workspaceFrom(workspace, fallbackCurrency: baseCurrency),
      ],
      activeWorkspaceId: workspace?['id'] as String?,
    );
  }

  Future<AuthResult> login({
    required String email,
    required String password,
  }) async =>
      adoptLogin(await requestLogin(email: email, password: password));

  /// The raw `POST /auth/login` envelope, adopted by nobody.
  ///
  /// Split out of [login] because the answer is not always a session: an account
  /// with a confirmed second factor gets `two_factor_required` and a challenge,
  /// and the caller has to look before storing anything. Read it with
  /// [LoginChallenge.fromLogin], then hand it to [adoptLogin] if there is a
  /// token in it.
  Future<Map<String, Object?>> requestLogin({
    required String email,
    required String password,
  }) =>
      client.post('/auth/login', body: {
        'email': email,
        'password': password,
      },);

  /// Takes the token and workspaces out of a login envelope and makes them this
  /// device's session.
  Future<AuthResult> adoptLogin(Map<String, Object?> response) async {
    final data = _data(response);
    final workspaces = [
      for (final item in data['workspaces'] as List? ?? const [])
        if (item is Map) _workspaceFrom(item.cast<String, Object?>()),
    ];

    await _adopt(
      token: data['token'] as String? ?? '',
      workspaceId: workspaces.isEmpty ? null : workspaces.first.id,
    );

    return AuthResult(
      user: AuthUser.fromJson(
        (data['user'] as Map?)?.cast<String, Object?>() ?? const {},
      ),
      workspaces: workspaces,
      activeWorkspaceId: session.workspaceId,
    );
  }

  Future<void> logout() async {
    try {
      await client.post('/auth/logout');
    } finally {
      // Whatever the server says, this device must stop holding the token.
      session.clear();
      await tokens.clearCredentials();
    }
  }

  Future<AuthUser> me() async =>
      AuthUser.fromJson(_data(await client.get('/me')));

  Future<List<Workspace>> workspaces() async {
    final response = await client.get('/workspaces');
    return [
      for (final item in response['data'] as List? ?? const [])
        if (item is Map) _workspaceFrom(item.cast<String, Object?>()),
    ];
  }

  Future<void> selectWorkspace(String id) async {
    session.workspaceId = id;
    await tokens.writeWorkspaceId(id);
  }

  /// Restores a previous sign-in on cold start. Returns false when the device
  /// has no token, which is not an error — the app still opens, offline.
  Future<bool> restore() async {
    final token = await tokens.readToken();
    if (token == null || token.isEmpty) return false;

    session
      ..token = token
      ..workspaceId = await tokens.readWorkspaceId();
    return true;
  }

  Future<void> _adopt({required String token, String? workspaceId}) async {
    session.token = token;
    await tokens.writeToken(token);

    if (workspaceId != null) await selectWorkspace(workspaceId);
  }

  static Map<String, Object?> _data(Map<String, Object?> response) =>
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {};

  static Workspace _workspaceFrom(
    Map<String, Object?> json, {
    String fallbackCurrency = 'IRR',
  }) {
    final code = json['base_currency'] as String? ?? fallbackCurrency;

    return Workspace(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      type: json['type'] as String? ?? 'personal',
      baseCurrency: Currency.byCode(code) ??
          Currency(code: code.toUpperCase(), symbol: code, minorUnit: 2),
      icon: json['icon'] as String?,
    );
  }
}
