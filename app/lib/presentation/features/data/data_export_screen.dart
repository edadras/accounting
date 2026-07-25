import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/data_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'data_format.dart';
import 'data_providers.dart';
import 'deletion_banner.dart';

/// The right to take your data out (docs/07-security.md §8), as a screen.
///
/// Someone who asks for "my data" is owed a straight answer about what they are
/// getting, so the contents of the archive are stated before the button that
/// builds it, not in a help page nobody opens.
class DataExportScreen extends ConsumerStatefulWidget {
  const DataExportScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const DataExportScreen(),
      );

  @override
  ConsumerState<DataExportScreen> createState() => _DataExportScreenState();
}

class _DataExportScreenState extends ConsumerState<DataExportScreen> {
  StreamSubscription<WorkspaceExport>? _watch;

  List<WorkspaceExport> _past = const [];
  WorkspaceExport? _current;
  DataOpsException? _failure;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  @override
  void dispose() {
    unawaited(_watch?.cancel());
    super.dispose();
  }

  Future<void> _load() async {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    try {
      final exports = await repository.exports();
      if (!mounted) return;

      // An export outlives the screen that asked for it, so an unfinished one
      // is picked back up rather than left to finish unwatched.
      WorkspaceExport? live;
      for (final export in exports) {
        if (!export.status.isTerminal) {
          live = export;
          break;
        }
      }

      setState(() {
        _past = exports;
        _current = live ?? _current;
      });

      if (live != null) _startWatching(live.id);
    } on DataOpsException catch (error) {
      if (!mounted) return;
      setState(() => _failure = error);
    }
  }

  Future<void> _request() async {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    setState(() {
      _busy = true;
      _failure = null;
    });

    try {
      final export = await repository.requestExport();
      if (!mounted) return;
      setState(() {
        _current = export;
        _busy = false;
      });
      _startWatching(export.id);
    } on DataOpsException catch (error) {
      if (!mounted) return;
      setState(() {
        _failure = error;
        _busy = false;
      });
    }
  }

  void _startWatching(String id) {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    unawaited(_watch?.cancel());
    _watch = repository
        .watchExport(id, interval: ref.read(dataPollIntervalProvider))
        .listen(
      (export) {
        if (!mounted) return;
        setState(() => _current = export);
        if (export.status.isTerminal) unawaited(_refreshPast());
      },
      onError: (Object error) {
        if (!mounted) return;
        setState(() => _failure = DataOpsException.from(error));
      },
      cancelOnError: true,
    );
  }

  Future<void> _refreshPast() async {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    try {
      final exports = await repository.exports();
      if (!mounted) return;
      setState(() => _past = exports);
    } on DataOpsException {
      // The list is a nicety; the export the user is watching is already on
      // screen, so a failed refresh is not worth an error card.
    }
  }

