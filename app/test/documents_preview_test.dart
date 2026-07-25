import 'dart:convert';
import 'dart:ui' as ui;

import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/data/documents_repository.dart';
import 'package:finora/presentation/features/documents/document_preview.dart';
import 'package:finora/presentation/features/documents/document_preview_screen.dart';
import 'package:finora/presentation/features/documents/documents_providers.dart';
import 'package:finora/presentation/features/documents/documents_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/membership_harness.dart';

/// The document preview, over the real shape of `DocumentResource`.
///
/// Two rules keep this file from hanging instead of failing:
///
/// **A test that awaits Dio itself does it inside `tester.runAsync`.**
/// `testWidgets` drives a fake clock that never advances Dio's real timers, so
/// such an await would never return and no timeout could reach it. Driving the
/// widget tree needs no such thing: [MockAdapter] answers from a function, so
/// the response lands on a microtask that an ordinary [pumpFrames] flushes —
/// and `pump` cannot be called from inside `runAsync` anyway.
///
/// **Nothing calls `pumpAndSettle`.** Frames are pumped explicitly.
void main() {
  setUpAll(loadTestFonts);

  /// A real 1×1 PNG. Small enough to inline, and genuinely decodable — the
  /// image preview is the one preview in the app that is not a description of
  /// a file, so the bytes behind it have to be real.
  final png = base64Decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAE'
    'hQGAhKmMIQAAAABJRU5ErkJggg==',
  );

  Map<String, Object?> doc({
    required String id,
    required String name,
    required String mime,
    required int size,
    String kind = 'other',
    String? ocrText,
  }) =>
      {
        'id': id,
        'disk': 'local',
        'original_name': name,
        'mime': mime,
        'size': size,
        'kind': kind,
        'checksum': 'abc',
        'ocr': {'status': 'done', 'text': ocrText, 'data': null},
        'uploaded_by': 1,
        'created_at': '2026-07-20T12:00:00+00:00',
      };

  final image = doc(
    id: 'doc-image',
    name: 'receipt.png',
    mime: 'image/png',
    size: 2048,
    kind: 'receipt',
  );
  final pdf = doc(
    id: 'doc-pdf',
    name: 'contract.pdf',
    mime: 'application/pdf',
    size: 1024 * 1024,
    kind: 'contract',
  );
  final audio = doc(
    id: 'doc-audio',
    name: 'note.m4a',
    mime: 'audio/mp4',
    size: 300000,
    kind: 'voice',
  );
  final video = doc(
    id: 'doc-video',
    name: 'walkthrough.mp4',
    mime: 'video/mp4',
    size: 40 * 1024 * 1024,
    kind: 'video',
  );

  /// Answers the routes the Documents module really registers, plus the
  /// download route it does not — [servesContent] says which of the two worlds
  /// the test is in.
  MockAdapter serverWith(
    List<Map<String, Object?>> documents, {
    bool servesContent = true,
  }) =>
      MockAdapter((options) {
        final path = options.path;

        if (path.contains('/download')) {
          if (!servesContent) {
            return MockAdapter.error(
              'not_found',
              status: 404,
              message: 'no such route',
            );
          }
          return ResponseBody.fromBytes(
            png,
            200,
            headers: {
              Headers.contentTypeHeader: ['image/png'],
            },
          );
        }

        final match = RegExp(r'/documents/([^/]+)$').firstMatch(path);
        if (match != null) {
          final id = match.group(1);
          return MockAdapter.json({
            'data': documents.firstWhere((d) => d['id'] == id),
          });
        }

        return MockAdapter.json({
          'data': documents,
          'meta': {'page': 1, 'per_page': 50, 'total': documents.length},
        });
      });

  Future<void> pumpScreen(
    WidgetTester tester,
    Widget screen, {
    required AppLocale locale,
    required MockAdapter adapter,
  }) =>
      pumpMembershipApp(
        tester,
        screen,
        locale: locale,
        extraOverrides: [
          documentsRepositoryProvider.overrideWithValue(
            DocumentsRepository(client: membershipClient(adapter)),
          ),
        ],
      );

  String tr(AppLocale locale, String key, {Map<String, String>? args}) =>
      Translator(locale: locale)(key, args: args);

  // ------------------------------------------------------------ media mapping

  test('every media type the server accepts maps to a preview kind', () {
    expect(DocumentMedia.forMime('image/jpeg'), DocumentMedia.image);
    expect(DocumentMedia.forMime('image/heic'), DocumentMedia.image);
    expect(DocumentMedia.forMime('application/pdf'), DocumentMedia.pdf);
    expect(DocumentMedia.forMime('audio/ogg'), DocumentMedia.audio);
    expect(DocumentMedia.forMime('video/quicktime'), DocumentMedia.video);
    expect(DocumentMedia.forMime('text/csv'), DocumentMedia.document);
    expect(
      DocumentMedia.forMime(
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      ),
      DocumentMedia.document,
    );
    expect(DocumentMedia.forMime('application/zip'), DocumentMedia.archive);
    expect(DocumentMedia.forMime(null), DocumentMedia.other);
  });

  test('an image is the only kind this build claims it can render', () {
    expect(DocumentMedia.image.isRenderable, isTrue);
    for (final media in DocumentMedia.values) {
      if (media == DocumentMedia.image) continue;
      expect(
        media.isRenderable,
        isFalse,
        reason: '$media must not claim an in-app viewer it does not have',
      );
    }
  });

  test('the inlined preview bytes really are a decodable image', () async {
    final codec = await ui.instantiateImageCodec(Uint8List.fromList(png));
    final frame = await codec.getNextFrame();

    expect(frame.image.width, 1);
    expect(frame.image.height, 1);
  });

  // ------------------------------------------------------------------- decode

  test('DocumentResource decodes into a DocumentFile', () {
    final file = DocumentFile.fromJson(
      doc(
        id: 'doc-1',
        name: 'فاکتور.pdf',
        mime: 'application/pdf',
        size: 4096,
        kind: 'invoice',
        ocrText: 'جمع کل ۱۲۳',
      ),
    );

    expect(file.id, 'doc-1');
    expect(file.originalName, 'فاکتور.pdf');
    expect(file.media, DocumentMedia.pdf);
    expect(file.size, 4096);
    expect(file.kind, 'invoice');
    expect(file.hasText, isTrue);
    expect(file.createdAt, isNotNull);
  });

  // -------------------------------------------------------------- repository

  testWidgets('the repository reads the routes the module really registers',
      (tester) async {
    final adapter = serverWith([image, pdf]);
    final repository = DocumentsRepository(client: membershipClient(adapter));

    // Awaiting Dio from the test body needs the real clock: `testWidgets` runs
    // a fake one that never fires Dio's timers, and the isolate would block
    // where no timeout could reach it.
    await tester.runAsync(() async {
      final listed = await repository.documents();
      expect(listed.map((d) => d.id), ['doc-image', 'doc-pdf']);

      final one = await repository.document('doc-pdf');
      expect(one.media, DocumentMedia.pdf);

      final bytes = await repository.content('doc-image');
      expect(bytes, png);
    });

    expect(
      adapter.requests.map((r) => r.path),
      containsAll(<String>[
        '/documents',
        '/documents/doc-pdf',
        '/documents/doc-image/download',
      ]),
    );
  });

  testWidgets('a missing download route is named, not swallowed',
      (tester) async {
    final adapter = serverWith([image], servesContent: false);
    final repository = DocumentsRepository(client: membershipClient(adapter));

    await tester.runAsync(() async {
      await expectLater(
        repository.content('doc-image'),
        throwsA(
          isA<DocumentException>().having(
            (e) => e.isContentUnavailable,
            'isContentUnavailable',
            isTrue,
          ),
        ),
      );
    });
  });

  // ------------------------------------------------------------------ listing

  testWidgets('the list separates what can be shown from what cannot',
      (tester) async {
    final adapter = serverWith([image, pdf, audio, video]);

    await pumpScreen(
      tester,
      const DocumentsScreen(),
      locale: AppLocale.fa,
      adapter: adapter,
    );
    await pumpFrames(tester);

    expect(tester.takeException(), isNull);
    expect(find.byType(DocumentRow), findsNWidgets(4));
    expect(
      find.text(tr(AppLocale.fa, 'documents.previewable')),
      findsOneWidget,
      reason: 'only the image is previewable in app',
    );
    expect(
      find.text(tr(AppLocale.fa, 'documents.externalOnly')),
      findsNWidgets(3),
    );
  });

  testWidgets('a build with no server says so instead of showing an empty list',
      (tester) async {
    await pumpMembershipApp(
      tester,
      const DocumentsScreen(),
      locale: AppLocale.en,
    );
    await pumpFrames(tester);

    expect(find.text(tr(AppLocale.en, 'error.documents_unavailable')),
        findsOneWidget,);
    expect(find.byType(DocumentRow), findsNothing);
  });

  // ------------------------------------------------------------------ preview

  testWidgets('an image is really rendered, from the bytes the server sent',
      (tester) async {
    final adapter = serverWith([image]);

    await pumpScreen(
      tester,
      DocumentPreviewScreen(document: DocumentFile.fromJson(image)),
      locale: AppLocale.fa,
      adapter: adapter,
    );
    await pumpFrames(tester);

    expect(tester.takeException(), isNull);

    final rendered = tester.widget<Image>(
      find.byKey(const ValueKey('document-image')),
    );
    expect(rendered.image, isA<MemoryImage>());
    expect((rendered.image as MemoryImage).bytes, png);

    // Nothing about an image that opened correctly is a placeholder.
    expect(find.byType(UnsupportedMediaCard), findsNothing);
  });

  for (final (label, payload, key) in [
    ('a PDF', 'pdf', 'documents.media.pdf'),
    ('an audio file', 'audio', 'documents.media.audio'),
    ('a video', 'video', 'documents.media.video'),
  ]) {
    testWidgets('$label gets a placeholder that names the type, size and file',
        (tester) async {
      final json = switch (payload) {
        'pdf' => pdf,
        'audio' => audio,
        _ => video,
      };
      final file = DocumentFile.fromJson(json);
      final adapter = serverWith([json]);

            await pumpScreen(
        tester,
        DocumentPreviewScreen(document: file),
        locale: AppLocale.en,
        adapter: adapter,
      );
      await pumpFrames(tester);

      expect(tester.takeException(), isNull);
      expect(find.byType(UnsupportedMediaCard), findsOneWidget);

      // Nothing that could be mistaken for a player was drawn.
      expect(find.byIcon(Icons.play_arrow_rounded), findsNothing);
      expect(find.byIcon(Icons.play_circle_fill_rounded), findsNothing);
      expect(find.byType(Slider), findsNothing);

      // The honest substitute: what it is, how big, and what it is called.
      expect(
        find.text(tr(AppLocale.en, 'documents.noInAppViewer')),
        findsOneWidget,
      );
      expect(
        find.text(tr(AppLocale.en, key)),
        findsWidgets,
        reason: 'the type has to be stated in words',
      );
      expect(find.textContaining(file.originalName), findsWidgets);
      expect(
        find.text(documentSizeLabel(Translator(locale: AppLocale.en),
            file.size, 'en',),),
        findsOneWidget,
      );

      // And a way to open it somewhere that can.
      expect(
        find.byKey(const ValueKey('document-copy-link')),
        findsOneWidget,
      );
    });
  }

  testWidgets(
      'the preview never fetches bytes for a file it could not draw anyway',
      (tester) async {
    final adapter = serverWith([video]);

    await pumpScreen(
      tester,
      DocumentPreviewScreen(document: DocumentFile.fromJson(video)),
      locale: AppLocale.en,
      adapter: adapter,
    );
    await pumpFrames(tester);

    expect(
      adapter.requests.where((r) => r.path.contains('/download')),
      isEmpty,
      reason: 'forty megabytes of video to describe it in one line',
    );
  });

  testWidgets('a server with no content route is reported, not faked',
      (tester) async {
    final adapter = serverWith([image], servesContent: false);

    await pumpScreen(
      tester,
      DocumentPreviewScreen(document: DocumentFile.fromJson(image)),
      locale: AppLocale.fa,
      adapter: adapter,
    );
    await pumpFrames(tester);

    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('document-image')), findsNothing);
    expect(
      find.text(tr(AppLocale.fa, 'error.document_content_unavailable')),
      findsOneWidget,
    );
    // The file's identity survives a failure to fetch it.
    expect(find.textContaining('receipt.png'), findsWidgets);
  });

  testWidgets('extracted text stands in for a page nobody can render',
      (tester) async {
    final json = doc(
      id: 'doc-ocr',
      name: 'receipt.pdf',
      mime: 'application/pdf',
      size: 900,
      kind: 'receipt',
      ocrText: 'فروشگاه هفت · جمع ۱۲۳٬۰۰۰',
    );
    final adapter = serverWith([json]);

    await pumpScreen(
      tester,
      DocumentPreviewScreen(document: DocumentFile.fromJson(json)),
      locale: AppLocale.fa,
      adapter: adapter,
    );
    await pumpFrames(tester);

    expect(find.text(tr(AppLocale.fa, 'documents.extractedText')),
        findsOneWidget,);
    expect(find.textContaining('فروشگاه هفت'), findsOneWidget);
  });

  testWidgets('the external address can be copied', (tester) async {
    final adapter = serverWith([pdf]);

    // The clipboard is a platform channel; without a handler the write would
    // wait on a real message loop the fake clock never turns.
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied = (call.arguments as Map)['text'] as String?;
        }
        return null;
      },
    );
    addTearDown(() => tester.binding.defaultBinaryMessenger
        .setMockMethodCallHandler(SystemChannels.platform, null),);

    await pumpScreen(
      tester,
      DocumentPreviewScreen(document: DocumentFile.fromJson(pdf)),
      locale: AppLocale.en,
      adapter: adapter,
    );
    await pumpFrames(tester);

    await tester.tap(find.byKey(const ValueKey('document-copy-link')));
    await pumpFrames(tester);

    expect(tester.takeException(), isNull);
    expect(copied, endsWith('/documents/doc-pdf/download'));
    expect(
      find.text(tr(AppLocale.en, 'documents.linkCopied')),
      findsOneWidget,
    );
  });

  // ---------------------------------------------------------------------- rtl

  for (final locale in [AppLocale.fa, AppLocale.en]) {
    testWidgets('the preview builds in ${locale.code}', (tester) async {
      final adapter = serverWith([pdf]);

            await pumpScreen(
        tester,
        DocumentPreviewScreen(document: DocumentFile.fromJson(pdf)),
        locale: locale,
        adapter: adapter,
      );
      await pumpFrames(tester);

      expect(tester.takeException(), isNull);
      expect(
        Directionality.of(tester.element(find.byType(Scaffold).first)),
        locale.textDirection,
      );
    });
  }
}
