@Tags(['reachability'])
library;

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Every screen must be opened by something.
///
/// This project shipped twelve finished, individually-tested screens that no
/// line of the app ever opened — a sign-in flow, conflict resolution, document
/// preview, report export. Each one passed its own suite the entire time,
/// because a screen's tests construct it directly and never ask whether a user
/// could arrive at it.
///
/// So this is a source-level check rather than a widget test: it is the only
/// kind that can see the absence of a caller.
void main() {
  test('no screen is orphaned', () {
    final sources = <String, String>{};
    for (final entity in Directory('lib').listSync(recursive: true)) {
      if (entity is File && entity.path.endsWith('.dart')) {
        sources[entity.path] = entity.readAsStringSync();
      }
    }

    // Widgets that are a destination: a screen, or a sheet raised over one.
    final declaredIn = <String, String>{};
    final declaration = RegExp(r'class (\w+(?:Screen|Sheet)) extends');
    sources.forEach((path, source) {
      for (final match in declaration.allMatches(source)) {
        declaredIn[match.group(1)!] = path;
      }
    });

    expect(
      declaredIn,
      isNotEmpty,
      reason: 'the declaration pattern matched nothing — the check is broken, '
          'not the app',
    );

    final orphans = <String, String>{};
    declaredIn.forEach((name, ownFile) {
      // `.route()`, `.new` as a tear-off, `.show()`/`.open()` for a sheet, or a
      // plain constructor call. Its own file does not count.
      final used = RegExp('\\b$name(\\.route|\\.new|\\.show|\\.open|\\()');
      final hasCaller = sources.entries.any(
        (entry) => entry.key != ownFile && used.hasMatch(entry.value),
      );
      if (!hasCaller) orphans[name] = ownFile;
    });

    expect(
      orphans,
      isEmpty,
      reason: 'These are built and tested but nothing opens them, so they do '
          'not exist as far as a user is concerned. Wire each one up, or '
          'delete it:\n${orphans.entries.map((e) => '  ${e.key}  ${e.value}').join('\n')}',
    );
  });
}
