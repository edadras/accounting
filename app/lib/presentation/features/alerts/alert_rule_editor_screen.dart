import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/alerts_repository.dart';
import '../../../data/ledger_repository.dart' show baseCurrencyProvider;
import '../../../data/remote/money_codec.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'alert_presentation.dart';
import 'alerts_providers.dart';

/// Create a rule, or edit one.
///
/// An edit is a single `PATCH`, so there is nothing to warn about: a save that
/// fails leaves the rule exactly as it was. The one thing the screen does not
/// offer on an existing rule is its type, because the type decides which
/// scanner reads the rule and therefore what its config means — changing it
/// would reinterpret the settings rather than edit them, which is a new rule.
class AlertRuleEditorScreen extends ConsumerStatefulWidget {
  const AlertRuleEditorScreen({super.key, this.existing});

  final AlertRule? existing;

  static Route<void> route({AlertRule? existing}) => MaterialPageRoute<void>(
        builder: (_) => AlertRuleEditorScreen(existing: existing),
      );

  @override
  ConsumerState<AlertRuleEditorScreen> createState() =>
      _AlertRuleEditorScreenState();
}

class _AlertRuleEditorScreenState extends ConsumerState<AlertRuleEditorScreen> {
  late final TextEditingController _threshold;

  late AlertRuleType _type;
  late int _leadDays;
  late Set<AlertChannel> _channels;
  late bool _isActive;

  bool _busy = false;

  /// Always a translation key, never a sentence from the server.
  String? _errorKey;

  bool get _isEditing => widget.existing != null;

  @override
  void initState() {
    super.initState();

    final existing = widget.existing;
    final currency = ref.read(baseCurrencyProvider);

    _type = existing?.type ?? AlertRuleType.checkDue;
    _leadDays = existing?.leadDays ?? 3;
    _channels = {...?existing?.channels, AlertChannel.database};
    _isActive = existing?.isActive ?? true;
    _threshold = TextEditingController(
      text: existing?.threshold == null
          ? ''
          : MoneyCodec.decimalString(Money(existing!.threshold!, currency)),
    );
  }

  @override
  void dispose() {
    _threshold.dispose();
    super.dispose();
  }

  /// Only the keys the scanner for this type actually reads. Carrying a stale
  /// `threshold` on a cheque rule would be a setting the user could see and
  /// nothing would honour.
  ///
  /// Merged over whatever the rule already holds rather than written fresh: a
  /// balance rule can carry a per-account `thresholds` map this screen never
  /// shows, and replacing the whole object would silently drop a setting the
  /// user never saw, let alone changed.
  Map<String, Object?>? _config() {
    if (!_type.usesThreshold) return const {};

    final parsed = Money.tryParse(_threshold.text, ref.read(baseCurrencyProvider));
    if (parsed == null || parsed.isNegative) return null;

    return {...?widget.existing?.config, 'threshold': parsed.minorUnits};
  }

