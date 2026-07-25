import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the bearer token lives.
///
/// Behind an interface because the real implementation is Keychain/Keystore,
/// which needs a device — and the auth flow still has to be testable on a
/// plain Dart VM.
abstract interface class TokenStore {
  Future<String?> readToken();
  Future<void> writeToken(String token);
  Future<String?> readWorkspaceId();
  Future<void> writeWorkspaceId(String id);
  Future<String?> readDeviceId();
  Future<void> writeDeviceId(String id);
  Future<void> clearCredentials();
}

final class MemoryTokenStore implements TokenStore {
  String? _token;
  String? _workspaceId;
  String? _deviceId;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> writeToken(String token) async => _token = token;

  @override
  Future<String?> readWorkspaceId() async => _workspaceId;

  @override
  Future<void> writeWorkspaceId(String id) async => _workspaceId = id;

  @override
  Future<String?> readDeviceId() async => _deviceId;

  @override
  Future<void> writeDeviceId(String id) async => _deviceId = id;

  @override
  Future<void> clearCredentials() async {
    _token = null;
    _workspaceId = null;
  }
}

/// The device id deliberately survives sign-out: it identifies the installation
/// for sync, not the person.
final class SecureTokenStore implements TokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  final FlutterSecureStorage _storage;

  static const _tokenKey = 'finora.auth.token';
  static const _workspaceKey = 'finora.auth.workspace';
  static const _deviceKey = 'finora.device.id';

  @override
  Future<String?> readToken() => _storage.read(key: _tokenKey);

  @override
  Future<void> writeToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  @override
  Future<String?> readWorkspaceId() => _storage.read(key: _workspaceKey);

  @override
  Future<void> writeWorkspaceId(String id) =>
      _storage.write(key: _workspaceKey, value: id);

  @override
  Future<String?> readDeviceId() => _storage.read(key: _deviceKey);

  @override
  Future<void> writeDeviceId(String id) =>
      _storage.write(key: _deviceKey, value: id);

  @override
  Future<void> clearCredentials() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _workspaceKey);
  }
}
