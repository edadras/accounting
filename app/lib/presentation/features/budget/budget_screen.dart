import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/budget_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../members/member_dialogs.dart';
import '../more/module_scaffold.dart';
import 'budget_form_screen.dart';
import 'budget_labels.dart';
import 'budget_providers.dart';

/// Budget management: every ceiling, what it has consumed, and the three things
/// a person can do about it.
///
/// The reports screen already *shows* budgets. This one is where they come
/// from: without it a budget could only ever be created by talking to the API
/// directly.
class BudgetScreen extends ConsumerWidget {
  const BudgetScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const BudgetScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final board = ref.watch(budgetBoardProvider);

    return ModulePage(
      title: t('budget.manageTitle'),
      subtitle: t('budget.manageSubtitle'),
      child: board.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('budget.noAccessTitle'),
          permissionBody: t('budget.noAccessBody'),
        ),
        data: (board) => _BudgetList(board: board),
      ),
    );
  }
}

class _BudgetList extends ConsumerWidget {
  const _BudgetList({required this.board});

  final BudgetBoard board;

  Future<void> _create(BuildContext context, WidgetRef ref) async {
    final saved = await Navigator.of(context).push<bool>(
      BudgetFormScreen.route(),
    );
    if (saved ?? false) ref.invalidate(budgetBoardProvider);
  }

  Future<void> _edit(
    BuildContext context,
    WidgetRef ref,
    BudgetBoardEntry entry,
  ) async {
    final draft = entry.draft;
    if (draft == null) return;

    final saved = await Navigator.of(context).push<bool>(
      BudgetFormScreen.route(budgetId: entry.id, draft: draft),
    );
    if (saved ?? false) ref.invalidate(budgetBoardProvider);
  }

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    BudgetBoardEntry entry,
  ) async {
    final t = ref.read(translatorProvider);

    final confirmed = await confirmAction(
      context,
      title: t('budget.deleteTitle'),
      body: t('budget.deleteBody', args: {'name': entry.status.name}),
      confirmLabel: t('budget.delete'),
    );
    if (!confirmed || !context.mounted) return;

    try {
      await ref.read(budgetRepositoryProvider).delete(entry.id);
      ref.invalidate(budgetBoardProvider);
    } on BudgetException catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(
          SnackBar(content: Text(t(error.translationKey))),
        );
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonButton(
          key: const ValueKey('budget-create'),
          label: t('budget.create'),
          icon: Icons.add_chart_rounded,
          accent: NeonPalette.violet,
          expand: true,
          onPressed: () => _create(context, ref),
        ),
        const SizedBox(height: 22),
        SectionHeader(
          title: t('budget.manageTitle'),
          accent: NeonPalette.violet,
        ),
        if (board.isEmpty)
          _EmptyLine(text: t('budget.empty'))
        else
          for (final entry in board.entries) ...[
            _BudgetRow(
              entry: entry,
              locale: locale,
              onEdit: entry.draft == null ? null : () => _edit(context, ref, entry),
              onDelete: () => _delete(context, ref, entry),
            ),
            const SizedBox(height: 12),
          ],
      ],
    );
  }
}

class _BudgetRow extends ConsumerWidget {
  const _BudgetRow({
    required this.entry,
    required this.locale,
    required this.onEdit,
    required this.onDelete,
  });

  final BudgetBoardEntry entry;
  final AppLocale locale;
  final VoidCallback? onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final status = entry.status;

    // Glow is information, not decoration: only a breach earns it. A budget at
    // its limit has not overspent, and a budget at 40% is unremarkable.
    final accent = status.isOverLimit
        ? (isDark ? NeonPalette.magenta : NeonPalette.lightMagenta)
        : status.isAtLimit || status.isNearLimit
            ? NeonPalette.amber
            : (isDark ? NeonPalette.violet : NeonPalette.lightViolet);

    final carriedOver = status.carriedOver;

    return NeonCardShell(
      key: ValueKey('budget-${status.id}'),
      accent: accent,
      glow: status.isOverLimit,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  status.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 14.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              Text(
                t('budget.percentUsed', args: {
                  'percent': DateFormatter.number(
                    status.usedPercent,
                    locale.code,
                  ),
                },),
                key: ValueKey('budget-percent-${status.id}'),
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: accent,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              NeonChip(
                label: budgetScopeLabel(t, status.scope),
                accent: accent,
              ),
              NeonChip(
                label: budgetPeriodLabel(t, status.period),
                accent: accent,
              ),
              if (carriedOver != null)
                NeonChip(
                  key: ValueKey('budget-carried-${status.id}'),
                  label: '${t('budget.rollover')} '
                      '${MoneyFormatter.format(
                    carriedOver,
                    locale: locale.code,
                    compact: true,
                  )}',
                  accent: NeonPalette.lime,
                  icon: Icons.arrow_forward_rounded,
                )
              else if (status.rollover)
                NeonChip(
                  key: ValueKey('budget-rollover-on-${status.id}'),
                  label: t('budget.rolloverOn'),
                  accent: accent,
                  icon: Icons.autorenew_rounded,
                ),
            ],
          ),
          const SizedBox(height: 12),
          NeonMeter(fraction: status.meterFraction, accent: accent),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${MoneyFormatter.format(
                    status.spent,
                    locale: locale.code,
                    compact: true,
                  )} / ${MoneyFormatter.format(
                    status.effectiveAmount,
                    locale: locale.code,
                    compact: true,
                  )}',
                  style: TextStyle(
                    fontSize: 11.5,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              Text(
                _standingLabel(t),
                key: ValueKey('budget-standing-${status.id}'),
                textAlign: TextAlign.end,
                style: TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                  color: accent,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: Text(
                  status.periodKey,
                  style: TextStyle(
                    fontSize: 10.5,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              if (onEdit != null) ...[
                _RowAction(
                  actionKey: 'budget-edit-${status.id}',
                  icon: Icons.tune_rounded,
                  tooltip: t('budget.edit'),
                  accent: isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
                  onPressed: onEdit!,
                ),
                const SizedBox(width: 6),
              ],
              _RowAction(
                actionKey: 'budget-delete-${status.id}',
                icon: Icons.delete_outline_rounded,
                tooltip: t('budget.delete'),
                accent: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
                onPressed: onDelete,
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Three distinct states, because they mean three different things to the
  /// person reading the row: over, exactly spent, and money left.
  String _standingLabel(Translator t) {
    final status = entry.status;

    if (status.isOverLimit) {
      return '${t('budget.over')} '
          '${MoneyFormatter.format(
        status.remaining.absolute,
        locale: locale.code,
        compact: true,
      )}';
    }

    if (status.isAtLimit) return t('budget.atLimit');

    return '${t('budget.remaining')} '
        '${MoneyFormatter.format(
      status.remaining,
      locale: locale.code,
      compact: true,
    )}';
  }
}

class _RowAction extends StatelessWidget {
  const _RowAction({
    required this.actionKey,
    required this.icon,
    required this.tooltip,
    required this.accent,
    required this.onPressed,
  });

  final String actionKey;
  final IconData icon;
  final String tooltip;
  final Color accent;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: tooltip,
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

class _EmptyLine extends StatelessWidget {
  const _EmptyLine({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 14),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 12.5,
          color: isDark
              ? NeonPalette.textMuted
              : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}
