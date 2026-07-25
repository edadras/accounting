import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/remote/money_codec.dart';
import '../../../sync/conflict.dart';
import '../../../sync/sync_controller.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';

/// Side-by-side resolution for the conflicts sync refused to guess at.
///
/// docs/09-sync-offline.md §6: a disagreement about an amount is shown, never
/// merged. Both versions stay visible until a person picks one.
class ConflictsScreen extends ConsumerWidget {
  const ConflictsScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const ConflictsScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final controller = ref.watch(syncControllerProvider);

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('sync.conflicts'))),
        body: SafeArea(
          top: false,
          // Listens to the controller directly rather than to a provider, so
          // the screen is correct even when opened outside the sync scope.
          child: ListenableBuilder(
            listenable: controller,
            builder: (context, _) {
              final conflicts = controller.conflicts;

              if (conflicts.isEmpty) {
                return Center(
                  child: Text(
                    t('sync.conflictsEmpty'),
                    style: const TextStyle(color: NeonPalette.textSecondary),
                  ),
                );
              }

              return ListView.separated(
                padding: const EdgeInsetsDirectional.all(16),
                itemCount: conflicts.length,
                separatorBuilder: (_, __) => const SizedBox(height: 14),
                itemBuilder: (_, index) => _ConflictCard(
                  conflict: conflicts[index],
                  onResolve: (choice) => controller.engine.resolve(
                    conflicts[index],
                    choice,
                  ),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _ConflictCard extends ConsumerWidget {
  const _ConflictCard({required this.conflict, required this.onResolve});

  final SyncConflict conflict;
  final void Function(ConflictResolution) onResolve;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;

    final fields = conflict.fields.isEmpty
        ? ConflictPolicy.divergentFields(conflict.local, conflict.server)
        : conflict.fields;

    return NeonCardShell(
      accent: NeonPalette.amber,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionHeader(
            title: t('entity.${conflict.entity}'),
            accent: NeonPalette.amber,
          ),
          Text(
            t('sync.conflictHint'),
            style: const TextStyle(
              fontSize: 12,
              color: NeonPalette.textSecondary,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: _VersionColumn(
                  title: t('sync.mine'),
                  accent: NeonPalette.cyan,
                  fields: fields,
                  values: conflict.local,
                  locale: locale,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _VersionColumn(
                  title: t('sync.theirs'),
                  accent: NeonPalette.violet,
                  fields: fields,
                  values: conflict.server,
                  locale: locale,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: NeonButton(
                  label: t('sync.keepMine'),
                  accent: NeonPalette.cyan,
                  onPressed: () => onResolve(ConflictResolution.keepLocal),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: NeonButton(
                  label: t('sync.keepServer'),
                  accent: NeonPalette.violet,
                  variant: NeonButtonVariant.outline,
                  onPressed: () => onResolve(ConflictResolution.keepServer),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _VersionColumn extends ConsumerWidget {
  const _VersionColumn({
    required this.title,
    required this.accent,
    required this.fields,
    required this.values,
    required this.locale,
  });

  final String title;
  final Color accent;
  final List<String> fields;
  final Map<String, Object?> values;
  final String locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: accent,
          ),
        ),
        const SizedBox(height: 8),
        for (final field in fields) ...[
          Text(
            t('field.$field'),
            style: const TextStyle(
              fontSize: 11,
              color: NeonPalette.textMuted,
            ),
          ),
          Text(
            _render(values[field], locale),
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: NeonPalette.textPrimary,
            ),
          ),
          const SizedBox(height: 8),
        ],
      ],
    );
  }

  /// Money is rendered through the formatter, so the two columns are compared
  /// in the same shape the rest of the app shows amounts in.
  static String _render(Object? value, String locale) {
    if (value == null) return '—';

    if (value is Map && value['value'] != null) {
      final money = MoneyCodec.tryDecode(value);
      if (money != null) return MoneyFormatter.format(money, locale: locale);
    }

    if (value is String) {
      final moment = DateTime.tryParse(value);
      if (moment != null) {
        final local = moment.toLocal();
        return '${local.year}-${_two(local.month)}-${_two(local.day)} '
            '${_two(local.hour)}:${_two(local.minute)}';
      }
    }

    return '$value';
  }

  static String _two(int value) => value.toString().padLeft(2, '0');
}
