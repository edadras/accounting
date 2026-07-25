import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/documents_repository.dart';
import '../../widgets/neon_card.dart';
import '../more/module_scaffold.dart';

/// Colour carries one fact on these screens: whether the app can show you the
/// file or only describe it.
///
/// Lime — it is on screen. Amber — it exists and needs something else to open
/// it. Magenta — something went wrong. Nothing here glows: a file that opened
/// correctly is the expected case, and the expected case does not shout.
Color documentMediaAccent(DocumentMedia media) =>
    media.isRenderable ? NeonPalette.lime : NeonPalette.amber;

IconData documentMediaIcon(DocumentMedia media) => switch (media) {
      DocumentMedia.image => Icons.image_rounded,
      DocumentMedia.pdf => Icons.picture_as_pdf_rounded,
      DocumentMedia.audio => Icons.graphic_eq_rounded,
      DocumentMedia.video => Icons.movie_rounded,
      DocumentMedia.document => Icons.description_rounded,
      DocumentMedia.archive => Icons.folder_zip_rounded,
      DocumentMedia.other => Icons.insert_drive_file_rounded,
    };

String documentMediaLabel(Translator t, DocumentMedia media) =>
    t('documents.media.${media.name}');

/// Byte counts in whole units, through the shared `data.size.*` wording.
///
/// Integer arithmetic throughout: a size is a count of things, and this app
/// does not carry counted quantities in a double.
String documentSizeLabel(Translator t, int bytes, String localeCode) {
  const kb = 1024;
  const mb = kb * 1024;
  const gb = mb * 1024;

  final (String key, int value) = switch (bytes) {
    >= gb => ('data.size.gb', (bytes + gb ~/ 2) ~/ gb),
    >= mb => ('data.size.mb', (bytes + mb ~/ 2) ~/ mb),
    >= kb => ('data.size.kb', (bytes + kb ~/ 2) ~/ kb),
    _ => ('data.size.bytes', bytes),
  };

  return t(key, args: {'size': DateFormatter.number(value, localeCode)});
}

/// A document, shown as honestly as this build can show it.
///
/// Exactly one media type is genuinely previewed: an image, decoded by the
/// engine from [bytes]. PDF, audio and video are not, and cannot be without a
/// renderer, a decoder or a player — none of which ship with Flutter. For those
/// the card states the type, the name and the size and offers the address to
/// open the file elsewhere. That is the whole design: a placeholder that tells
/// the truth, never a play button that does nothing.
class DocumentPreview extends StatelessWidget {
  const DocumentPreview({
    super.key,
    required this.document,
    required this.t,
    required this.localeCode,
    required this.externalUrl,
    required this.onCopyLink,
    this.bytes,
    this.failure,
    this.loading = false,
  });

  final DocumentFile document;
  final Translator t;
  final String localeCode;

  /// Where the blob would be fetched from, shown so the file can be opened
  /// outside the app. Null when this build has no server to address.
  final String? externalUrl;
  final VoidCallback onCopyLink;

  /// Decoded and drawn when the document is an image; ignored otherwise,
  /// because bytes of video are not something this app can do anything with.
  final Uint8List? bytes;

  final DocumentException? failure;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    final media = document.media;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (media.isRenderable)
          _ImagePreview(
            document: document,
            bytes: bytes,
            failure: failure,
            loading: loading,
            t: t,
          )
        else
          UnsupportedMediaCard(document: document, t: t),
        const SizedBox(height: 14),
        DocumentFacts(document: document, t: t, localeCode: localeCode),
        const SizedBox(height: 14),
        ExternalOpenCard(
          t: t,
          url: externalUrl,
          onCopy: onCopyLink,
          accent: documentMediaAccent(media),
        ),
      ],
    );
  }
}

/// The one real preview in the app.
class _ImagePreview extends StatelessWidget {
  const _ImagePreview({
    required this.document,
    required this.bytes,
    required this.failure,
    required this.loading,
    required this.t,
  });

  final DocumentFile document;
  final Uint8List? bytes;
  final DocumentException? failure;
  final bool loading;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final data = bytes;

    if (failure != null) {
      return _PreviewFrame(
        accent: NeonPalette.magenta,
        child: _Message(
          icon: Icons.broken_image_rounded,
          accent: NeonPalette.magenta,
          title: t('documents.imageFailed'),
          body: t(failure!.translationKey),
        ),
      );
    }

    if (loading || data == null) {
      // A static placeholder rather than a spinner: an indeterminate animation
      // says "nearly there" about a wait whose length nobody knows, and it
      // keeps a frame scheduled forever.
      return _PreviewFrame(
        accent: NeonPalette.textMuted,
        child: _Message(
          icon: Icons.image_rounded,
          accent: NeonPalette.textMuted,
          title: t('documents.imageLoading'),
        ),
      );
    }

    return _PreviewFrame(
      accent: NeonPalette.lime,
      padding: EdgeInsets.zero,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
        child: Image.memory(
          data,
          key: const ValueKey('document-image'),
          fit: BoxFit.contain,
          semanticLabel: document.originalName,
          errorBuilder: (context, error, stack) => _Message(
            icon: Icons.broken_image_rounded,
            accent: NeonPalette.magenta,
            title: t('documents.imageFailed'),
            body: t('documents.imageCorrupt'),
          ),
        ),
      ),
    );
  }
}

