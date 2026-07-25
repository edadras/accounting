import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/presentation/features/conflicts/conflicts_screen.dart';
import 'package:finora/presentation/features/documents/documents_screen.dart';
import 'package:finora/presentation/features/more/more_screen.dart';
import 'package:finora/presentation/features/reports/reports_screen.dart';
import 'package:finora/presentation/shell.dart';
import 'package:finora/sync/sync_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Three screens were finished, tested, and reachable from nothing.
///
/// A reachability hole is invisible to every other kind of test: each of these
/// screens passes its own suite whether or not a single line of the app opens
/// it. `ConflictsScreen` was the expensive one — the sync engine has always
/// counted conflicts, so an edit that lost a race with the server was already
/// being detected and simply could not be looked at.
void main() {
  Widget harness(
    Widget child, {
    List<Override> overrides = const [],
    List<NavigatorObserver> observers = const [],
  }) {
    return ProviderScope(
      overrides: [
        localeProvider.overrideWith((ref) => AppLocale.en),
        ...overrides,
      ],
      child: MaterialApp(
        navigatorObservers: observers,
        home: Directionality(
          textDirection: TextDirection.ltr,
          child: child,
        ),
      ),
    );
  }

  testWidgets('the module hub opens documents', (tester) async {
    tester.view.physicalSize = const Size(430, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(harness(const MoreScreen()));
    await tester.pumpAndSettle();

    final tile = find.byKey(const ValueKey('module-documents'));
    await tester.ensureVisible(tile);
    await tester.pumpAndSettle();
    await tester.tap(tile);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 350));

    expect(find.byType(DocumentsScreen), findsOneWidget);
  });

  testWidgets('the reports screen opens the export screen', (tester) async {
    tester.view.physicalSize = const Size(430, 1800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(harness(const Scaffold(body: ReportsScreen())));
    await tester.pumpAndSettle();

    final tile = find.byKey(const ValueKey('reports-export'));
    await tester.ensureVisible(tile);
    await tester.pumpAndSettle();
    await tester.tap(tile);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 350));

    expect(find.text('Export report'), findsWidgets);
  });

  group('the conflict chip', () {
    testWidgets('is absent while there is nothing to resolve', (tester) async {
      await tester.pumpWidget(harness(const AppShell()));
      await tester.pumpAndSettle();

      // This is what keeps the demo build and all 11 goldens unchanged: at zero
      // conflicts the shell must render exactly what it rendered before.
      expect(find.byKey(const ValueKey('conflicts-chip')), findsNothing);
    });

    testWidgets('appears with a count and opens the resolver', (tester) async {
      final observer = _RouteRecorder();

      await tester.pumpWidget(harness(
        const AppShell(),
        overrides: [
          syncStateProvider.overrideWith((ref) => const SyncState(conflicts: 3)),
        ],
        observers: [observer],
      ),);
      await tester.pumpAndSettle();

      final chip = find.byKey(const ValueKey('conflicts-chip'));
      expect(chip, findsOneWidget);
      expect(find.text('3'), findsOneWidget);

      // Deliberately not pumped afterwards. `Navigator.push` runs inside the
      // tap, so the route is recorded immediately; letting a frame build it
      // would construct ConflictsScreen, which needs a live SyncController that
      // by design exists only once a backend is configured. What the screen
      // then renders is offline_ui_test's job, against a real sync harness.
      await tester.tap(chip);

      final routes = observer.pushed.whereType<MaterialPageRoute<void>>();
      expect(routes, isNotEmpty);
      // Constructing the widget is not building it — `const ConflictsScreen()`
      // reads no provider until a frame renders it.
      expect(
        routes.last.builder(tester.element(find.byType(AppShell))),
        isA<ConflictsScreen>(),
      );

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    });
  });
}

/// Records pushes so a route can be inspected without rendering its screen.
class _RouteRecorder extends NavigatorObserver {
  final List<Route<dynamic>> pushed = [];

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    pushed.add(route);
    super.didPush(route, previousRoute);
  }
}
