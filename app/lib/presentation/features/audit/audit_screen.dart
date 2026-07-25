import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/audit_repository.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'audit_format.dart';
import 'audit_providers.dart';
import 'change_diff.dart';
import 'security_log_screen.dart';

/// The workspace trail: what happened in these books, and who did it.
class AuditTrailScreen extends ConsumerWidget {
  const AuditTrailScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final trail = ref.watch(auditTrailProvider);

    return ModulePage(
      title: t('audit.title'),
      subtitle: t('audit.subtitle'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const _RangeFilter(),
          Expanded(
            child: trail.when(
              loading: () => const ModuleLoading(),
              error: (error, _) => noticeForFailure(
                error,
                t: t,
                permissionTitle: t('audit.noAccessTitle'),
                permissionBody: t('audit.noAccessBody'),
              ),
              data: (entries) => _TrailList(entries: entries),
            ),
          ),
        ],
      ),
    );
  }
}

class _RangeFilter extends ConsumerWidget {
  const _RangeFilter();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final selected = ref.watch(auditRangeProvider);

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 12),
      child: Row(
        children: [
          for (final range in AuditRange.values) ...[
            NeonChip(
              key: ValueKey('audit-range-${range.name}'),
              label: t(range.labelKey),
              selected: range == selected,
              onTap: () {
                ref.read(auditRangeProvider.notifier).state = range;
                ref.read(auditActionProvider.notifier).state = null;
              },
            ),
            const SizedBox(width: 8),
          ],
        ],
      ),
    );
  }
}

class _TrailList extends ConsumerWidget {
  const _TrailList({required this.entries});

  final List<AuditEntry> entries;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final action = ref.watch(auditActionProvider);

    final actions = <String>{for (final entry in entries) entry.action}.toList()
      ..sort();

    final visible = action == null
        ? entries
        : [for (final entry in entries) if (entry.action == action) entry];

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 40),
      children: [
        if (actions.length > 1) ...[
          SectionHeader(title: t('audit.filterAction')),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              NeonChip(
                key: const ValueKey('audit-action-all'),
                label: t('audit.filterAll'),
                selected: action == null,
                onTap: () =>
                    ref.read(auditActionProvider.notifier).state = null,
              ),
              for (final candidate in actions)
                NeonChip(
                  key: ValueKey('audit-action-$candidate'),
                  label: AuditFormat.action(t, candidate),
                  selected: candidate == action,
                  onTap: () => ref.read(auditActionProvider.notifier).state =
                      candidate,
                ),
            ],
          ),
          const SizedBox(height: 18),
        ],
        SectionHeader(
          title: t('audit.title'),
          actionLabel: t('audit.openSecurity'),
          onAction: () => Navigator.of(context).push(
            MaterialPageRoute<void>(builder: (_) => const SecurityLogScreen()),
          ),
        ),
        if (visible.isEmpty)
          _EmptyLine(text: t('audit.empty'))
        else
          for (final entry in visible) ...[
            _TrailRow(entry: entry, locale: locale),
            const SizedBox(height: 10),
          ],
      ],
    );
  }
}

class _TrailRow extends ConsumerWidget {
  const _TrailRow({required this.entry, required this.locale});

  final AuditEntry entry;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    // A failure in the trail is the one row worth finding at a glance; the rest
    // are ordinary bookkeeping and stay quiet.
    final accent = entry.isFailure
        ? (isDark ? NeonPalette.magenta : NeonPalette.lightMagenta)
        : (isDark ? NeonPalette.cyan : NeonPalette.lightCyan);

    return NeonCardShell(
      key: ValueKey('audit-${entry.id}'),
      accent: accent,
      glow: entry.isFailure,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  AuditFormat.action(t, entry.action),
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: entry.isFailure
                        ? accent
                        : (isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary),
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Text(
                AuditFormat.timestamp(entry.createdAt, locale),
                style: TextStyle(
                  fontSize: 11,
                  color: isDark
                      ? NeonPalette.textMuted
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            entry.userName == null
                ? t('audit.bySystem')
                : t('audit.by', args: {'name': entry.userName!}),
            style: TextStyle(
              fontSize: 11.5,
              color: isDark
                  ? NeonPalette.textSecondary
                  : NeonPalette.lightTextSecondary,
            ),
          ),
          ChangeDiff(entry: entry),
        ],
      ),
    );
  }
}

class _EmptyLine extends StatelessWidget {
  const _EmptyLine({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 20),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 12.5,
          color:
              isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}