/// PDF, audio, video and everything else.
///
/// Public so a test can prove that a video document gets a description and not
/// a transport bar.
class UnsupportedMediaCard extends StatelessWidget {
  const UnsupportedMediaCard({
    super.key,
    required this.document,
    required this.t,
  });

  final DocumentFile document;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final media = document.media;

    return _PreviewFrame(
      accent: NeonPalette.amber,
      child: _Message(
        icon: documentMediaIcon(media),
        accent: NeonPalette.amber,
        title: t('documents.noInAppViewer'),
        body: t('documents.noInAppViewerHint', args: {
          'type': documentMediaLabel(t, media),
        },),
      ),
    );
  }
}

class _PreviewFrame extends StatelessWidget {
  const _PreviewFrame({
    required this.accent,
    required this.child,
    this.padding = const EdgeInsetsDirectional.all(20),
  });

  final Color accent;
  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      width: double.infinity,
      constraints: const BoxConstraints(minHeight: 180),
      padding: padding,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: accent.withValues(alpha: isDark ? 0.06 : 0.05),
        borderRadius: BorderRadius.circular(NeonEffects.radiusMd),
        border: Border.all(color: accent.withValues(alpha: 0.28)),
      ),
      child: child,
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({
    required this.icon,
    required this.accent,
    required this.title,
    this.body,
  });

  final IconData icon;
  final Color accent;
  final String title;
  final String? body;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 34, color: accent),
        const SizedBox(height: 12),
        Text(
          title,
          textAlign: TextAlign.center,
          style: TextStyle(
            fontSize: 13.5,
            fontWeight: FontWeight.w700,
            color:
                isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
          ),
        ),
        if (body != null) ...[
          const SizedBox(height: 8),
          Text(
            body!,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 12,
              height: 1.6,
              color: NeonPalette.textMuted,
            ),
          ),
        ],
      ],
    );
  }
}

/// Name, type and size — the three things a placeholder owes the reader.
class DocumentFacts extends StatelessWidget {
  const DocumentFacts({
    super.key,
    required this.document,
    required this.t,
    required this.localeCode,
  });

  final DocumentFile document;
  final Translator t;
  final String localeCode;

  @override
  Widget build(BuildContext context) {
    return NeonCard(
      accent: documentMediaAccent(document.media),
      padding: const EdgeInsetsDirectional.symmetric(
        horizontal: 16,
        vertical: 8,
      ),
      child: Column(
        children: [
          DetailRow(
            label: t('documents.fileName'),
            // A file name is usually Latin and often carries an extension; the
            // isolate keeps it from being reordered inside a Persian line.
            value: '\u{2068}${document.originalName}\u{2069}',
          ),
          DetailRow(
            label: t('documents.fileType'),
            value: documentMediaLabel(t, document.media),
            accent: documentMediaAccent(document.media),
          ),
          DetailRow(
            label: t('documents.fileSize'),
            value: documentSizeLabel(t, document.size, localeCode),
          ),
        ],
      ),
    );
  }
}

/// The text the OCR pass pulled out of the file.
///
/// For a scanned receipt or a text-layer PDF this is the nearest thing to a
/// preview the app can honestly give of a document it cannot render: not the
/// page, but what the page says. It is only shown when the server actually
/// extracted something.
class ExtractedTextCard extends StatelessWidget {
  const ExtractedTextCard({
    super.key,
    required this.document,
    required this.t,
  });

  final DocumentFile document;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCard(
      accent: NeonPalette.cyan,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t('documents.extractedText'),
            style: TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color:
                  isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            document.ocrText!.trim(),
            style: TextStyle(
              fontSize: 12.5,
              height: 1.7,
              color: isDark
                  ? NeonPalette.textSecondary
                  : NeonPalette.lightTextSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

/// The "open it somewhere else" affordance.
///
/// It copies an address rather than launching one. This build has no URL
/// launcher and no share sheet, so a button labelled "open" would be a button
/// that does nothing — the address itself is the deliverable.
class ExternalOpenCard extends StatelessWidget {
  const ExternalOpenCard({
    super.key,
    required this.t,
    required this.url,
    required this.onCopy,
    this.accent = NeonPalette.amber,
  });

  final Translator t;
  final String? url;
  final VoidCallback onCopy;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final address = url;

    return NeonCard(
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t('documents.openExternally'),
            style: TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color:
                  isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            t('documents.openExternallyHint'),
            style: const TextStyle(
              fontSize: 11.5,
              height: 1.6,
              color: NeonPalette.textMuted,
            ),
          ),
          const SizedBox(height: 12),
          if (address == null)
            Text(
              t('documents.noAddress'),
              style: const TextStyle(fontSize: 12, color: NeonPalette.textMuted),
            )
          else ...[
            Container(
              width: double.infinity,
              padding: const EdgeInsetsDirectional.all(10),
              decoration: BoxDecoration(
                color: accent.withValues(alpha: isDark ? 0.08 : 0.06),
                borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
              ),
              child: Text(
                '\u{2066}$address\u{2069}',
                style: TextStyle(
                  fontSize: 12,
                  color: isDark
                      ? NeonPalette.textSecondary
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                key: const ValueKey('document-copy-link'),
                onPressed: onCopy,
                icon: Icon(Icons.copy_rounded, size: 16, color: accent),
                label: Text(
                  t('documents.copyLink'),
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: accent,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
