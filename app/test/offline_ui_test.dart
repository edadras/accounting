import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/finora_backend.dart';
import 'package:finora/data/local/key_value_store.dart';
import 'package:finora/data/local/local_store.dart';
import 'package:finora/data/local/token_store.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/main.dart';
import 'package:finora/presentation/features/conflicts/conflicts_screen.dart';
import 'package:finora/presentation/sync_scope.dart';
import 'package:finora/sync/conflict.dart';
import 'package:finora/sync/sync_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/harness.dart';

/// Two rules make this file work, and breaking either one hangs it silently.
///
/// **Anything that talks to Dio runs inside `tester.runAsync`.** `testWidgets`
/// drives a fake clock, and Dio completes its responses on real timers the fake
/// clock never advances — so an `await` on a request simply never returns. It
/// blocks synchronously, which is why `--timeout` cannot interrupt it and why
/// the whole suite, not just this test, appears to freeze.
///
/// **Nothing calls `pumpAndSettle`.** `SyncScope` holds a periodic timer for as
/// long as it is mounted, so the tree never goes quiet and `pumpAndSettle`
/// waits out its entire ten-minute budget instead of failing.
Future<void> pumpFrames(WidgetTester tester, {int frames = 5}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// A harness already carrying one unresolved conflict on `amount`: the device
/// says 1,000 and the server says 9,999.
Future<SyncHarness> harnessWithAmountConflict(WidgetTester tester) async {
  final harness = (await tester.runAsync(SyncHarness.create))!;

  await tester.runAsync(() async {
    harness.offline = true;
    final id = (await harness.recordExpenses(1)).single.id;

    harness.server.conflictFor[id] =
        serverTransactionPayload(id: id, amountMinorUnits: 9999);
    harness.server.versions['transaction:$id'] = 4;

    harness.offline = false;
    await harness.engine.push();
  });

  return harness;
}

Widget conflictsApp(SyncController controller, AppLocale locale) {
  return ProviderScope(
    overrides: [
      syncControllerProvider.overrideWithValue(controller),
      localeProvider.overrideWith((ref) => locale),
    ],
    child: MaterialApp(
      theme: NeonTheme.dark(fontFamily: 'Vazirmatn'),
      home: const ConflictsScreen(),
    ),
  );
}

void main() {
  testWidgets('the offline banner shows the real pending count',
      (tester) async {
    // Seeded before connecting: with a backend configured, the app is behind
    // AuthGate, and an unauthenticated start correctly shows sign-in rather
    // than the shell. A stored token and workspace restore the session without
    // a request, which matters here because the adapter refuses every one.
    final tokens = MemoryTokenStore();
    await tokens.writeToken('offline-session');
    await tokens.writeWorkspaceId('01JWORKSPACE00000000000000');

    final backend = (await tester.runAsync(() => FinoraBackend.connect(
          keyValueStore: MemoryKeyValueStore(),
          tokenStore: tokens,
          adapter: MockAdapter((options) => throw connectionFailure(options)),
          policy: const RetryPolicy(maxAttempts: 1),
        ),))!;
    addTearDown(backend.dispose);

    await tester.runAsync(() => backend.store.put(
          LocalStore.entityAccount,
          const LocalRecord(
            id: SyncHarness.accountId,
            version: 1,
            data: {
              'id': SyncHarness.accountId,
              'name': 'کیف پول',
              'type': 'cash',
              'currency': 'IRR',
              'balance': {'value': 500000, 'currency': 'IRR', 'minor_unit': 0},
            },
          ),
        ),);

    await tester.pumpWidget(
      ProviderScope(
        overrides: backend.overrides,
        child: const SyncScope(child: FinoraApp()),
      ),
    );
    await pumpFrames(tester);

    // Three expenses recorded with no network at all.
    await tester.runAsync(() async {
      final harness = await SyncHarness.create();
      for (final draft in await harness.recordExpenses(3)) {
        await backend.repository.record(draft);
      }
      await backend.controller.syncNow();
    });
    await pumpFrames(tester);

    expect(backend.controller.state.isOnline, isFalse);
    expect(backend.controller.state.pending, 3);
    expect(find.text('آفلاین · 3 تغییر در انتظار ارسال'), findsOneWidget);

    // Unmount so the periodic timer stops with the tree.
    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
  });

  testWidgets('the conflicts screen shows both versions and resolves one',
      (tester) async {
    final harness = await harnessWithAmountConflict(tester);
    final controller =
        SyncController(engine: harness.engine, store: harness.store);
    addTearDown(controller.dispose);

    await tester.pumpWidget(conflictsApp(controller, AppLocale.fa));
    await pumpFrames(tester);

    expect(find.text('نسخه این دستگاه'), findsOneWidget);
    expect(find.text('نسخه سرور'), findsOneWidget);

    // Both amounts are on screen at once — nothing was decided for the user.
    expect(find.textContaining('۱٬۰۰۰'), findsWidgets);
    expect(find.textContaining('۹٬۹۹۹'), findsWidgets);

    await tester.tap(find.text('نسخه من درست است'));
    await tester.runAsync(() => Future<void>.delayed(Duration.zero));
    await pumpFrames(tester);

    expect(harness.store.conflicts, isEmpty);
    expect(find.text('تعارض حل‌نشده‌ای وجود ندارد.'), findsOneWidget);
  });

  testWidgets('resolving in favour of the server adopts the server amount',
      (tester) async {
    final harness = await harnessWithAmountConflict(tester);
    final controller =
        SyncController(engine: harness.engine, store: harness.store);
    addTearDown(controller.dispose);

    await tester.pumpWidget(conflictsApp(controller, AppLocale.en));
    await pumpFrames(tester);

    await tester.tap(find.text('Keep the server version'));
    await tester.runAsync(() => Future<void>.delayed(Duration.zero));
    await pumpFrames(tester);

    final transaction =
        (await tester.runAsync(harness.repository.transactions))!.single;

    expect(transaction.amount.minorUnits, 9999);
    expect(harness.store.conflicts, isEmpty);
  });

  test('a resolution choice is one of exactly two', () {
    // Auto-resolving a money conflict is the one thing this screen must never
    // do, so there is deliberately no third "merge" option.
    expect(ConflictResolution.values.length, 2);
  });
}
