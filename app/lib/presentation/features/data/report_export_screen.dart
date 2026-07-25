import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/data_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'data_format.dart';
import 'data_providers.dart';

/// Turns the report you are looking at into a file you can send someone.
///
/// The format and the language of the file are asked for separately on
/// purpose: a Persian user very often needs an English-labelled spreadsheet for
/// an accountant, and the server supports exactly that.
class ReportExportScreen extends ConsumerStatefulWidget {
  const ReportExportScreen({
    super.key,
    this.reportType = 'cash-flow',
    this.reportLabelKey,
  });

  /// One of `Modules\Reports\Support\ReportCatalog::TYPES`.
  final String reportType;

  /// Translation key naming the report, supplied by whoever opens this screen
  /// so no report name is spelled out here.
  final String? reportLabelKey;

  static Route<void> route({
    String reportType = 'cash-flow',
    String? reportLabelKey,
  }) =>
      MaterialPageRoute<void>(
        builder: (_) => ReportExportScreen(
          reportType: reportType,
          reportLabelKey: reportLabelKey,
        ),
      );

  @override
  ConsumerState<ReportExportScreen> createState() => _ReportExportScreenState();
}

class _ReportExportScreenState extends ConsumerState<ReportExportScreen> {
  StreamSubscription<ReportExport>? _watch;

  ReportFormat _format = ReportFormat.csv;
  AppLocale? _fileLocale;
  ReportExport? _export;
  DataOpsException? _failure;
  bool _busy = false;

  @override
  void dispose() {
    unawaited(_watch?.cancel());
    super.dispose();
  }

  Future<void> _requestExport() async {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    final AppLocale locale = _fileLocale ?? ref.read(localeProvider);

    setState(() {
      _busy = true;
      _failure = null;
      _export = null;
    });

    try {
      final requested = await repository.requestReportExport(
        report: widget.reportType,
        format: _format,
        locale: locale.code,
      );
      if (!mounted) return;
      setState(() {
        _export = requested;
        _busy = false;
      });
      _startWatching(requested.id);
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
        .watchReportExport(id, interval: ref.read(dataPollIntervalProvider))
        .listen(
      (export) {
        if (!mounted) return;
        setState(() => _export = export);
      },
      onError: (Object error) {
        if (!mounted) return;
        setState(() => _failure = DataOpsException.from(error));
      },
      cancelOnError: true,
    );
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
    final locale = ref.watch(localeProvider);
    final repository = ref.watch(dataRepositoryProvider);
    final export = _export;
    final failure = _failure;
    final labelKey = widget.reportLabelKey;

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('data.report.title'))),
        body: SafeArea(
          top: false,
          child: ListView(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
            children: [
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
                if (labelKey != null) ...[
                  Text(
                    t(labelKey),
                    style: const TextStyle(
                      fontSize: 17,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                SectionHeader(title: t('data.report.format')),
                NeonCard(
                  child: Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final format in ReportFormat.values)
                        NeonChip(
                          key: ValueKey('format-${format.wire}'),
                          label: t('data.format.${format.wire}'),
                          selected: format == _format,
                          onTap: () => setState(() => _format = format),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 22),
                SectionHeader(
                  title: t('data.report.locale'),
                  accent: NeonPalette.violet,
                ),
                NeonCard(
                  accent: NeonPalette.violet,
                  child: Wrap(
                    spacing: 10,
                    runSpacing: 10,
                    children: [
                      for (final option in AppLocale.supported)
                        NeonChip(
                          key: ValueKey('file-locale-${option.code}'),
                          // Each language in its own script, as in settings.
                          label: option.nativeName,
                          accent: NeonPalette.violet,
                          selected: option.code ==
                              (_fileLocale ?? locale).code,
                          onTap: () => setState(() => _fileLocale = option),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 22),
                NeonButton(
                  label: t('data.report.export'),
                  icon: Icons.download_rounded,
                  expand: true,
                  busy: _busy,
                  onPressed: _requestExport,
                ),
                const SizedBox(height: 10),
                Text(
                  t('data.report.hint'),
                  style: const TextStyle(
                    fontSize: 12,
                    height: 1.5,
                    color: NeonPalette.textMuted,
                  ),
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
                if (export != null) ...[
                  const SizedBox(height: 16),
                  _ReportExportCard(export: export, onCopy: _copy),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _ReportExportCard extends ConsumerWidget {
  const _ReportExportCard({required this.export, required this.onCopy});

  final ReportExport export;
  final Future<void> Function(String url) onCopy;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final localeCode = ref.watch(localeProvider).code;
    final url = export.downloadUrl;
    final rows = export.rowCount;
    final size = export.sizeBytes;

    return NeonCardShell(
      accent: statusAccent(export.status),
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
              Text(
                t('data.format.${export.format.wire}'),
                style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                  color: NeonPalette.textMuted,
                ),
              ),
            ],
          ),
          if (rows != null || size != null) ...[
            const SizedBox(height: 10),
            Row(
              children: [
                if (rows != null)
                  Text(
                    t(
                      'data.report.rows',
                      args: {'count': DateFormatter.number(rows, localeCode)},
                    ),
                    style: const TextStyle(
                      fontSize: 12,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
                if (rows != null && size != null) const SizedBox(width: 12),
                if (size != null)
                  Text(
                    sizeLabel(t, size, localeCode),
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
              ],
            ),
          ],
          if (export.status == JobStatus.failed) ...[
            const SizedBox(height: 10),
            Text(
              t('data.export.failed'),
              style: const TextStyle(
                fontSize: 13,
                color: NeonPalette.magenta,
              ),
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
