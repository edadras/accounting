import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/data_repository.dart';
import '../../widgets/neon_widgets.dart';

/// Presentation rules shared by the three data screens.
///
/// Colour is semantic here and nowhere near decorative: amber while a job is
/// still owed to you, lime when it has produced something, magenta when it has
/// not. The same mapping drives whether a surface glows, so a screen full of
/// finished exports cannot end up looking like a screen with a problem.
Color statusAccent(JobStatus status) => switch (status) {
      JobStatus.pending || JobStatus.running => NeonPalette.amber,
      JobStatus.ready => NeonPalette.lime,
      JobStatus.failed => NeonPalette.magenta,
    };

String statusLabel(Translator t, JobStatus status) =>
    t('data.status.${status.name}');

/// Byte counts, in whole units.
///
/// Integer arithmetic throughout: a size is a count of things, and this app
/// does not carry counted quantities in a double.
String sizeLabel(Translator t, int bytes, String localeCode) {
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

String dateLabel(DateTime moment, AppLocale locale) =>
    DateFormatter.short(moment.toLocal(), locale);

/// The state of one job, as a pill.
///
/// Only a terminal state is lit: a queued export glowing would say "look at
/// me" about the one thing the user can do nothing with yet.
class JobStatusChip extends StatelessWidget {
  const JobStatusChip({super.key, required this.status, required this.label});

  final JobStatus status;
  final String label;

  @override
  Widget build(BuildContext context) {
    return NeonChip(
      label: label,
      accent: statusAccent(status),
      selected: status.isTerminal,
      icon: switch (status) {
        JobStatus.pending => Icons.schedule_rounded,
        JobStatus.running => Icons.autorenew_rounded,
        JobStatus.ready => Icons.check_circle_outline_rounded,
        JobStatus.failed => Icons.error_outline_rounded,
      },
    );
  }
}

/// A copyable URL: the app has no share sheet and no file picker, so the link
/// itself is the deliverable.
class CopyableLink extends StatelessWidget {
  const CopyableLink({
    super.key,
    required this.label,
    required this.url,
    required this.copyLabel,
    required this.onCopy,
    this.accent = NeonPalette.lime,
  });

  final String label;
  final String url;
  final String copyLabel;
  final VoidCallback onCopy;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(fontSize: 11, color: NeonPalette.textMuted),
        ),
        const SizedBox(height: 6),
        Container(
          width: double.infinity,
          padding: const EdgeInsetsDirectional.all(10),
          decoration: BoxDecoration(
            color: accent.withValues(alpha: isDark ? 0.08 : 0.06),
            borderRadius: BorderRadius.circular(10),
          ),
          // A URL is Latin text: without the isolate it would be reordered
          // around the Persian sentence next to it.
          child: Text(
            '\u{2066}$url\u{2069}',
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
            onPressed: onCopy,
            icon: Icon(Icons.copy_rounded, size: 16, color: accent),
            label: Text(
              copyLabel,
              style: TextStyle(
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                color: accent,
              ),
            ),
          ),
        ),
      ],
    );
  }
}
