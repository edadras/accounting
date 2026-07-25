import 'dart:io';

import 'package:finora/core/date/date_formatter.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/data_repository.dart';
import 'package:finora/data/modules_repository.dart' show clockProvider;
import 'package:finora/presentation/features/data/account_deletion_screen.dart';
import 'package:finora/presentation/features/data/data_export_screen.dart';
import 'package:finora/presentation/features/data/data_providers.dart';
import 'package:finora/presentation/features/data/report_export_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'data_fake_server.dart';

/// The data & privacy screens, driven the way a person drives them.
///
/// Two rules keep this file from hanging instead of failing, both inherited
/// from `offline_ui_test.dart`:
///
/// **Anything that reaches Dio runs inside `tester.runAsync`.** `testWidgets`
/// drives a fake clock and Dio completes on real timers the fake clock never
/// advances, so an `await` on a request never returns — and blocks the isolate
/// where no `--timeout` can reach it.
///
/// **Nothing calls `pumpAndSettle`.** These screens hold a poll timer and a
/// progress spinner while an export is building, so the tree never goes quiet
/// and `pumpAndSettle` would wait out its whole budget.
void main() {
  setUpAll(() async {
    await _loadFont('Vazirmatn', [
      'assets/fonts/Vazirmatn-Regular.ttf',
      'assets/fonts/Vazirmatn-Bold.ttf',
    ]);
    await _loadFont('MaterialIcons', [
      '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
    ]);
  });

  /// Pinned so "irreversible on" is a fixed date rather than today plus thirty.
  final clock = DateTime.utc(2026, 7, 25, 12);

  String tr(AppLocale locale, String key, {Map<String, String>? args}) =>
      Translator(locale: locale)(key, args: args);

  Future<void> pump(
    WidgetTester tester,
    Widget screen, {
    required AppLocale locale,
    required FakeDataOpsServer server,
    ScheduledDeletion? scheduled,
  }) async {
    tester.view.physicalSize = const Size(430, 932);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => locale),
          clockProvider.overrideWithValue(clock),
          dataRepositoryProvider.overrideWithValue(
            DataRepository(
              client: dataOpsClient(server),
              // A poll costs microseconds here, so the attempt budget is spent
              // in a blink; raising it keeps "still queued" from being mistaken
              // for "gave up" while a test decides when to move the job on.
              maxPollAttempts: 100000,
            ),
          ),
          // Polling has to advance on the fake clock one pump at a time; a
          // three-second wait would need three thousand of them.
          dataPollIntervalProvider.overrideWithValue(
            const Duration(milliseconds: 20),
          ),
          scheduledDeletionProvider.overrideWith((ref) => scheduled),
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
    await flush(tester);
  }

  group('data export', () {
    testWidgets('a request shows as queued, then ready with a link',
        (tester) async {
      final server = FakeDataOpsServer();
      await pump(tester, const DataExportScreen(),
          locale: AppLocale.fa, server: server,);

      // Nothing hidden: the archive says what is in it before it is asked for.
      expect(find.text(tr(AppLocale.fa, 'data.export.contents')), findsOneWidget);
      expect(find.text(tr(AppLocale.fa, 'data.export.empty')), findsOneWidget);

      await tester.tap(find.text(tr(AppLocale.fa, 'data.export.request')));
      await flush(tester, rounds: 2);

      expect(find.text(tr(AppLocale.fa, 'data.status.pending')), findsWidgets);
      expect(find.text(tr(AppLocale.fa, 'data.export.building')), findsOneWidget);
      expect(
        find.textContaining(FakeDataOpsServer.downloadUrl),
        findsNothing,
        reason: 'a queued export has nothing to download yet',
      );

      server.exportStatus = 'ready';
      server.exportSize = 4 * 1024 * 1024;
      await flush(tester);

      expect(find.text(tr(AppLocale.fa, 'data.export.ready')), findsOneWidget);
      expect(find.textContaining(FakeDataOpsServer.downloadUrl), findsWidgets);
      expect(
        find.text(tr(AppLocale.fa, 'data.size.mb', args: {'size': '\u{2068}۴\u{2069}'})),
        findsWidgets,
      );

      // The link is the whole deliverable, so copying it has to work.
      await tester.tap(find.text(tr(AppLocale.fa, 'data.export.copy')).first);
      await flush(tester, rounds: 2);
      expect(find.text(tr(AppLocale.fa, 'data.export.copied')), findsOneWidget);

      await _unmount(tester);
    });

    testWidgets('a failed export shows its state instead of spinning',
        (tester) async {
      final server = FakeDataOpsServer()..exportStatus = 'failed';
      await pump(tester, const DataExportScreen(),
          locale: AppLocale.fa, server: server,);

      await tester.tap(find.text(tr(AppLocale.fa, 'data.export.request')));
      await flush(tester);

      expect(find.text(tr(AppLocale.fa, 'data.status.failed')), findsWidgets);
      expect(find.text(tr(AppLocale.fa, 'data.export.failed')), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);

      await _unmount(tester);
    });

    testWidgets('past exports are listed with their size and expiry',
        (tester) async {
      final server = FakeDataOpsServer()
        ..history = [FakeDataOpsServer.pastExport()];
      await pump(tester, const DataExportScreen(),
          locale: AppLocale.en, server: server,);

      expect(find.text(tr(AppLocale.en, 'data.export.empty')), findsNothing);
      expect(find.text(tr(AppLocale.en, 'data.size.mb', args: {'size': '\u{2068}2\u{2069}'})), findsOneWidget);

      await _unmount(tester);
    });
  });

  group('account deletion', () {
    testWidgets('it asks for a password and states the irreversible date',
        (tester) async {
      final server = FakeDataOpsServer();
      await pump(tester, const AccountDeletionScreen(),
          locale: AppLocale.en, server: server,);

      expect(find.text(tr(AppLocale.en, 'data.delete.whatBody')), findsOneWidget);

      // Confirming with an empty field asks for the password rather than
      // deleting anything.
      await tester.tap(find.text(tr(AppLocale.en, 'data.delete.confirm')));
      await flush(tester, rounds: 2);
      expect(
        find.text(tr(AppLocale.en, 'data.delete.passwordRequired')),
        findsOneWidget,
      );
      expect(server.deleteCalls, 0);

      await tester.enterText(find.byType(TextField), 'hunter2');
      await tester.tap(find.text(tr(AppLocale.en, 'data.delete.confirm')));
      await flush(tester);

      final deadline = tr(
        AppLocale.en,
        'data.delete.irreversible',
        args: {'date': _shortDate(FakeDataOpsServer.purgeAfter, AppLocale.en)},
      );

      expect(server.deleteCalls, 1);
      expect(find.text(deadline), findsOneWidget);
      expect(find.text(tr(AppLocale.en, 'data.delete.scheduled')), findsOneWidget);
      // The form is gone; the way back is the only thing left to press.
      expect(find.text(tr(AppLocale.en, 'data.delete.confirm')), findsNothing);
      expect(find.text(tr(AppLocale.en, 'data.delete.restore')), findsOneWidget);

      await _unmount(tester);
    });

    testWidgets('a wrong password renders the translated code, not English',
        (tester) async {
      final server = FakeDataOpsServer()..passwordAccepted = false;
      await pump(tester, const AccountDeletionScreen(),
          locale: AppLocale.fa, server: server,);

      await tester.enterText(find.byType(TextField), 'wrong');
      await tester.tap(find.text(tr(AppLocale.fa, 'data.delete.confirm')));
      await flush(tester);

      expect(
        find.text(tr(AppLocale.fa, 'error.incorrect_password')),
        findsOneWidget,
      );
      expect(
        find.textContaining('does not match this account'),
        findsNothing,
        reason: 'the server sentence must never reach the screen',
      );

      await _unmount(tester);
    });

    testWidgets('restoring cancels the deletion and the banner goes away',
        (tester) async {
      final server = FakeDataOpsServer();
      await pump(
        tester,
        const AccountDeletionScreen(),
        locale: AppLocale.fa,
        server: server,
        scheduled: ScheduledDeletion(
          purgeAfter: DateTime.parse(FakeDataOpsServer.purgeAfter),
          graceDays: 30,
        ),
      );

      final banner = tr(
        AppLocale.fa,
        'data.banner.scheduled',
        args: {'date': _shortDate(FakeDataOpsServer.purgeAfter, AppLocale.fa)},
      );
      expect(find.text(banner), findsOneWidget);

      await tester.tap(find.text(tr(AppLocale.fa, 'data.banner.restore')));
      await flush(tester);

      expect(server.restoreCalls, 1);
      expect(find.text(banner), findsNothing);
      expect(find.text(tr(AppLocale.fa, 'data.delete.restored')), findsNothing,
          reason: 'the banner restored it, so the screen-level notice is not shown',);
      // The delete form is back, because there is no longer a deletion pending.
      expect(find.text(tr(AppLocale.fa, 'data.delete.confirm')), findsOneWidget);

      await _unmount(tester);
    });

    testWidgets('the panel restore is as reachable as the delete button',
        (tester) async {
      final server = FakeDataOpsServer();
      await pump(
        tester,
        const AccountDeletionScreen(),
        locale: AppLocale.en,
        server: server,
        scheduled: ScheduledDeletion(
          purgeAfter: DateTime.parse(FakeDataOpsServer.purgeAfter),
          graceDays: 30,
        ),
      );

      await tester.tap(find.text(tr(AppLocale.en, 'data.delete.restore')));
      await flush(tester);

      expect(server.restoreCalls, 1);
      expect(find.text(tr(AppLocale.en, 'data.delete.restored')), findsOneWidget);

      await _unmount(tester);
    });
  });

  group('report export', () {
    testWidgets('it offers all three formats and polls to a link',
        (tester) async {
      final server = FakeDataOpsServer();
      await pump(tester, const ReportExportScreen(),
          locale: AppLocale.en, server: server,);

      for (final format in ['csv', 'xlsx', 'pdf']) {
        expect(
          find.byKey(ValueKey('format-$format')),
          findsOneWidget,
          reason: '$format is missing',
        );
      }

      await tester.tap(find.byKey(const ValueKey('format-pdf')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('file-locale-en')));
      await tester.pump();

      await tester.tap(find.text(tr(AppLocale.en, 'data.report.export')));
      await flush(tester, rounds: 2);

      expect(find.text(tr(AppLocale.en, 'data.status.pending')), findsWidgets);

      server.reportStatus = 'ready';
      server.reportRows = 128;
      server.reportSize = 32 * 1024;
      await flush(tester);

      expect(find.text(tr(AppLocale.en, 'data.status.ready')), findsWidgets);
      expect(
        find.textContaining(FakeDataOpsServer.reportDownloadUrl),
        findsOneWidget,
      );
      expect(
        find.text(
          tr(
            AppLocale.en,
            'data.report.rows',
            args: {'count': '\u{2068}128\u{2069}'},
          ),
        ),
        findsOneWidget,
      );

      final body = server.requests
          .lastWhere((request) => request.path.endsWith('/export'))
          .data! as Map;
      expect(body['format'], 'pdf');
      expect(body['locale'], 'en');

      await _unmount(tester);
    });
  });

  group('every screen builds in both writing directions', () {
    for (final locale in [AppLocale.fa, AppLocale.en]) {
      for (final entry in <String, Widget>{
        'export': const DataExportScreen(),
        'deletion': const AccountDeletionScreen(),
        'report': const ReportExportScreen(reportLabelKey: 'reports.cashFlow'),
      }.entries) {
        testWidgets('${entry.key} in ${locale.code}', (tester) async {
          await pump(
            tester,
            entry.value,
            locale: locale,
            server: FakeDataOpsServer()
              ..history = [FakeDataOpsServer.pastExport()],
            scheduled: ScheduledDeletion(
              purgeAfter: DateTime.parse(FakeDataOpsServer.purgeAfter),
              graceDays: 30,
            ),
          );

          expect(tester.takeException(), isNull);
          expect(
            Directionality.of(tester.element(find.byType(Scaffold).first)),
            locale.textDirection,
          );

          await _unmount(tester);
        });
      }
    }
  });
}

/// Alternates real time — which is the only thing Dio's timers respond to —
/// with pumped frames, which is the only thing the poll delay responds to.
Future<void> flush(WidgetTester tester, {int rounds = 6}) async {
  for (var i = 0; i < rounds; i++) {
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 8)),
    );
    await tester.pump(const Duration(milliseconds: 30));
  }
}

/// Takes the tree down so the poll timer stops with it.
Future<void> _unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump(const Duration(seconds: 1));
}

/// Produced by the same formatter the screens use, rather than by a second copy
/// of the calendar rules.
String _shortDate(String iso, AppLocale locale) =>
    DateFormatter.short(DateTime.parse(iso).toLocal(), locale);

String _flutterRoot() {
  final fromEnv = Platform.environment['FLUTTER_ROOT'];
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;

  var dir = File(Platform.resolvedExecutable).parent;
  while (dir.path != dir.parent.path) {
    if (Directory('${dir.path}/bin/cache/artifacts/material_fonts')
        .existsSync()) {
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
