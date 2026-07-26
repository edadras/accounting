import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/currency.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/budget_repository.dart';
import '../../../data/ledger_repository.dart';
import '../../../data/remote/money_codec.dart';
import '../../../domain/analytics.dart' show BudgetPeriod;
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'budget_labels.dart';
import 'budget_providers.dart';

/// Create a budget, or edit one in place.
///
/// An edit is a PATCH that keeps the budget's id, so every period already
/// measured against it goes on meaning the same thing. The one field the form
/// will not offer on an edit is the currency: the server rejects a change to it
/// outright, because spend is measured in the budget's own currency and
/// re-denominating would reinterpret figures already recorded.
class BudgetFormScreen extends ConsumerStatefulWidget {
  const BudgetFormScreen({super.key, this.budgetId, this.initial});

  /// Null when creating.
  final String? budgetId;
  final BudgetDraft? initial;

  /// Pops with `true` when something was written, so the caller knows whether
  /// to refetch.
  static Route<bool> route({String? budgetId, BudgetDraft? draft}) =>
      MaterialPageRoute<bool>(
        builder: (_) => BudgetFormScreen(budgetId: budgetId, initial: draft),
      );

  @override
  ConsumerState<BudgetFormScreen> createState() => _BudgetFormScreenState();
}

class _BudgetFormScreenState extends ConsumerState<BudgetFormScreen> {
  late final TextEditingController _name =
      TextEditingController(text: widget.initial?.name ?? '');

  /// Held as text, parsed to [Money] by [Money.tryParse] — never through a
  /// double, and never through a locale-aware number parser.
  late final TextEditingController _amount = TextEditingController(
    text: widget.initial == null
        ? ''
        : MoneyCodec.decimalString(widget.initial!.amount),
  );

  late BudgetScope _scope = widget.initial?.scope ?? BudgetScope.overall;
  late String? _scopeId = widget.initial?.scopeId;
  late BudgetPeriod _period = widget.initial?.period ?? BudgetPeriod.monthly;
  late DateTime _startsAt = widget.initial?.startsAt ?? DateTime.now();
  late DateTime? _endsAt = widget.initial?.endsAt;
  late bool _rollover = widget.initial?.rollover ?? false;

  bool _busy = false;

  /// Translation keys, never a server sentence.
  String? _errorKey;
  String? _validationKey;

  bool get _isEdit => widget.budgetId != null;

  /// The currency the typed amount is read in.
  ///
  /// An existing budget keeps its own — it cannot be re-denominated, so parsing
  /// the field as the workspace base currency would send minor units the server
  /// would then read as something else entirely.
  Currency _currency(WidgetRef ref) =>
      widget.initial?.amount.currency ?? ref.read(baseCurrencyProvider);

  @override
  void dispose() {
    _name.dispose();
    _amount.dispose();
    super.dispose();
  }

  /// The scopes on offer: the two the form can target, plus whatever this
  /// budget already is, so opening a project budget cannot silently re-point it
  /// at the whole ledger.
  List<BudgetScope> get _offeredScopes => [
        ...budgetFormScopes,
        if (!budgetFormScopes.contains(_scope)) _scope,
      ];

  /// The first thing wrong with the form, as a translation key, or null.
  ///
  /// Checked here rather than only on the server so a typo costs no round trip;
  /// the server still validates everything, and its refusal still wins.
  String? _validate({required String name, required Money? amount}) {
    if (name.isEmpty) return 'budget.nameRequired';
    if (amount == null || !amount.isPositive) return 'budget.amountInvalid';
    if (_scope.needsTarget && (_scopeId ?? '').isEmpty) {
      return 'budget.categoryRequired';
    }
    if (_period.needsEndDate) {
      final end = _endsAt;
      if (end == null) return 'budget.endRequired';
      if (end.isBefore(_startsAt)) return 'budget.endBeforeStart';
    }
    return null;
  }

