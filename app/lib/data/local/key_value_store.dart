import 'package:shared_preferences/shared_preferences.dart';

/// The narrow slice of persistence the local store needs.
///
/// Keeping it this small is what lets the whole offline stack run in a plain
/// `flutter test` — no device, no native build, no code generation.
abstract interface class KeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
  Future<void> clear();
}

/// Used by tests and by the very first launch, before persistence is ready.
final class MemoryKeyValueStore implements KeyValueStore {
  final Map<String, String> _values = {};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async => _values[key] = value;

  @override
  Future<void> delete(String key) async => _values.remove(key);

  @override
  Future<void> clear() async => _values.clear();
}

final class SharedPreferencesStore implements KeyValueStore {
  SharedPreferencesStore(this._prefs);

  static Future<SharedPreferencesStore> open() async =>
      SharedPreferencesStore(await SharedPreferences.getInstance());

  final SharedPreferences _prefs;

  @override
  Future<String?> read(String key) async => _prefs.getString(key);

  @override
  Future<void> write(String key, String value) =>
      _prefs.setString(key, value);

  @override
  Future<void> delete(String key) => _prefs.remove(key);

  @override
  Future<void> clear() => _prefs.clear();
}
