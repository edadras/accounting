import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart' show clockProvider;
import '../../../data/recurring_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'recurring_presentation.dart';
import 'recurring_providers.dart';
import 'recurring_rule_editor_screen.dart';

/// Standing instructions, soonest first.
///
/// The server orders on `next_run_at`, which is the order the list wants: what
/// is about to touch the books, at the top. A rule that is due today or already
/// late glows — that is the one piece of information on this screen that is
/// about to cost money.
class RecurringScreen extends ConsumerWidget {
  const RecurringScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const RecurringScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final rules = ref.watch(recurringRulesProvider);

    return ModulePage(
      title: t('recurring.title'),
      subtitle: t('recurring.subtitle'),
      child: rules.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('recurring.noAccessTitle'),
          permissionBody: t('recurring.noAccessBody'),
        ),
        data: (list) => _RulesBody(rules: list),
      ),
    );
  }
}

class _RulesBody extends ConsumerWidget {
  const _RulesBody({required this.rules});

  final List<RecurringRule> rules;

  Future<void> _setPaused(
    BuildContext context,
    WidgetRef ref,
    RecurringRule rule,
  ) =>
      _run(
        context,
        ref,
        () => ref
            .read(recurringRepositoryProvider)
            .setPaused(rule.id, paused: !rule.isPaused),
      );

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    RecurringRule rule,
  ) async {
    final t = ref.read(translatorProvider);

    final confirmed = await confirmRecurringAction(
      context,
      title: t('recurring.deleteTitle'),
      body: t(
        'recurring.deleteBody',
        args: {'name': rule.name ?? t('recurring.unnamed')},
      ),
      confirmLabel: t('recurring.delete'),
      cancelLabel: t('common.cancel'),
    );
    if (!confirmed || !context.mounted) return;

    await _run(
      context,
      ref,
      () => ref.read(recurringRepositoryProvider).delete(rule.id),
    );
  }

  /// Runs a write, then refetches. `next_run_at` moves as a side effect of a
  /// write the server performs, so patching the row locally would show a date
  /// the poster does not agree with.
  static Future<void> _run(
    BuildContext context,
    WidgetRef ref,
    Future<void> Function() action,
  ) async {
    try {
      await action();
      ref.invalidate(recurringRulesProvider);
    } on RecurringRuleException catch (error) {
      if (!context.mounted) return;
      final t = ref.read(translatorProvider);
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text(t(error.translationKey))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return ListView(
      key: const ValueKey('recurring-list'),
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonButton(
          key: const ValueKey('recurring-add'),
          label: t('recurring.add'),
          icon: Icons.add_task_rounded,
          expand: true,
          onPressed: () async {
            await Navigator.of(context).push(RecurringRuleEditorScreen.route());
            ref.invalidate(recurringRulesProvider);
          },
        ),
        const SizedBox(height: 20),
        if (rules.isEmpty)
          _RulesEmpty(title: t('recurring.empty'), hint: t('recurring.emptyHint'))
        else
          for (final rule in rules) ...[
            _RuleCard(
              rule: rule,
              onTogglePause: () => _setPaused(context, ref, rule),
              onEdit: () async {
                await Navigator.of(context)
                    .push(RecurringRuleEditorScreen.route(existing: rule));
                ref.invalidate(recurringRulesProvider);
              },
              onDelete: () => _delete(context, ref, rule),
            ),
            const SizedBox(height: 12),
          ],
      ],
    );
  }
}

class _RulesEmpty extends StatelessWidget {
  const _RulesEmpty({required this.title, required this.hint});

