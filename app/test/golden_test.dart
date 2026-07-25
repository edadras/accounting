@Tags(['golden'])
library;

import 'dart:io';

import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/main.dart';
import 'package:finora/presentation/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Golden images for every screen, in Persian (RTL) and English (LTR).
///
/// `docs/10-quality-and-dod.md` requires a golden per screen in both writing
/// directions — this is where an RTL regression actually becomes visible,
/// because a mirrored layout still passes every behavioural test.
///
/// Regenerate with: `flutter test --update-goldens test/golden_test.dart`
void main() {
  setUpAll(() async {
    // Without a real font the engine draws Ahem boxes and every golden becomes
    // a grid of rectangles — useless for spotting a layout problem.
    await _loadFont('Vazirmatn', [
      'assets/fonts/Vazirmatn-Regular.ttf',
      'assets/fonts/Vazirmatn-Bold.ttf',
    ]);

    // Icons are boxes without this, which hides exactly the kind of wrong-icon
    // or misaligned-icon problem a golden is supposed to catch.
    await _loadFont('MaterialIcons', [
      '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
    ]);
  });

  for (final (locale, suffix) in [(AppLocale.fa, 'fa'), (AppLocale.en, 'en')]) {
    for (final (tabIndex, screen) in const [
      (0, 'dashboard'),
      (1, 'transactions'),
      (2, 'reports'),
      (3, 'accounts'),
      (4, 'settings'),
    ]) {
      testWidgets('$screen — $suffix', (tester) async {
        tester.view.physicalSize = const Size(430, 932);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.reset);

        await tester.pumpWidget(
          ProviderScope(
            overrides: [
              localeProvider.overrideWith((ref) => locale),
              selectedTabProvider.overrideWith((ref) => tabIndex),
            ],
            child: const FinoraApp(),
          ),
        );
        await tester.pumpAndSettle();

        await expectLater(
          find.byType(MaterialApp),
          matchesGoldenFile('goldens/$screen-$suffix.png'),
        );
      });
    }
  }

  testWidgets('dashboard — light theme', (tester) async {
    tester.view.physicalSize = const Size(430, 932);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => AppLocale.fa),
          themeModeProvider.overrideWith((ref) => ThemeMode.light),
        ],
        child: const FinoraApp(),
      ),
    );
    await tester.pumpAndSettle();

    await expectLater(
      find.byType(MaterialApp),
      matchesGoldenFile('goldens/dashboard-light.png'),
    );
  });
}

/// Locates the SDK so the icon font can be loaded without hard-coding a path.
String _flutterRoot() {
  final fromEnv = Platform.environment['FLUTTER_ROOT'];
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;

  // `dart` runs from <sdk>/bin/cache/dart-sdk/bin/dart during flutter test.
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
