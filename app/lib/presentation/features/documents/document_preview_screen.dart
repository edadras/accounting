import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../data/documents_repository.dart';
import '../more/module_scaffold.dart';
import 'document_preview.dart';
import 'documents_providers.dart';

/// One stored file, previewed (docs/04-roadmap.md M4).
///
/// The screen only ever fetches bytes for an image, because an image is the
/// only thing it could do anything with. For a PDF, a recording or a clip it
/// goes straight to the placeholder — downloading twenty megabytes of video in
/// order to describe it would be worse than useless.
class DocumentPreviewScreen extends ConsumerStatefulWidget {
  const DocumentPreviewScreen({super.key, required this.document});

  final DocumentFile document;

  static Route<void> route(DocumentFile document) => MaterialPageRoute<void>(
        builder: (_) => DocumentPreviewScreen(document: document),
      );

  @override
  ConsumerState<DocumentPreviewScreen> createState() =>
      _DocumentPreviewScreenState();
}

class _DocumentPreviewScreenState extends ConsumerState<DocumentPreviewScreen> {
  Uint8List? _bytes;
  DocumentException? _failure;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    if (!widget.document.media.isRenderable) return;

    final repository = ref.read(documentsRepositoryProvider);
    if (repository == null) {
      setState(
        () => _failure = const DocumentException(DocumentException.noBackend),
      );
      return;
    }

    setState(() => _loading = true);

    try {
      final bytes = await repository.content(widget.document.id);
      if (!mounted) return;
      setState(() {
        _bytes = bytes;
        _loading = false;
      });
    } on DocumentException catch (error) {
      if (!mounted) return;
      setState(() {
        _failure = error;
        _loading = false;
      });
    }
  }

  Future<void> _copyLink(String url, String confirmation) async {
    await Clipboard.setData(ClipboardData(text: url));
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(confirmation)));
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final repository = ref.watch(documentsRepositoryProvider);
    final url = repository?.contentUrl(widget.document.id);

    return ModulePage(
      title: t('documents.preview'),
      subtitle: widget.document.originalName,
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 32),
        children: [
          DocumentPreview(
            document: widget.document,
            t: t,
            localeCode: locale.code,
            externalUrl: url,
            bytes: _bytes,
            failure: _failure,
            loading: _loading,
            onCopyLink: () {
              if (url == null) return;
              unawaited(_copyLink(url, t('documents.linkCopied')));
            },
          ),
          if (widget.document.hasText) ...[
            const SizedBox(height: 14),
            ExtractedTextCard(document: widget.document, t: t),
          ],
        ],
      ),
    );
  }
}