  final String title;
  final String hint;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      key: const ValueKey('recurring-empty'),
      padding: const EdgeInsetsDirectional.symmetric(vertical: 34),
      child: Column(
        children: [
          Icon(
            Icons.event_repeat_rounded,
            size: 38,
            color:
                isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
          ),
          const SizedBox(height: 14),
          Text(
            title,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            hint,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 12,
              height: 1.6,
              color: isDark
                  ? NeonPalette.textMuted
                  : NeonPalette.lightTextSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

class _RuleCard extends ConsumerWidget {
  const _RuleCard({
    required this.rule,
    required this.onTogglePause,
    required this.onEdit,
    required this.onDelete,
  });

  final RecurringRule rule;
  final VoidCallback onTogglePause;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final now = ref.watch(clockProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final muted =
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary;
    final accent = rule.isRunning
        ? recurringAccentFor(
            transactionAccent(rule.template.type),
            isDark: isDark,
          )
        : muted;

    final next = rule.nextRunAt;
    final daysAway = next == null ? null : DateFormatter.daysUntil(next, now);

    // Due today or already late, and nothing is stopping it — the one state on
    // this screen that is about to move money on its own.
    final imminent = rule.isRunning && rule.autoPost && (daysAway ?? 1) <= 0;

    return NeonCardShell(
      key: ValueKey('recurring-${rule.id}'),
      accent: imminent
          ? recurringAccentFor(NeonPalette.amber, isDark: isDark)
          : accent,
      glow: imminent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                transactionIcon(rule.template.type),
                size: 18,
                color: accent,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      rule.name ??
                          rule.template.description ??
                          t('recurring.unnamed'),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      scheduleSummary(
                        rule.frequency,
                        rule.interval,
                        rule.dayOfMonth,
                        t: t,
                        locale: locale,
                      ),
                      style: TextStyle(fontSize: 11.5, color: muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Text(
                MoneyFormatter.format(
                  rule.template.amount,
                  locale: locale.code,
                ),
                style: TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                  color: accent,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          DetailRow(
            label: t('recurring.nextRun'),
            value: next == null
                ? t('recurring.ended')
                : DateFormatter.short(next, locale),
            strong: true,
            accent: imminent
                ? recurringAccentFor(NeonPalette.amber, isDark: isDark)
                : null,
          ),
          if (rule.lastRunAt != null)
            DetailRow(
              label: t('recurring.lastRun'),
              value: DateFormatter.short(rule.lastRunAt!, locale),
            ),
          if (rule.endsAt != null)
            DetailRow(
              label: t('recurring.endsAt'),
              value: DateFormatter.short(rule.endsAt!, locale),
            ),
          const SizedBox(height: 10),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              if (nextRunBadge(rule, now: now, t: t, locale: locale)
                  case final badge?)
                NeonChip(
                  label: badge,
                  accent: imminent
                      ? recurringAccentFor(NeonPalette.amber, isDark: isDark)
                      : accent,
                  selected: imminent,
                ),
              if (rule.isPaused)
                NeonChip(
                  key: ValueKey('recurring-paused-${rule.id}'),
                  label: t('recurring.paused'),
                  accent: muted,
                ),
              if (!rule.autoPost)
                NeonChip(
                  key: ValueKey('recurring-reminder-${rule.id}'),
                  label: t('recurring.reminderOnly'),
                  accent: recurringAccentFor(
                    NeonPalette.violet,
                    isDark: isDark,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              _RuleAction(
                actionKey: 'recurring-toggle-${rule.id}',
                icon: rule.isPaused
                    ? Icons.play_arrow_rounded
                    : Icons.pause_rounded,
                label:
                    rule.isPaused ? t('recurring.resume') : t('recurring.pause'),
                accent: recurringAccentFor(NeonPalette.amber, isDark: isDark),
                onPressed: onTogglePause,
              ),
              const SizedBox(width: 8),
              _RuleAction(
                actionKey: 'recurring-edit-${rule.id}',
                icon: Icons.edit_rounded,
                label: t('recurring.editTitle'),
                accent: recurringAccentFor(NeonPalette.cyan, isDark: isDark),
                onPressed: onEdit,
              ),
              const SizedBox(width: 8),
              _RuleAction(
                actionKey: 'recurring-delete-${rule.id}',
                icon: Icons.delete_outline_rounded,
                label: t('recurring.delete'),
                accent: recurringAccentFor(NeonPalette.magenta, isDark: isDark),
                onPressed: onDelete,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _RuleAction extends StatelessWidget {
  const _RuleAction({
    required this.actionKey,
    required this.icon,
    required this.label,
    required this.accent,
    required this.onPressed,
  });

  final String actionKey;
  final IconData icon;
  final String label;
  final Color accent;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: GestureDetector(
        key: ValueKey(actionKey),
        onTap: onPressed,
        child: Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            color: accent.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
            border: NeonEffects.border(accent, alpha: 0.30),
          ),
          child: Icon(icon, size: 17, color: accent),
        ),
      ),
    );
  }
}

/// A yes/no gate before anything irreversible.
Future<bool> confirmRecurringAction(
  BuildContext context, {
  required String title,
  required String body,
  required String confirmLabel,
  required String cancelLabel,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(body),
      actions: [
        TextButton(
          key: const ValueKey('recurring-confirm-cancel'),
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(cancelLabel),
        ),
        TextButton(
          key: const ValueKey('recurring-confirm-ok'),
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(confirmLabel),
        ),
      ],
    ),
  );

  return confirmed ?? false;
}