  Future<void> _save() async {
    final config = _config();

    if (config == null) {
      setState(() => _errorKey = 'alerts.thresholdInvalid');
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final repository = ref.read(alertsRepositoryProvider);
      final existing = widget.existing;

      if (existing != null) {
        // No `type`: it cannot change, and sending the one it already has
        // would only invite a 422 on a request that changes nothing.
        await repository.updateRule(
          existing.id,
          // Omitted for a kind that has no settings of its own, so the server
          // keeps whatever it holds instead of being handed an empty object.
          config: _type.usesThreshold ? config : null,
          channels: _orderedChannels(),
          leadDays: _leadDays,
          isActive: _isActive,
        );
      } else {
        await repository.createRule(
          AlertRule(
            id: '',
            type: _type,
            config: config,
            channels: _orderedChannels(),
            leadDays: _leadDays,
            isActive: _isActive,
          ),
        );
      }

      ref.invalidate(alertRulesProvider);
      if (!mounted) return;
      Navigator.of(context).pop();
    } on AlertsException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorKey = error.translationKey;
        _busy = false;
      });
    }
  }

  /// Channel order follows the enum so two rules with the same channels always
  /// render the same chips in the same places.
  List<AlertChannel> _orderedChannels() => [
        for (final channel in AlertChannel.values)
          if (_channels.contains(channel)) channel,
      ];

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final currency = ref.watch(baseCurrencyProvider);

    return ModulePage(
      title: _isEditing ? t('alerts.editRuleTitle') : t('alerts.newRuleTitle'),
      subtitle: t('alerts.rulesSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          SectionHeader(title: t('alerts.ruleType')),
          if (_isEditing)
            DetailRow(
              label: t('alerts.ruleType'),
              value: t(alertTypeKey(_type)),
              strong: true,
            )
          else
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final option in AlertRuleType.values)
                  NeonChip(
                    key: ValueKey('rule-type-${option.wire}'),
                    label: t(alertTypeKey(option)),
                    accent: alertAccentFor(
                      alertIconAndAccent(option).$2,
                      isDark: isDark,
                    ),
                    selected: option == _type,
                    onTap: () => setState(() => _type = option),
                  ),
              ],
            ),
          const SizedBox(height: 20),
          if (_type.usesLeadDays) ...[
            SectionHeader(title: t('alerts.leadDays')),
            _Stepper(
              valueKey: 'rule-lead',
              value: t(
                'alerts.leadDaysValue',
                args: {'days': DateFormatter.number(_leadDays, locale.code)},
              ),
              onDecrease: _leadDays > 0
                  ? () => setState(() => _leadDays -= 1)
                  : null,
              onIncrease: _leadDays < 365
                  ? () => setState(() => _leadDays += 1)
                  : null,
            ),
            const SizedBox(height: 6),
            _Note(text: t('alerts.leadDaysNote')),
            const SizedBox(height: 20),
          ],
          if (_type.usesThreshold) ...[
            SectionHeader(title: t('alerts.thresholdAmount')),
            TextField(
              key: const ValueKey('rule-threshold'),
              controller: _threshold,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: InputDecoration(
                hintText: t('alerts.thresholdHint'),
                suffixText: currency.code,
              ),
            ),
            const SizedBox(height: 6),
            _Note(text: t('alerts.thresholdNote')),
            const SizedBox(height: 20),
          ],
          if (_type == AlertRuleType.budgetThreshold) ...[
            _Note(text: t('alerts.budgetRuleNote')),
            const SizedBox(height: 20),
          ],
          SectionHeader(title: t('alerts.ruleChannels')),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final channel in AlertChannel.values)
                NeonChip(
                  key: ValueKey('rule-channel-${channel.wire}'),
                  label: t(alertChannelKey(channel)),
                  accent: alertAccentFor(NeonPalette.cyan, isDark: isDark),
                  selected: _channels.contains(channel),
                  icon: channel.isMandatory ? Icons.lock_rounded : null,
                  onTap: channel.isMandatory
                      ? null
                      : () => setState(() {
                            if (!_channels.remove(channel)) {
                              _channels.add(channel);
                            }
                          }),
                ),
            ],
          ),
          const SizedBox(height: 6),
          _Note(text: t('alerts.channelLocked')),
          const SizedBox(height: 20),
          SectionHeader(title: t('alerts.ruleState')),
          Wrap(
            spacing: 8,
            children: [
              NeonChip(
                key: const ValueKey('rule-active-on'),
                label: t('alerts.ruleActive'),
                accent: alertAccentFor(NeonPalette.lime, isDark: isDark),
                selected: _isActive,
                onTap: () => setState(() => _isActive = true),
              ),
              NeonChip(
                key: const ValueKey('rule-active-off'),
                label: t('alerts.ruleInactive'),
                accent: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
                selected: !_isActive,
                onTap: () => setState(() => _isActive = false),
              ),
            ],
          ),
          const SizedBox(height: 26),
          NeonButton(
            key: const ValueKey('rule-save'),
            label: t('alerts.saveRule'),
            icon: Icons.save_rounded,
            expand: true,
            busy: _busy,
            onPressed: _busy ? null : _save,
          ),
          if (_errorKey != null) _InlineError(message: t(_errorKey!)),
        ],
      ),
    );
  }
}

/// A number the user nudges rather than types — lead days has a small, bounded
/// range and a keyboard for it would be three taps too many.
class _Stepper extends StatelessWidget {
  const _Stepper({
    required this.valueKey,
    required this.value,
    required this.onDecrease,
    required this.onIncrease,
  });

  final String valueKey;
  final String value;
  final VoidCallback? onDecrease;
  final VoidCallback? onIncrease;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = isDark ? NeonPalette.cyan : NeonPalette.lightCyan;

    return Row(
      children: [
        _StepperButton(
          buttonKey: '$valueKey-minus',
          icon: Icons.remove_rounded,
          accent: accent,
          onPressed: onDecrease,
        ),
        Expanded(
          child: Text(
            value,
            key: ValueKey('$valueKey-value'),
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
        ),
        _StepperButton(
          buttonKey: '$valueKey-plus',
          icon: Icons.add_rounded,
          accent: accent,
          onPressed: onIncrease,
        ),
      ],
    );
  }
}

class _StepperButton extends StatelessWidget {
  const _StepperButton({
    required this.buttonKey,
    required this.icon,
    required this.accent,
    required this.onPressed,
  });

  final String buttonKey;
  final IconData icon;
  final Color accent;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final enabled = onPressed != null;

    return Semantics(
      button: true,
      enabled: enabled,
      child: GestureDetector(
        key: ValueKey(buttonKey),
        onTap: onPressed,
        child: Opacity(
          opacity: enabled ? 1 : 0.4,
          child: Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
              border: NeonEffects.border(accent, alpha: 0.30),
            ),
            child: Icon(icon, size: 18, color: accent),
          ),
        ),
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 11.5,
        height: 1.6,
        color:
            isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
      ),
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(top: 12),
      child: Text(
        message,
        style: TextStyle(
          fontSize: 12.5,
          height: 1.5,
          color: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
        ),
      ),
    );
  }
}
