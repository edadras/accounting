import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/data/modules_repository.dart' show clockProvider;
import 'package:finora/main.dart';
import 'package:finora/presentation/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// "Today" must mean today according to the injected clock.
///
/// This screen decided it from `DateTime.now()` while its rows sat at offsets
/// from the pinned clock, so the transactions goldens passed all day and failed
/// the moment the date rolled over — a suite that breaks at midnight with no
/// commit behind it. The goldens cannot catch the regression on their own,
/// because they only disagree on the days they happen to straddle.
void main() {
  Future<void> pumpAt(WidgetTester tester, DateTime now) async {
    // Wider than a phone on purpose: without the real font loaded the default
    // test typeface is wider and the row overflows, which has nothing to do
    // with what this test is checking.
    tester.view.physicalSize = const Size(700, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => AppLocale.en),
          selectedTabProvider.overrideWith((ref) => 1),
          clockProvider.overrideWithValue(now),
        ],
        child: const FinoraApp(),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('the day heading follows the injected clock, not the wall clock',
      (tester) async {
    // The seeded rows are placed relative to this instant, so the newest of
    // them is "Today" by construction.
    await pumpAt(tester, DateTime.utc(2026, 7, 25, 12));
    expect(find.text('Today'), findsWidgets);

    // Same data, clock advanced one day: what was Today has to become
    // Yesterday. Reading the wall clock instead would leave this unchanged
    // whenever the test happened to run on the pinned date.
    await pumpAt(tester, DateTime.utc(2026, 7, 26, 12));
    expect(find.text('Yesterday'), findsWidgets);
  });
}
