import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ulid/ulid.dart';

import '../core/money/currency.dart';
import '../sync/sync_api.dart';
import '../sync/sync_controller.dart';
import '../sync/sync_engine.dart';
import 'remote/translations_api.dart';
import 'api_ledger_repository.dart';
import 'auth_repository.dart';
import 'ledger_repository.dart';
import 'local/key_value_store.dart';
import 'local/local_store.dart';
import 'local/token_store.dart';
import 'remote/api_client.dart';

final localStoreProvider = Provider<LocalStore>(
  (ref) => throw UnimplementedError(
    'localStoreProvider must be overridden with FinoraBackend.overrides',
  ),
);

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => throw UnimplementedError(
    'authRepositoryProvider must be overridden with FinoraBackend.overrides',
  ),
);

/// Assembles the whole online stack and hands back the provider overrides that
/// swap it in for [InMemoryLedgerRepository]:
///
/// ```dart
/// final backend = await FinoraBackend.connect(baseUrl: '...');
/// runApp(ProviderScope(overrides: backend.overrides, child: const SyncScope(child: FinoraApp())));
/// ```
///
/// The demo build keeps working untouched, which is what lets the existing
/// widget tests and goldens stay meaningful.
final class FinoraBackend {
  FinoraBackend._({
    required this.client,
    required this.store,
    required this.auth,
    required this.repository,
    required this.controller,
    required this.translations,
    required this.baseCurrency,
  });

  final ApiClient client;
  final LocalStore store;
  final AuthRepository auth;
  final ApiLedgerRepository repository;
  final SyncController controller;
  final TranslationsApi translations;
  final Currency baseCurrency;

  static Future<FinoraBackend> connect({
    String baseUrl = 'http://localhost/api/v1',
    Currency baseCurrency = Currency.irr,
    String localeCode = 'fa',
    KeyValueStore? keyValueStore,
    TokenStore? tokenStore,
    HttpClientAdapter? adapter,
    RetryPolicy policy = const RetryPolicy(),
    Duration syncInterval = const Duration(seconds: 30),
  }) async {
    final tokens = tokenStore ?? SecureTokenStore();

    // The device id outlives sign-out: it identifies the installation to the
    // sync protocol, not the person using it.
    var deviceId = await tokens.readDeviceId();
    if (deviceId == null || deviceId.isEmpty) {
      deviceId = Ulid().toString();
      await tokens.writeDeviceId(deviceId);
    }

    final session = ApiSession(deviceId: deviceId, localeCode: localeCode);
    final client = ApiClient.create(
      session: session,
      baseUrl: baseUrl,
      adapter: adapter,
      policy: policy,
    );

    final auth = AuthRepository(client: client, tokens: tokens);
    await auth.restore();

    final kv = keyValueStore ?? await SharedPreferencesStore.open();
    final store = await LocalStore.open(kv);

    return FinoraBackend._(
      client: client,
      store: store,
      auth: auth,
      repository: ApiLedgerRepository(store: store, baseCurrency: baseCurrency),
      controller: SyncController(
        engine: SyncEngine(
          api: SyncApi(client),
          store: store,
          deviceId: deviceId,
        ),
        store: store,
        interval: syncInterval,
      ),
      translations: TranslationsApi(client: client, store: kv),
      baseCurrency: baseCurrency,
    );
  }

  /// Server wording, applied over the bundled strings.
  ///
  /// The cached copy is applied first so the UI never waits on the network for
  /// its own labels, and a refresh failure is swallowed: the bundled dictionary
  /// is complete, so the worst case is slightly stale wording.
  Future<Map<String, String>> loadTranslations(String locale) async {
    final cached = await translations.cached(locale);

    try {
      return await translations.refresh(locale);
    } on Object {
      return cached;
    }
  }

  List<Override> get overrides => [
        finoraBackendProvider.overrideWithValue(this),
        baseCurrencyProvider.overrideWithValue(baseCurrency),
        ledgerRepositoryProvider.overrideWithValue(repository),
        localStoreProvider.overrideWithValue(store),
        authRepositoryProvider.overrideWithValue(auth),
        syncControllerProvider.overrideWithValue(controller),
      ];

  void dispose() {
    controller.dispose();
    client.dio.close();
  }
}

/// The assembled stack, for the few places that need more than one piece of it.
/// Null in the demo build, where there is no server.
final finoraBackendProvider = Provider<FinoraBackend?>((ref) => null);
