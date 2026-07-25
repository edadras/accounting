import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/documents_repository.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'document_preview.dart';
import 'document_preview_screen.dart';
import 'documents_providers.dart';

/// The workspace's files, as a way into the preview.
///
/// A document is normally reached from the record it hangs off, and this screen
/// does not replace that: it is the flat list, filtered the way `GET documents`
/// filters, so a file that is attached to nothing is still findable.
class DocumentsScreen extends ConsumerStatefulWidget {
  const DocumentsScreen({super.key, this.attachedType, this.attachedId});

  /// Narrows the list to one record's attachments, using the aliases
  /// `AttachableTypes` accepts (`transaction`, `invoice`, `asset`, …).
  final String? attachedType;
  final String? attachedId;

  static Route<void> route({String? attachedType, String? attachedId}) =>
      MaterialPageRoute<void>(
        builder: (_) => DocumentsScreen(
          attachedType: attachedType,
          attachedId: attachedId,
        ),
      );

  @override
  ConsumerState<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends ConsumerState<DocumentsScreen> {
  List<DocumentFile>? _documents;
  DocumentException? _failure;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    final repository = ref.read(documentsRepositoryProvider);
    if (repository == null) {
      setState(
        () => _failure = const DocumentException(DocumentException.noBackend),
      );
      return;
    }

    try {
      final documents = await repository.documents(
        attachedType: widget.attachedType,
        attachedId: widget.attachedId,
      );
      if (!mounted) return;
      setState(() => _documents = documents);
    } on DocumentException catch (error) {
      if (!mounted) return;
      setState(() => _failure = error);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final documents = _documents;

    return ModulePage(
      title: t('documents.title'),
      subtitle: t('documents.hint'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          if (_failure != null)
            _Notice(
              text: t(_failure!.translationKey),
              accent: NeonPalette.magenta,
            )
          else if (documents == null)
            // Static, not a spinner: nothing here animates while it waits.
            _Notice(text: t('documents.loading'), accent: NeonPalette.textMuted)
          else if (documents.isEmpty)
            _Notice(text: t('documents.empty'), accent: NeonPalette.textMuted)
          else
            for (final document in documents) ...[
              DocumentRow(
                document: document,
                t: t,
                localeCode: locale.code,
                onTap: () => Navigator.of(context)
                    .push(DocumentPreviewScreen.route(document)),
              ),
              const SizedBox(height: 10),
            ],
        ],
      ),
    );
  }
}

/// One file in the list. Public so a test can count them.
class DocumentRow extends StatelessWidget {
  const DocumentRow({
    super.key,
    required this.document,
    required this.t,
    required this.localeCode,
    required this.onTap,
  });

  final DocumentFile document;
  final Translator t;
  final String localeCode;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = documentMediaAccent(document.media);

    return NeonCardShell(
      accent: accent,
      onTap: onTap,
      child: Row(
        children: [
          Icon(documentMediaIcon(document.media), size: 22, color: accent),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '\u{2068}${document.originalName}\u{2069}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${documentMediaLabel(t, document.media)} · '
                  '${documentSizeLabel(t, document.size, localeCode)}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          NeonChip(
            label: document.media.isRenderable
                ? t('documents.previewable')
                : t('documents.externalOnly'),
            accent: accent,
            selected: document.media.isRenderable,
          ),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text, required this.accent});

  final String text;
  final Color accent;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.only(top: 40),
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 13, height: 1.6, color: accent),
        ),
      );
}
