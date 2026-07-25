import 'dart:convert';

import '../local/key_value_store.dart';
import 'api_client.dart';

/// Fetches server-side wording overrides.
///
/// The app ships a complete bundled dictionary, so this is never on the
/// critical path: a failure here leaves the user with the built-in strings and
/// nothing looks broken. What it buys is the ability to fix a wrong or awkward
/// phrase for everyone without shipping a new build — which is the whole reason
/// `docs/06-i18n-rtl.md` puts translations in a database instead of in the
/// binary.
final class TranslationsApi {
  TranslationsApi({required this.client, required this.store});

  final ApiClient client;
  final KeyValueStore store;

  static String _cacheKey(String locale) => 'finora.i18n.$locale';
  static String _sinceKey(String locale) => 'finora.i18n.$locale.since';

  /// Everything cached for [locale], with no network involved.
  Future<Map<String, String>> cached(String locale) async {
    final raw = await store.read(_cacheKey(locale));
    if (raw == null || raw.isEmpty) return const {};

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) return const {};
      return {
        for (final entry in decoded.entries)
          if (entry.value is String) '${entry.key}': entry.value as String,
      };
    } on FormatException {
      // A corrupt cache is not worth a crash; the bundled strings still work.
      return const {};
    }
  }

  /// Asks the server for changes and merges them into the cache.
  ///
  /// Only the delta is requested, so the usual answer on app start is empty.
  /// The cursor comes from the server's own clock — a device with a skewed
  /// clock would otherwise ask from the wrong moment and never see an update.
  Future<Map<String, String>> refresh(String locale) async {
    final existing = await cached(locale);
    final since = await store.read(_sinceKey(locale));

    final response = await client.get(
      'translations/$locale',
      query: {if (since != null && since.isNotEmpty) 'since': since},
    );

    final data = response['data'];
    final merged = <String, String>{...existing};

    if (data is Map) {
      for (final entry in data.entries) {
        if (entry.value is String) merged['${entry.key}'] = entry.value as String;
      }
    }

    final checkedAt = (response['meta'] as Map?)?['checked_at'];

    await store.write(_cacheKey(locale), jsonEncode(merged));
    if (checkedAt is String) await store.write(_sinceKey(locale), checkedAt);

    return merged;
  }
}
