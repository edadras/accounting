import 'package:finora/core/date/date_formatter.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/data/travel_repository.dart';
import 'package:finora/domain/travel.dart';
import 'package:finora/presentation/features/travel/travel_screen.dart';
import 'package:finora/presentation/features/travel/trip_map.dart';
import 'package:finora/presentation/features/travel/trip_map_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/membership_harness.dart';

/// The trip expense map.
///
/// Nothing here calls `pumpAndSettle`; frames are pumped explicitly, in line
/// with the rest of this suite. Nothing here touches Dio either — the map is
/// drawn from a trip the caller already holds.
void main() {
  setUpAll(loadTestFonts);

  const currency = Currency.try_;

  const members = [
    TripMember(id: 'm-a', name: 'Ali'),
    TripMember(id: 'm-b', name: 'Sara'),
  ];

  SplitExpense expense(
    String id,
    int amount, {
    double? lat,
    double? lng,
    String? title,
  }) =>
      SplitExpense(
        id: id,
        title: title ?? id,
        amount: Money(amount, currency),
        payerId: 'm-a',
        participantIds: const ['m-a', 'm-b'],
        occurredAt: DateTime.utc(2026, 7, 20),
        latitude: lat,
        longitude: lng,
      );

  Trip tripWith(List<SplitExpense> expenses) => Trip(
        id: 'trip-1',
        name: 'Istanbul',
        destination: 'Istanbul',
        startsAt: DateTime.utc(2026, 7, 1),
        endsAt: DateTime.utc(2026, 7, 8),
        baseCurrency: currency,
        members: members,
        expenses: expenses,
      );

  const size = Size(320, 260);

  String tr(AppLocale locale, String key, {Map<String, String>? args}) =>
      Translator(locale: locale)(key, args: args);

  // ------------------------------------------------------------- the geometry

  test('a trip with no coordinates plots nothing and says how many', () {
    final layout = TripMapProjection.layout(
      [expense('a', 100), expense('b', 200)],
      size: size,
    );

    expect(layout.isEmpty, isTrue);
    expect(layout.markers, isEmpty);
    expect(layout.placed, 0);
    expect(layout.unplaced, 2);
    expect(layout.isSinglePoint, isFalse);
  });

  test('half a coordinate places nothing', () {
    final layout = TripMapProjection.layout(
      [expense('a', 100, lat: 41.0), expense('b', 200, lng: 28.9)],
      size: size,
    );

    expect(layout.placed, 0);
    expect(layout.unplaced, 2);
  });

  test('every expense at one identical point still lays out finitely', () {
    // The naive normalisation divides by a zero span here, which yields NaN
    // for every marker and paints nothing at all.
    final layout = TripMapProjection.layout(
      [
        expense('a', 100, lat: 41.0082, lng: 28.9784),
        expense('b', 200, lat: 41.0082, lng: 28.9784),
        expense('c', 300, lat: 41.0082, lng: 28.9784),
      ],
      size: size,
    );

    expect(layout.isSinglePoint, isTrue);
    expect(layout.markers, hasLength(3));

    for (final marker in layout.markers) {
      expect(marker.position.dx.isFinite, isTrue);
      expect(marker.position.dy.isFinite, isTrue);
      expect(marker.radius.isFinite, isTrue);
      expect(marker.position.dx, inInclusiveRange(0, size.width));
      expect(marker.position.dy, inInclusiveRange(0, size.height));
    }

    // Fanned, so each one can still be aimed at.
    final positions = layout.markers.map((m) => m.position).toSet();
    expect(positions, hasLength(3));
  });

  test('one placed expense sits in the middle and is not "the largest"', () {
    final layout = TripMapProjection.layout(
      [expense('a', 100, lat: 41.0, lng: 28.9)],
      size: size,
    );

    expect(layout.markers, hasLength(1));
    expect(layout.markers.single.position, const Offset(160, 130));
    expect(
      layout.markers.single.isLargest,
      isFalse,
      reason: 'nothing is the largest when there is nothing to compare it to',
    );
  });

  test('a collapsed axis flattens only itself', () {
    // Same latitude, different longitudes: a real east–west spread and no
    // north–south one.
    final layout = TripMapProjection.layout(
      [
        expense('a', 100, lat: 41.0, lng: 28.0),
        expense('b', 100, lat: 41.0, lng: 29.0),
      ],
      size: size,
    );

    expect(layout.isSinglePoint, isFalse);
    final ys = layout.markers.map((m) => m.position.dy).toSet();
    expect(ys, hasLength(1), reason: 'the flat axis is centred');
    expect(ys.single, 130);

    final xs = layout.markers.map((m) => m.position.dx).toList();
    expect(xs.first, lessThan(xs.last), reason: 'the live axis still spreads');
  });

  test('equal amounts give equal markers and no glow', () {
    final layout = TripMapProjection.layout(
      [
        expense('a', 500, lat: 41.0, lng: 28.0),
        expense('b', 500, lat: 42.0, lng: 29.0),
      ],
      size: size,
    );

    expect(layout.markers.map((m) => m.radius).toSet(), hasLength(1));
    expect(layout.markers.every((m) => !m.isLargest), isTrue);
  });

  test('the marker for the biggest amount is the biggest and the lit one', () {
    final layout = TripMapProjection.layout(
      [
        expense('small', 100, lat: 41.0, lng: 28.0),
        expense('big', 900, lat: 42.0, lng: 29.0),
        expense('mid', 500, lat: 41.5, lng: 28.5),
      ],
      size: size,
    );

    final byId = {for (final m in layout.markers) m.expense.id: m};

    expect(byId['big']!.isLargest, isTrue);
    expect(byId['mid']!.isLargest, isFalse);
    expect(byId['small']!.isLargest, isFalse);
    expect(byId['big']!.radius, greaterThan(byId['mid']!.radius));
    expect(byId['mid']!.radius, greaterThan(byId['small']!.radius));
  });

  test('north is up', () {
    final layout = TripMapProjection.layout(
      [
        expense('north', 100, lat: 42.0, lng: 28.0),
        expense('south', 100, lat: 41.0, lng: 29.0),
      ],
      size: size,
    );

    final byId = {for (final m in layout.markers) m.expense.id: m};
    expect(byId['north']!.position.dy, lessThan(byId['south']!.position.dy));
  });

  // ---------------------------------------------------------------- the screen

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    testWidgets('the map builds in ${locale.code}', (tester) async {
      await pumpMembershipApp(
        tester,
        TripMapScreen(
          trip: tripWith([
            expense('a', 100, lat: 41.0, lng: 28.0, title: 'هتل'),
            expense('b', 900, lat: 42.0, lng: 29.0, title: 'پرواز'),
          ]),
        ),
        locale: locale,
      );

      expect(tester.takeException(), isNull);
      expect(find.byType(TripMapPlot), findsOneWidget);
      expect(
        Directionality.of(tester.element(find.byType(Scaffold).first)),
        locale.textDirection,
      );
    });
  }

  testWidgets('the plot itself is never mirrored, whatever the script reads',
      (tester) async {
    await pumpMembershipApp(
      tester,
      TripMapScreen(
        trip: tripWith([
          expense('a', 100, lat: 41.0, lng: 28.0),
          expense('b', 900, lat: 42.0, lng: 29.0),
        ]),
      ),
      locale: AppLocale.fa,
    );

    // East has to stay on the right: direction here is a compass, not a
    // reading order.
    final inner = tester.widget<Directionality>(
      find
          .descendant(
            of: find.byType(TripMapPlot),
            matching: find.byType(Directionality),
          )
          .first,
    );
    expect(inner.textDirection, TextDirection.ltr);
  });

  testWidgets('a trip with no located expense says so and draws no plot',
      (tester) async {
    await pumpMembershipApp(
      tester,
      TripMapScreen(trip: tripWith([expense('a', 100), expense('b', 200)])),
      locale: AppLocale.fa,
    );

    expect(tester.takeException(), isNull);
    expect(find.byType(TripMapPlot), findsNothing);
    expect(find.text(tr(AppLocale.fa, 'travel.mapEmpty')), findsOneWidget);
    expect(find.text(tr(AppLocale.fa, 'travel.mapEmptyHint')), findsOneWidget);
    expect(
      find.text(tr(AppLocale.fa, 'travel.mapUnplaced',
          args: {'count': DateFormatter.number(2, 'fa')},),),
      findsOneWidget,
    );
  });

  testWidgets('every expense at one point is disclosed, not disguised',
      (tester) async {
    await pumpMembershipApp(
      tester,
      TripMapScreen(
        trip: tripWith([
          expense('a', 100, lat: 41.0082, lng: 28.9784),
          expense('b', 200, lat: 41.0082, lng: 28.9784),
          expense('c', 300, lat: 41.0082, lng: 28.9784),
        ]),
      ),
      locale: AppLocale.en,
    );

    expect(tester.takeException(), isNull);
    expect(find.text(tr(AppLocale.en, 'travel.mapSamePlace')), findsOneWidget);

    // Coincident or not, each marker keeps its own tap target.
    for (final id in ['a', 'b', 'c']) {
      expect(find.byKey(ValueKey('trip-map-marker-$id')), findsOneWidget);
    }
  });

  testWidgets('tapping a marker names the expense behind it', (tester) async {
    await pumpMembershipApp(
      tester,
      TripMapScreen(
        trip: tripWith([
          expense('a', 12300, lat: 41.0082, lng: 28.9784, title: 'Hotel'),
          expense('b', 900, lat: 42.0, lng: 29.5, title: 'Museum'),
        ]),
      ),
      locale: AppLocale.en,
    );

    expect(find.text(tr(AppLocale.en, 'travel.mapSelectHint')), findsOneWidget);
    expect(find.byType(SelectedExpenseCard), findsNothing);

    await tester.tap(find.byKey(const ValueKey('trip-map-marker-a')));
    await pumpFrames(tester);

    expect(find.byType(SelectedExpenseCard), findsOneWidget);
    expect(find.text('Hotel'), findsOneWidget);
    expect(find.text(tr(AppLocale.en, 'travel.coordinates')), findsOneWidget);
    // The coordinate is printed, isolated so it keeps its own reading order.
    expect(find.textContaining('41.0082'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('trip-map-marker-b')));
    await pumpFrames(tester);

    expect(find.text('Museum'), findsOneWidget);
    expect(find.text('Hotel'), findsNothing);
  });

  testWidgets('the legend counts what is placed and what is not',
      (tester) async {
    await pumpMembershipApp(
      tester,
      TripMapScreen(
        trip: tripWith([
          expense('a', 100, lat: 41.0, lng: 28.0),
          expense('b', 900, lat: 42.0, lng: 29.0),
          expense('c', 300),
        ]),
      ),
      locale: AppLocale.en,
    );

    expect(
      find.text(tr(AppLocale.en, 'travel.mapPlaced',
          args: {'count': DateFormatter.number(2, 'en')},),),
      findsOneWidget,
    );
    expect(
      find.text(tr(AppLocale.en, 'travel.mapUnplaced',
          args: {'count': DateFormatter.number(1, 'en')},),),
      findsOneWidget,
    );
    expect(find.text(tr(AppLocale.en, 'travel.mapSizeLegend')), findsOneWidget);
    expect(find.text(tr(AppLocale.en, 'travel.mapLargest')), findsOneWidget);
  });

  // ------------------------------------------------------------ the way in

  testWidgets('the trip list opens the map and comes back', (tester) async {
    await pumpMembershipApp(tester, const TravelScreen(), locale: AppLocale.fa);

    final chip = find.byKey(const ValueKey('trip-map-trip-istanbul'));
    expect(chip, findsOneWidget);

    await tester.tap(chip);
    await pumpFrames(tester, frames: 12);

    expect(tester.takeException(), isNull);
    expect(find.byType(TripMapScreen), findsOneWidget);

    // The seeded Istanbul trip records three of its four expenses with a
    // position, which is what the map has to report.
    expect(
      find.text(tr(AppLocale.fa, 'travel.mapPlaced',
          args: {'count': DateFormatter.number(3, 'fa')},),),
      findsWidgets,
    );

    await tester.tap(find.byType(BackButton));
    await pumpFrames(tester, frames: 12);

    expect(find.byType(TravelScreen), findsOneWidget);
  });

  // ---------------------------------------------------------- the wire format

  test('SplitExpenseResource carries the coordinates the map needs', () {
    final decoded = TravelRepository.expenseFromJson({
      'id': 'sx-1',
      'trip_id': 'trip-1',
      'payer_member_id': 'm-a',
      'amount': {
        'value': 24000,
        'currency': 'TRY',
        'minor_unit': 2,
        'decimal': '240.00',
      },
      'base': {
        'value': 24000,
        'currency': 'TRY',
        'minor_unit': 2,
        'decimal': '240.00',
        'fx_rate': '1.000000000000',
      },
      'category_id': null,
      'occurred_at': '2026-07-20T10:00:00+00:00',
      'description': 'رستوران',
      'latitude': 41.0369,
      'longitude': 28.985,
      'shares': [
        {
          'id': 's1',
          'member_id': 'm-a',
          'mode': 'equal',
          'amount': {'value': 12000, 'currency': 'TRY', 'minor_unit': 2},
          'base': {'value': 12000, 'currency': 'TRY', 'minor_unit': 2},
        },
        {
          'id': 's2',
          'member_id': 'm-b',
          'mode': 'equal',
          'amount': {'value': 12000, 'currency': 'TRY', 'minor_unit': 2},
          'base': {'value': 12000, 'currency': 'TRY', 'minor_unit': 2},
        },
      ],
    });

    expect(decoded.hasLocation, isTrue);
    expect(decoded.latitude, closeTo(41.0369, 1e-9));
    expect(decoded.longitude, closeTo(28.985, 1e-9));
    expect(decoded.title, 'رستوران');
    expect(decoded.amount.minorUnits, 24000);
    expect(decoded.participantIds, ['m-a', 'm-b']);
  });

  test('a driver that sends decimals as strings still places the expense', () {
    final decoded = TravelRepository.expenseFromJson({
      'id': 'sx-2',
      'payer_member_id': 'm-a',
      'base': {'value': 100, 'currency': 'TRY', 'minor_unit': 2},
      'occurred_at': '2026-07-20T10:00:00+00:00',
      'latitude': '41.0082000',
      'longitude': '28.9784000',
      'shares': const [],
    });

    expect(decoded.latitude, closeTo(41.0082, 1e-9));
    expect(decoded.longitude, closeTo(28.9784, 1e-9));
  });

  test('an expense the server never located stays unplaced', () {
    final decoded = TravelRepository.expenseFromJson({
      'id': 'sx-3',
      'payer_member_id': 'm-a',
      'base': {'value': 100, 'currency': 'TRY', 'minor_unit': 2},
      'occurred_at': '2026-07-20T10:00:00+00:00',
      'latitude': null,
      'longitude': null,
      'shares': const [],
    });

    expect(decoded.hasLocation, isFalse);
  });
}
