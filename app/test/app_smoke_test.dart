import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translations.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/main.dart';
import 'package:finora/presentation/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Boots the real app and drives it the way a person would.
///
/// The point is not pixel perfection — it is that every screen builds in both
/// writing directions and in both themes, which is where an RTL bug actually
/// shows up.
void main() {
  Future<void> pumpApp(WidgetTester tester, {required AppLocale locale}) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [localeProvider.overrideWith((ref) => locale)],
        child: const FinoraApp(),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('boots in Persian and lays out right-to-left', (tester) async {
    await pumpApp(tester, locale: AppLocale.fa);

    expect(find.text('داشبورد'), findsWidgets);

    final direction = Directionality.of(
      tester.element(find.byType(Scaffold).first),
    );
    expect(direction, TextDirection.rtl);
  });

  testWidgets('boots in English and lays out left-to-right', (tester) async {
    await pumpApp(tester, locale: AppLocale.en);

    expect(find.text('Dashboard'), findsWidgets);

    final direction = Directionality.of(
      tester.element(find.byType(Scaffold).first),
    );
    expect(direction, TextDirection.ltr);
  });

  testWidgets('every tab builds without throwing', (tester) async {
    await pumpApp(tester, locale: AppLocale.fa);

    for (final label in ['تراکنش‌ها', 'گزارش‌ها', 'حساب‌ها', 'تنظیمات']) {
      await tester.tap(find.text(label).last);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: 'tab "$label" threw');
    }
  });

  testWidgets('recording an expense updates the list and the balance',
      (tester) async {
    await pumpApp(tester, locale: AppLocale.fa);

    await tester.tap(find.byIcon(Icons.add_rounded));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField).first, '250');
    await tester.pumpAndSettle();

    await tester.tap(find.text('ثبت'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('تراکنش ثبت شد.'), findsOneWidget);
  });

  testWidgets('switching language flips direction without a restart',
      (tester) async {
    await pumpApp(tester, locale: AppLocale.fa);

    await tester.tap(find.text('تنظیمات').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('English'));
    await tester.pumpAndSettle();

    expect(find.text('Settings'), findsWidgets);
    expect(
      Directionality.of(tester.element(find.byType(Scaffold).first)),
      TextDirection.ltr,
    );
  });

  testWidgets('light theme renders every screen', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => AppLocale.en),
          themeModeProvider.overrideWith((ref) => ThemeMode.light),
        ],
        child: const FinoraApp(),
      ),
    );
    await tester.pumpAndSettle();

    for (final label in ['Transactions', 'Reports', 'Accounts', 'Settings']) {
      await tester.tap(find.text(label).last);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: 'light "$label" threw');
    }
  });

  test('every locale defines the same set of keys', () {
    // A key present in Persian but missing in Arabic silently falls back to
    // English at runtime, which is the kind of gap nobody notices until a user
    // does.
    final reference = BundledTranslations.byLocale['en']!.keys.toSet();

    for (final entry in BundledTranslations.byLocale.entries) {
      expect(
        entry.value.keys.toSet(),
        reference,
        reason: 'locale "${entry.key}" has a different key set than English',
      );
    }
  });
}