  Future<void> _save() async {
    final currency = _currency(ref);
    final name = _name.text.trim();
    final amount = Money.tryParse(_amount.text, currency);

    final validation = _validate(name: name, amount: amount);

    if (validation != null) {
      setState(() {
        _validationKey = validation;
        _errorKey = null;
      });
      return;
    }

    final draft = BudgetDraft(
      name: name,
      scope: _scope,
      scopeId: _scopeId,
      period: _period,
      amount: amount!,
      startsAt: _startsAt,
      endsAt: _endsAt,
      rollover: _rollover,
      alertThresholds: widget.initial?.alertThresholds ?? const [80, 100],
    );

    setState(() {
      _busy = true;
      _validationKey = null;
      _errorKey = null;
    });

    final repository = ref.read(budgetRepositoryProvider);
    final id = widget.budgetId;

    try {
      if (id == null) {
        await repository.create(draft);
      } else {
        await repository.update(id: id, draft: draft);
      }

      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on BudgetException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorKey = error.translationKey;
        _busy = false;
      });
    }
  }

  Future<void> _pickDate({required bool start}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: start ? _startsAt : (_endsAt ?? _startsAt),
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;

    setState(() {
      if (start) {
        _startsAt = picked;
      } else {
        _endsAt = picked;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final currency = _currency(ref);
    final categories = ref.watch(categoriesProvider);

    return ModulePage(
      title: t(_isEdit ? 'budget.editTitle' : 'budget.createTitle'),
      subtitle: t(_isEdit ? 'budget.editSubtitle' : 'budget.createSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          _Field(
            label: t('budget.name'),
            child: TextField(
              key: const ValueKey('budget-name'),
              controller: _name,
              textInputAction: TextInputAction.next,
              decoration: InputDecoration(hintText: t('budget.nameHint')),
            ),
          ),
          const SizedBox(height: 18),
          _Field(
            label: t('budget.limit', args: {'currency': currency.code}),
            child: TextField(
              key: const ValueKey('budget-amount'),
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [
                // Digits (in any of the three shapes the app accepts) and a
                // single separator. Money.tryParse is still the authority; this
                // only keeps letters out of an amount field.
                FilteringTextInputFormatter.allow(
                  RegExp(r'[0-9۰-۹٠-٩.,٫٬\s-]'),
                ),
              ],
              decoration: const InputDecoration(hintText: '0'),
            ),
          ),
          const SizedBox(height: 18),
          _Field(
            label: t('budget.scope'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final scope in _offeredScopes)
                  NeonChip(
                    key: ValueKey('budget-scope-${scope.name}'),
                    label: budgetScopeLabel(t, scope),
                    accent: NeonPalette.violet,
                    selected: scope == _scope,
                    onTap: () => setState(() {
                      _scope = scope;
                      if (!scope.needsTarget) _scopeId = null;
                    }),
                  ),
              ],
            ),
          ),
          if (_scope == BudgetScope.category) ...[
            const SizedBox(height: 18),
            _Field(
              label: t('budget.category'),
              child: categories.when(
                loading: () => const ModuleLoading(),
                error: (_, __) => _Note(text: t('budget.categoriesUnavailable')),
                data: (list) => Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final category in list)
                      NeonChip(
                        key: ValueKey('budget-category-${category.id}'),
                        label: category.name,
                        accent: NeonPalette.cyan,
                        selected: category.id == _scopeId,
                        onTap: () => setState(() => _scopeId = category.id),
                      ),
                  ],
                ),
              ),
            ),
          ],
          const SizedBox(height: 18),
          _Field(
            label: t('budget.period'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final period in BudgetPeriod.values)
                  NeonChip(
                    key: ValueKey('budget-period-${period.name}'),
                    label: budgetPeriodLabel(t, period),
                    accent: NeonPalette.violet,
                    selected: period == _period,
                    onTap: () => setState(() => _period = period),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          _Field(
            label: t('budget.startsAt'),
            child: _DateButton(
              buttonKey: const ValueKey('budget-starts-at'),
              label: DateFormatter.short(_startsAt, locale),
              onPressed: () => _pickDate(start: true),
            ),
          ),
          if (_period.needsEndDate) ...[
            const SizedBox(height: 18),
            _Field(
              label: t('budget.endsAt'),
              child: _DateButton(
                buttonKey: const ValueKey('budget-ends-at'),
                label: _endsAt == null
                    ? t('budget.pickDate')
                    : DateFormatter.short(_endsAt!, locale),
                onPressed: () => _pickDate(start: false),
              ),
            ),
          ],
          const SizedBox(height: 18),
          _RolloverSwitch(
            value: _rollover,
            onChanged: (value) => setState(() => _rollover = value),
          ),
          if (_validationKey != null || _errorKey != null) ...[
            const SizedBox(height: 16),
            _Note(
              text: t(_validationKey ?? _errorKey!),
              accent: NeonPalette.magenta,
            ),
          ],
          const SizedBox(height: 24),
          NeonButton(
            key: const ValueKey('budget-save'),
            label: t(_isEdit ? 'budget.saveChanges' : 'budget.create'),
            icon: Icons.check_rounded,
            accent: NeonPalette.violet,
            expand: true,
            busy: _busy,
            onPressed: _busy ? null : _save,
          ),
        ],
      ),
    );
  }
}

/// The one control on this screen that needs explaining, so it explains itself
/// rather than relying on a help page nobody opens.
class _RolloverSwitch extends ConsumerWidget {
  const _RolloverSwitch({required this.value, required this.onChanged});

  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('budget.rolloverLabel'),
                style: TextStyle(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  color: isDark
                      ? NeonPalette.textPrimary
                      : NeonPalette.lightTextPrimary,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                t('budget.rolloverHelp'),
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.6,
                  color: isDark
                      ? NeonPalette.textSecondary
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(width: 12),
        Switch(
          key: const ValueKey('budget-rollover'),
          value: value,
          onChanged: onChanged,
        ),
      ],
    );
  }
}

class _DateButton extends StatelessWidget {
  const _DateButton({
    required this.buttonKey,
    required this.label,
    required this.onPressed,
  });

  final Key buttonKey;
  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: AlignmentDirectional.centerStart,
      child: NeonButton(
        key: buttonKey,
        label: label,
        icon: Icons.event_rounded,
        variant: NeonButtonVariant.outline,
        onPressed: onPressed,
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
            color: isDark
                ? NeonPalette.textSecondary
                : NeonPalette.lightTextSecondary,
          ),
        ),
        const SizedBox(height: 8),
        child,
      ],
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.text, this.accent});

  final String text;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 12,
        height: 1.5,
        color: accent ??
            (isDark
                ? NeonPalette.textMuted
                : NeonPalette.lightTextSecondary),
      ),
    );
  }
}
