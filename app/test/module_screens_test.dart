import 'dart:io';

import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/money/allocation.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/modules_repository.dart';
import 'package:finora/domain/banking.dart';
import 'package:finora/domain/travel.dart';
import 'package:finora/presentation/features/assets/asset_detail_screen.dart';
import 'package:finora/presentation/features/assets/assets_screen.dart';
import 'package:finora/presentation/features/banking/banking_screen.dart';
import 'package:finora/presentation/features/banking/loan_detail_screen.dart';
import 'package:finora/presentation/features/buildings/buildings_screen.dart';
import 'package:finora/presentation/features/business/business_screen.dart';
import 'package:finora/presentation/features/business/invoice_detail_screen.dart';
import 'package:finora/presentation/features/investment/investment_screen.dart';
import 'package:finora/presentation/features/investment/position_detail_screen.dart';
import 'package:finora/presentation/features/more/more_screen.dart';
import 'package:finora/presentation/features/travel/travel_screen.dart';
import 'package:finora/presentation/features/travel/trip_detail_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// The module screens, driven the way a person reaches them: from the hub, in
/// both writing directions.
void main() {
  setUpAll(() async {
    // Without the shipped font the test engine measures every glyph as a full
    // em square, which makes Persian labels roughly twice their real width and
    // reports overflows that do not exist on a device.
    await _loadFont('Vazirmatn', [
      'assets/fonts/Vazirmatn-Regular.ttf',
      'assets/fonts/Vazirmatn-Bold.ttf',
    ]);
    await _loadFont('MaterialIcons', [
      '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
    ]);
  });

  /// A fixed clock so "overdue" and "due soon" mean the same thing on every
  /// run — the seeded dates are relative to it.
  final clock = DateTime(2026, 7, 25, 10);

  Future<void> pump(
    WidgetTester tester,
    Widget screen, {
    required AppLocale locale,
  }) async {
    tester.view.physicalSize = const Size(430, 932);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => locale),
          clockProvider.overrideWith((ref) => clock),
        ],
        child: MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: NeonTheme.dark(fontFamily: 'Vazirmatn'),
          locale: locale.locale,
          supportedLocales: [for (final l in AppLocale.supported) l.locale],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          builder: (context, child) => Directionality(
            textDirection: locale.textDirection,
            child: child ?? const SizedBox.shrink(),
          ),
          home: screen,
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  final screens = <String, Widget>{
    'banking': const BankingScreen(),
    'investment': const InvestmentScreen(),
    'assets': const AssetsScreen(),
    'travel': const TravelScreen(),
    'buildings': const BuildingsScreen(),
    'business': const BusinessScreen(),
    'more': const MoreScreen(),
  };

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    for (final entry in screens.entries) {
      testWidgets('${entry.key} builds in ${locale.code}', (tester) async {
        await pump(tester, entry.value, locale: locale);

        expect(tester.takeException(), isNull);
        expect(
          Directionality.of(tester.element(find.byType(Scaffold).first)),
          locale.textDirection,
        );
      });
    }
  }

  testWidgets('the hub lists every module and opens each one and back',
      (tester) async {
    await pump(tester, const MoreScreen(), locale: AppLocale.fa);

    for (final entry in moduleEntries) {
      final tile = find.byKey(ValueKey('module-${entry.id}'));
      expect(tile, findsOneWidget, reason: 'hub is missing ${entry.id}');

      // The hub scrolls now. A tile can be in the tree via the list's cache
      // extent while sitting off-screen, and a tap on it silently misses —
      // the failure then shows up as a missing back button one line later,
      // which reads like the opened screen is broken.
      await tester.ensureVisible(tile);
      await tester.pumpAndSettle();

      await tester.tap(tile);
      await tester.pumpAndSettle();
      expect(
        tester.takeException(),
        isNull,
        reason: 'opening ${entry.id} threw',
      );

      // Not `pageBack()`: it looks up the back button by its English tooltip,
      // which does not exist once the app is running in Persian.
      await tester.tap(find.byType(BackButton));
      await tester.pumpAndSettle();
      expect(
        tester.takeException(),
        isNull,
        reason: 'returning from ${entry.id} threw',
      );
      expect(find.byKey(ValueKey('module-${entry.id}')), findsOneWidget);
    }
  });

  testWidgets('amounts render through MoneyFormatter with Persian digits',
      (tester) async {
    await pump(tester, const InvestmentScreen(), locale: AppLocale.fa);

    // 3,475,000 + 8,299,800 + 3,654,000 + 4,182,000 minor units of TRY.
    expect(find.textContaining('۱۹۶٬۱۰۸٫۰۰'), findsWidgets);
  });

  testWidgets('the same amount uses Latin digits in English', (tester) async {
    await pump(tester, const InvestmentScreen(), locale: AppLocale.en);

    expect(find.textContaining('196,108.00'), findsWidgets);
  });

  testWidgets('the cheque list marks an overdue cheque differently from an '
      'upcoming one', (tester) async {
    await pump(tester, const BankingScreen(), locale: AppLocale.fa);

    final badges =
        tester.widgetList<ChequeUrgencyBadge>(find.byType(ChequeUrgencyBadge));
    final overdue = badges.firstWhere((b) => b.chequeId == 'chq-1');
    final upcoming = badges.firstWhere((b) => b.chequeId == 'chq-3');

    expect(overdue.urgency, ChequeUrgency.overdue);
    expect(upcoming.urgency, ChequeUrgency.upcoming);
    expect(overdue.accent, isNot(upcoming.accent));
    expect(overdue.label, isNot(upcoming.label));
  });

  testWidgets('a cheque approaching its due date reads amber, not magenta',
      (tester) async {
    await pump(tester, const BankingScreen(), locale: AppLocale.en);

    final badges =
        tester.widgetList<ChequeUrgencyBadge>(find.byType(ChequeUrgencyBadge));
    final soon = badges.firstWhere((b) => b.chequeId == 'chq-2');
    final overdue = badges.firstWhere((b) => b.chequeId == 'chq-1');

    expect(soon.urgency, ChequeUrgency.dueSoon);
    expect(soon.accent, isNot(overdue.accent));
  });

  testWidgets('the loan detail shows the full amortisation schedule',
      (tester) async {
    await pump(tester, const BankingScreen(), locale: AppLocale.fa);

    await tester.tap(find.text('وام‌ها'));
    await tester.pumpAndSettle();

    await tester.tap(find.byType(LoanCard).first);
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(LoanDetailScreen), findsOneWidget);
    expect(find.byType(InstallmentRow), findsWidgets);
  });

  testWidgets('the travel settlement view shows three transfers for the '
      'seeded Istanbul trip', (tester) async {
    await pump(tester, const TravelScreen(), locale: AppLocale.fa);

    await tester.tap(find.byType(TripCard).first);
    await tester.pumpAndSettle();

    expect(find.byType(TripDetailScreen), findsOneWidget);
    expect(find.byType(SettlementRow), findsNWidgets(3));
  });

  testWidgets('the second seeded trip settles in two transfers',
      (tester) async {
    await pump(tester, const TravelScreen(), locale: AppLocale.en);

    await tester.tap(find.byType(TripCard).last);
    await tester.pumpAndSettle();

    expect(find.byType(SettlementRow), findsNWidgets(2));
  });

  testWidgets('a position detail separates realized from unrealized profit',
      (tester) async {
    await pump(tester, const InvestmentScreen(), locale: AppLocale.fa);

    await tester.tap(find.byType(PositionCard).first);
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(PositionDetailScreen), findsOneWidget);
    expect(find.text('سود محقق‌شده'), findsOneWidget);
    expect(find.text('سود محقق‌نشده'), findsOneWidget);
  });

  testWidgets('an asset detail draws its depreciation curve', (tester) async {
    await pump(tester, const AssetsScreen(), locale: AppLocale.en);

    // The car is the first seeded asset with a depreciation method set.
    await tester.tap(find.byType(AssetCard).at(1));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(AssetDetailScreen), findsOneWidget);
    expect(find.byType(DepreciationChart), findsOneWidget);
  });

  testWidgets('the buildings screen grids every unit and lists debtors',
      (tester) async {
    await pump(tester, const BuildingsScreen(), locale: AppLocale.fa);

    expect(find.byType(UnitsGrid), findsOneWidget);
    expect(find.byType(UnitTile), findsNWidgets(12));

    await tester.drag(find.byType(ListView), const Offset(0, -1400));
    await tester.pumpAndSettle();
    expect(find.text('بدهکاران'), findsOneWidget);
    expect(find.byType(DebtorCard), findsWidgets);
  });

  testWidgets('an invoice detail lists its line items and totals',
      (tester) async {
    await pump(tester, const BusinessScreen(), locale: AppLocale.fa);

    await tester.tap(find.byType(InvoiceCard).first);
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(InvoiceDetailScreen), findsOneWidget);
    expect(find.text('جمع جزء'), findsOneWidget);
    expect(find.text('اقلام'), findsOneWidget);
  });

  testWidgets('the contacts tab of the business module builds', (tester) async {
    await pump(tester, const BusinessScreen(), locale: AppLocale.en);

    await tester.tap(find.text('Contacts'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.textContaining('Supplier'), findsWidgets);
  });

  // ------------------------------------------------------------------- domain

  test('settlement transfers clear every balance exactly', () async {
    final repository = InMemoryModulesRepository(
      baseCurrency: Currency.try_,
      now: clock,
    );
    final trips = await repository.trips();
    final trip = trips.first;

    final transfers = trip.settlements();
    expect(transfers.length, 3);

    final net = <String, int>{
      for (final balance in trip.balances())
        balance.member.id: balance.balance.minorUnits,
    };
    for (final transfer in transfers) {
      net[transfer.from.id] = net[transfer.from.id]! + transfer.amount.minorUnits;
      net[transfer.to.id] = net[transfer.to.id]! - transfer.amount.minorUnits;
    }

    expect(net.values.every((value) => value == 0), isTrue);
  });

  test('an equal split never loses or invents a minor unit', () {
    final expense = SplitExpense(
      id: 'x',
      title: 'y',
      amount: const Money(1000, Currency.try_),
      payerId: 'a',
      participantIds: const ['a', 'b', 'c'],
      occurredAt: DateTime(2026),
    );
    const members = [
      TripMember(id: 'a', name: 'A'),
      TripMember(id: 'b', name: 'B'),
      TripMember(id: 'c', name: 'C'),
    ];

    final shares = expense.shares(members);
    final total = shares.values.fold<int>(0, (s, m) => s + m.minorUnits);

    expect(total, 1000);
    expect(shares.values.map((m) => m.minorUnits).toList(), [334, 333, 333]);
  });

  test('allocation hands the remainder to the heaviest weights', () {
    final parts = MoneyAllocation.byWeights(
      const Money(1000, Currency.try_),
      [5, 3, 2],
    );

    expect(parts.map((m) => m.minorUnits).toList(), [500, 300, 200]);
    expect(
      MoneyAllocation.evenly(const Money(100, Currency.try_), 3)
          .fold<int>(0, (s, m) => s + m.minorUnits),
      100,
    );
  });

  test('an annuity schedule repays exactly the principal', () {
    const principal = Money(24000000, Currency.try_);
    final schedule = amortize(
      principal: principal,
      annualRatePercent: 18.5,
      installmentsCount: 36,
      startDate: DateTime(2026),
      interestType: LoanInterestType.compound,
    );

    expect(schedule.length, 36);
    expect(
      schedule.fold<int>(0, (sum, i) => sum + i.principalPart.minorUnits),
      principal.minorUnits,
    );
    expect(schedule.first.dueDate, DateTime(2026, 2));
  });

  test('a flat schedule splits principal and interest evenly', () {
    const principal = Money(9000000, Currency.try_);
    final schedule = amortize(
      principal: principal,
      annualRatePercent: 12,
      installmentsCount: 24,
      startDate: DateTime(2026),
      interestType: LoanInterestType.simple,
    );

    expect(
      schedule.fold<int>(0, (sum, i) => sum + i.principalPart.minorUnits),
      principal.minorUnits,
    );
    // 12 % a year over two years on 9,000,000 minor units.
    expect(
      schedule.fold<int>(0, (sum, i) => sum + i.interestPart.minorUnits),
      2160000,
    );
  });

  test('the depreciation curve lands exactly on the salvage value', () async {
    final repository = InMemoryModulesRepository(
      baseCurrency: Currency.try_,
      now: clock,
    );
    final assets = await repository.assets();

    for (final asset in assets) {
      final curve = asset.depreciationCurve();
      if (curve.isEmpty) continue;
      expect(curve.last.closing, asset.salvageValue, reason: asset.name);
      expect(curve.length, asset.usefulLifeYears);
    }
  });

  test('the debtors list is ordered by the amount owed', () async {
    final repository = InMemoryModulesRepository(
      baseCurrency: Currency.try_,
      now: clock,
    );
    final building = await repository.building();
    final debtors = building.debtors();

    expect(debtors, isNotEmpty);
    for (var i = 1; i < debtors.length; i++) {
      expect(
        debtors[i - 1].owed.minorUnits >= debtors[i].owed.minorUnits,
        isTrue,
      );
    }
  });

  test('invoice totals stay consistent with their line items', () async {
    final repository = InMemoryModulesRepository(
      baseCurrency: Currency.try_,
      now: clock,
    );
    final ledger = await repository.business();

    for (final invoice in ledger.invoices) {
      final lines = invoice.items
          .fold<int>(0, (sum, item) => sum + item.lineTotal.minorUnits);
      expect(lines, invoice.subtotal.minorUnits, reason: invoice.number);
      expect(
        invoice.subtotal.minorUnits -
            invoice.discount.minorUnits +
            invoice.tax.minorUnits,
        invoice.total.minorUnits,
        reason: invoice.number,
      );
    }
  });
}

/// Locates the SDK so the icon font can be loaded without hard-coding a path.
String _flutterRoot() {
  final fromEnv = Platform.environment['FLUTTER_ROOT'];
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;

  var dir = File(Platform.resolvedExecutable).parent;
  while (dir.path != dir.parent.path) {
    if (Directory('${dir.path}/bin/cache/artifacts/material_fonts').existsSync()) {
      return dir.path;
    }
    dir = dir.parent;
  }
  return '';
}

Future<void> _loadFont(String family, List<String> paths) async {
  final loader = FontLoader(family);

  for (final path in paths) {
    final file = File(path);
    if (!file.existsSync()) continue;
    loader.addFont(
      file.readAsBytes().then((bytes) => ByteData.view(bytes.buffer)),
    );
  }

  await loader.load();
}
