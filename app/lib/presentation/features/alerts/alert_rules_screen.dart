import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/alerts_repository.dart';
import '../../../data/ledger_repository.dart' show baseCurrencyProvider;
import '../../../core/money/money.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'alert_presentation.dart';
import 'alert_rule_editor_screen.dart';
import 'alerts_providers.dart';

/// What this workspace wants to be warned about.
///
/// One row per rule. Editing and pausing are both a single `PATCH`, so a rule
/// that fails to save is the rule that was there before — the row never passes
/// through a moment where it does not exist.
class AlertRulesScreen extends ConsumerWidget {
  const AlertRulesScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const AlertRulesScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final rules = ref.watch(alertRulesProvider);

    return ModulePage(
      title: t('alerts.rulesTitle'),
      subtitle: t('alerts.rulesSubtitle'),
      child: rules.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('alerts.noAccessTitle'),
          permissionBody: t('alerts.noAccessBody'),
        ),
        data: (list) => _RulesBody(rules: list),
      ),
    );
  }
}

class _RulesBody extends ConsumerWidget {
  const _RulesBody({required this.rules});

  final List<AlertRule> rules;

  Future<void> _toggle(
    BuildContext context,
    WidgetRef ref,
    AlertRule rule,
  ) =>
      _run(
        context,
        ref,
        () => ref
            .read(alertsRepositoryProvider)
            .setRuleActive(rule.id, active: !rule.isActive),
      );

  Future<void> _delete(
    BuildContext context,
    WidgetRef ref,
    AlertRule rule,
  ) async {
    final t = ref.read(translatorProvider);

    final confirmed = await confirmAlertAction(
      context,
      title: t('alerts.deleteRuleTitle'),
      body: t('alerts.deleteRuleBody', args: {'type': t(alertTypeKey(rule.type))}),
      confirmLabel: t('alerts.deleteRule'),
      cancelLabel: t('common.cancel'),
    );
    if (!confirmed || !context.mounted) return;

    await _run(
      context,
      ref,
      () => ref.read(alertsRepositoryProvider).deleteRule(rule.id),
    );
  }

  static Future<void> _run(
    BuildContext context,
    WidgetRef ref,
    Future<void> Function() action,
  ) async {
    try {
      await action();
      ref.invalidate(alertRulesProvider);
    } on AlertsException catch (error) {
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
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonButton(
          key: const ValueKey('alerts-add-rule'),
          label: t('alerts.addRule'),
          icon: Icons.add_alert_rounded,
          expand: true,
          onPressed: () async {
            await Navigator.of(context).push(AlertRuleEditorScreen.route());
            ref.invalidate(alertRulesProvider);
          },
        ),
        const SizedBox(height: 20),
        if (rules.isEmpty)
          _RulesEmpty(title: t('alerts.rulesEmpty'), hint: t('alerts.rulesEmptyHint'))
        else
          for (final rule in rules) ...[
            _RuleCard(
              rule: rule,
              onToggle: () => _toggle(context, ref, rule),
              onEdit: () async {
                await Navigator.of(context)
                    .push(AlertRuleEditorScreen.route(existing: rule));
                ref.invalidate(alertRulesProvider);
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
      key: const ValueKey('alerts-rules-empty'),
      padding: const EdgeInsetsDirectional.symmetric(vertical: 30),
      child: Column(
        children: [
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
    required this.onToggle,
    required this.onEdit,
    required this.onDelete,
  });

  final AlertRule rule;
  final VoidCallback onToggle;
  final VoidCallback onEdit;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final currency = ref.watch(baseCurrencyProvider);

    final (icon, rawAccent) = alertIconAndAccent(rule.type);
    final muted =
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary;
    final accent =
        rule.isActive ? alertAccentFor(rawAccent, isDark: isDark) : muted;

    final threshold = rule.threshold;

    return NeonCardShell(
      key: ValueKey('rule-${rule.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: accent),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  t(alertTypeKey(rule.type)),
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              NeonChip(
                key: ValueKey('rule-state-${rule.id}'),
                label: rule.isActive
                    ? t('alerts.ruleActive')
                    : t('alerts.ruleInactive'),
                accent: accent,
                selected: rule.isActive,
              ),
            ],
          ),
          const SizedBox(height: 8),
          if (rule.type.usesLeadDays)
            DetailRow(
              label: t('alerts.leadDays'),
              value: t(
                'alerts.leadDaysValue',
                args: {
                  'days': DateFormatter.number(rule.leadDays, locale.code),
                },
              ),
            ),
          if (rule.type.usesThreshold)
            DetailRow(
              label: t('alerts.thresholdAmount'),
              value: MoneyFormatter.format(
                Money(threshold ?? 0, currency),
                locale: locale.code,
              ),
            ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final channel in rule.channels)
                NeonChip(
                  label: t(alertChannelKey(channel)),
                  accent: accent,
                  selected: false,
                ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              _RuleAction(
                actionKey: 'rule-toggle-${rule.id}',
                icon: rule.isActive
                    ? Icons.pause_circle_outline_rounded
                    : Icons.play_circle_outline_rounded,
                label: rule.isActive
                    ? t('alerts.deactivate')
                    : t('alerts.activate'),
                accent: alertAccentFor(NeonPalette.amber, isDark: isDark),
                onPressed: onToggle,
              ),
              const SizedBox(width: 8),
              _RuleAction(
                actionKey: 'rule-edit-${rule.id}',
                icon: Icons.edit_rounded,
                label: t('alerts.editRule'),
                accent: alertAccentFor(NeonPalette.cyan, isDark: isDark),
                onPressed: onEdit,
              ),
              const SizedBox(width: 8),
              _RuleAction(
                actionKey: 'rule-delete-${rule.id}',
                icon: Icons.delete_outline_rounded,
                label: t('alerts.deleteRule'),
                accent: alertAccentFor(NeonPalette.magenta, isDark: isDark),
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

/// A yes/no gate for anything destructive on the alerts screens.
///
/// Its own copy rather than the members one so this feature does not depend on
/// another feature's dialogs, and so both labels come from this namespace.
Future<bool> confirmAlertAction(
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
          key: const ValueKey('alerts-confirm-cancel'),
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(cancelLabel),
        ),
        TextButton(
          key: const ValueKey('alerts-confirm-ok'),
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(confirmLabel),
        ),
      ],
    ),
  );

  return confirmed ?? false;
}