  Future<void> _copy(String url) async {
    final t = ref.read(translatorProvider);
    await Clipboard.setData(ClipboardData(text: url));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(t('data.export.copied'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final repository = ref.watch(dataRepositoryProvider);
    final current = _current;
    final failure = _failure;

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('data.export.title'))),
        body: SafeArea(
          top: false,
          child: ListView(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
            children: [
              const ScheduledDeletionBanner(),
              if (repository == null)
                NeonCard(
                  accent: NeonPalette.amber,
                  child: Text(
                    t('data.unavailable'),
                    style: const TextStyle(
                      fontSize: 13,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
                )
              else ...[
                const _ArchiveContents(),
                const SizedBox(height: 16),
                NeonButton(
                  label: t(
                    current == null
                        ? 'data.export.request'
                        : 'data.export.requestAgain',
                  ),
                  icon: Icons.archive_outlined,
                  expand: true,
                  busy: _busy,
                  onPressed: _request,
                ),
                if (failure != null) ...[
                  const SizedBox(height: 16),
                  NeonCardShell(
                    accent: NeonPalette.magenta,
                    glow: true,
                    child: Text(
                      t(failure.translationKey),
                      style: const TextStyle(
                        fontSize: 13,
                        color: NeonPalette.magenta,
                      ),
                    ),
                  ),
                ],
                if (current != null) ...[
                  const SizedBox(height: 16),
                  ExportCard(
                    export: current,
                    detailed: true,
                    onCopy: _copy,
                  ),
                ],
                const SizedBox(height: 26),
                SectionHeader(
                  title: t('data.export.past'),
                  accent: NeonPalette.cyan,
                ),
                if (_past.isEmpty)
                  Text(
                    t('data.export.empty'),
                    style: const TextStyle(
                      fontSize: 13,
                      color: NeonPalette.textMuted,
                    ),
                  )
                else
                  for (final export in _past)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(bottom: 10),
                      child: ExportCard(
                        key: ValueKey('export-${export.id}'),
                        export: export,
                        detailed: false,
                        onCopy: _copy,
                      ),
                    ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// What is actually in the ZIP, in the order the server writes it.
class _ArchiveContents extends ConsumerWidget {
  const _ArchiveContents();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t('data.export.intro'),
            style: const TextStyle(
              fontSize: 13.5,
              height: 1.6,
              color: NeonPalette.textPrimary,
            ),
          ),
          const SizedBox(height: 14),
          for (final key in const [
            'data.export.contents',
            'data.export.contentsMoney',
            'data.export.contentsAudit',
          ])
            Padding(
              padding: const EdgeInsetsDirectional.only(bottom: 8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Padding(
                    padding: EdgeInsetsDirectional.only(top: 3),
                    child: Icon(
                      Icons.check_rounded,
                      size: 14,
                      color: NeonPalette.cyan,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      t(key),
                      style: const TextStyle(
                        fontSize: 12.5,
                        height: 1.55,
                        color: NeonPalette.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// One export, as a row in the history or as the headline of the screen.
class ExportCard extends ConsumerWidget {
  const ExportCard({
    super.key,
    required this.export,
    required this.detailed,
    required this.onCopy,
  });

  final WorkspaceExport export;
  final bool detailed;
  final Future<void> Function(String url) onCopy;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final url = export.downloadUrl;
    final size = export.sizeBytes;
    final expiresAt = export.expiresAt;

    final message = switch (export.status) {
      JobStatus.ready => 'data.export.ready',
      JobStatus.failed => 'data.export.failed',
      _ => 'data.export.building',
    };

    return NeonCardShell(
      accent: statusAccent(export.status),
      // A finished export is the one thing on this screen worth lighting: it
      // either has something for you or it does not.
      glow: url != null || export.status == JobStatus.failed,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              JobStatusChip(
                status: export.status,
                label: statusLabel(t, export.status),
              ),
              const Spacer(),
              if (export.createdAt != null)
                Text(
                  dateLabel(export.createdAt!, locale),
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
            ],
          ),
          if (detailed) ...[
            const SizedBox(height: 12),
            Text(
              t(message),
              style: const TextStyle(
                fontSize: 13,
                color: NeonPalette.textPrimary,
              ),
            ),
          ],
          if (size != null || expiresAt != null) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                if (size != null)
                  Text(
                    sizeLabel(t, size, locale.code),
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
                if (size != null && expiresAt != null) const SizedBox(width: 12),
                if (expiresAt != null)
                  Expanded(
                    child: Text(
                      export.isExpired
                          ? t('data.export.expired')
                          : t(
                              'data.export.expires',
                              args: {'date': dateLabel(expiresAt, locale)},
                            ),
                      style: TextStyle(
                        fontSize: 12,
                        color: export.isExpired
                            ? NeonPalette.textMuted
                            : NeonPalette.textSecondary,
                      ),
                    ),
                  ),
              ],
            ),
          ],
          if (url != null) ...[
            const SizedBox(height: 14),
            CopyableLink(
              label: t('data.export.link'),
              url: url,
              copyLabel: t('data.export.copy'),
              onCopy: () => onCopy(url),
            ),
            if (detailed)
              Text(
                t('data.export.linkHint'),
                style: const TextStyle(
                  fontSize: 11.5,
                  height: 1.5,
                  color: NeonPalette.textMuted,
                ),
              ),
          ],
        ],
      ),
    );
  }
}
