import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/currency.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart'
    show accountsProvider, baseCurrencyProvider, categoriesProvider;
import '../../../data/modules_repository.dart' show clockProvider;
import '../../../data/recurring_repository.dart';
import '../../../data/remote/money_codec.dart';
import '../../../domain/entities.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'recurring_presentation.dart';
import 'recurring_providers.dart';

/// Write a standing instruction, or change one.
///
/// `PATCH /recurring-rules/{rule}` takes the template and the schedule as well
/// as the flags, so editing is the same form as creating and every control is
/// real. The rule keeps its id and its posting history, which is the whole
/// reason changing an amount is no longer a delete and a rebuild.
///
/// One asymmetry survives, and it belongs to the server rather than the form:
/// moving the start date only moves the next run on a rule that has not posted
/// yet. A rule already under way keeps its cursor, so the field says so.
class RecurringRuleEditorScreen extends ConsumerStatefulWidget {
  const RecurringRuleEditorScreen({super.key, this.existing});

  final RecurringRule? existing;

  static Route<void> route({RecurringRule? existing}) =>
      MaterialPageRoute<void>(
        builder: (_) => RecurringRuleEditorScreen(existing: existing),
      );

  @override
  ConsumerState<RecurringRuleEditorScreen> createState() =>
      _RecurringRuleEditorScreenState();
}

class _RecurringRuleEditorScreenState
    extends ConsumerState<RecurringRuleEditorScreen> {
  late final TextEditingController _name;
  late final TextEditingController _amount;
  late final TextEditingController _description;
  late final TextEditingController _payee;

  late TransactionType _type;
  String? _accountId;
  String? _counterAccountId;
  String? _categoryId;
  late Currency _currency;

  late RecurringFrequency _frequency;
  late int _interval;
  int? _dayOfMonth;

  /// 0 = Sunday, the API's own basis. Null keeps the weekday the rule started
  /// on.
  int? _dayOfWeek;
  late DateTime _startsAt;
  DateTime? _endsAt;
  late bool _autoPost;
  late bool _isPaused;

  bool _busy = false;

  /// Always a translation key, never a sentence from the server.
  String? _errorKey;

  bool get _isEditing => widget.existing != null;

  /// True when the rule points at an account that is not in the list.
  ///
  /// Selecting nothing would be a lie: the id is still stored and is resent
  /// unchanged on save, so the account has not been cleared — it just cannot
  /// be named here.
  bool _unknownAccount(List<Account> accounts) {
    final id = _accountId;

    return id != null && accounts.every((account) => account.id != id);
  }

  @override
  void initState() {
    super.initState();

    final existing = widget.existing;
    final template = existing?.template;

    _name = TextEditingController(text: existing?.name ?? '');
    _description = TextEditingController(text: template?.description ?? '');
    _payee = TextEditingController(text: template?.payee ?? '');
    _currency = template?.amount.currency ?? ref.read(baseCurrencyProvider);
    _amount = TextEditingController(
      text: template == null ? '' : MoneyCodec.decimalString(template.amount),
    );

    _type = template?.type ?? TransactionType.expense;
    _accountId = template?.accountId;
    _counterAccountId = template?.counterAccountId;
    _categoryId = template?.categoryId;

    _frequency = existing?.frequency ?? RecurringFrequency.monthly;
    _interval = existing?.interval ?? 1;
    _dayOfMonth = existing?.dayOfMonth;
    _dayOfWeek = existing?.dayOfWeek;
    _startsAt = existing?.startsAt ?? ref.read(clockProvider);
    _endsAt = existing?.endsAt;
    _autoPost = existing?.autoPost ?? true;
    _isPaused = existing?.isPaused ?? false;
  }

  @override
  void dispose() {
    _name.dispose();
    _amount.dispose();
    _description.dispose();
    _payee.dispose();
    super.dispose();
  }

  Future<void> _pickDate({required bool isStart}) async {
    final picked = await showDatePicker(
      context: context,
      initialDate: isStart ? _startsAt : (_endsAt ?? _startsAt),
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;

    setState(() {
      if (isStart) {
        _startsAt = picked;
      } else {
        _endsAt = picked;
      }
      _errorKey = null;
    });
  }

  Future<void> _save() async {
    final validation = _validate();
    if (validation != null) {
      setState(() => _errorKey = validation);
      return;
    }

    setState(() {
      _busy = true;
      _errorKey = null;
    });

    try {
      final repository = ref.read(recurringRepositoryProvider);
      final existing = widget.existing;

      if (existing != null) {
        await repository.save(_draft(id: existing.id));
      } else {
        await repository.create(_draft(id: ''));
      }

      ref.invalidate(recurringRulesProvider);
      if (!mounted) return;
      Navigator.of(context).pop();
    } on RecurringRuleException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorKey = error.translationKey;
        _busy = false;
      });
    }
  }

  /// A translation key, or null when the draft is good enough to send.
  ///
  /// The same checks either way: an edit now carries the whole rule, so it can
  /// break it in exactly the ways a new one can.
  String? _validate() {
    if (_accountId == null || _accountId!.isEmpty) {
      return 'recurring.accountRequired';
    }

    // The ledger refuses a transfer without a destination, and refuses one back
    // into the same account. A rule missing either would post nothing,
    // silently, forever.
    if (_type == TransactionType.transfer) {
      if (_counterAccountId == null || _counterAccountId!.isEmpty) {
        return 'recurring.counterRequired';
      }
      if (_counterAccountId == _accountId) {
        return 'recurring.counterSameAccount';
      }
    }

    final amount = Money.tryParse(_amount.text, _currency);
    if (amount == null || amount.minorUnits <= 0) return 'tx.amountInvalid';

    if (_endsAt != null && _endsAt!.isBefore(_startsAt)) {
      return 'recurring.endsBeforeStart';
    }

    return null;
  }

  RecurringRule _draft({required String id}) => RecurringRule(
        id: id,
        name: _name.text.trim().isEmpty ? null : _name.text.trim(),
        template: RecurringTemplate(
          type: _type,
          accountId: _accountId ?? '',
          // Already proven parseable and positive by _validate(); the fallback
          // exists only so this expression has no nullable branch.
          amount:
              Money.tryParse(_amount.text, _currency) ?? Money(0, _currency),
          counterAccountId:
              _type == TransactionType.transfer ? _counterAccountId : null,
          categoryId: _categoryId,
          description: _description.text.trim(),
          payee: _payee.text.trim(),
          // Carried through untouched. No control here edits tags, and a
          // template sent without them replaces the stored one.
          tags: widget.existing?.template.tags ?? const [],
        ),
        frequency: _frequency,
        interval: _interval,
        dayOfMonth: _frequency.usesDayOfMonth ? _dayOfMonth : null,
        dayOfWeek: _frequency.usesDayOfWeek ? _dayOfWeek : null,
        startsAt: _startsAt,
        endsAt: _endsAt,
        autoPost: _autoPost,
        isPaused: _isPaused,
      );

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);

    return ModulePage(
      title: _isEditing ? t('recurring.editTitle') : t('recurring.newTitle'),
      subtitle: t('recurring.subtitle'),
      child: _form(t),
    );
  }

  // -------------------------------------------------------------------- form

  Widget _form(Translator t) {
    final accounts = ref.watch(accountsProvider);

    return accounts.when(
      loading: () => const ModuleLoading(),
      // Editing does not actually need the account list: every field was
      // populated from the rule itself, and the account id is resent
      // unchanged. Refusing the whole screen would take away renaming and
      // pausing — the two things most likely to be wanted when something
      // else is already failing.
      error: (error, _) => _isEditing
          ? _reducedForm(t)
          : noticeForFailure(
              error,
              t: t,
              permissionTitle: t('recurring.noAccessTitle'),
              permissionBody: t('recurring.noAccessBody'),
            ),
      data: (list) => list.isEmpty
          // Nothing to post into. Saying so beats a form whose only required
          // field cannot be filled.
          ? NoticePanel(
              icon: Icons.account_balance_wallet_rounded,
              title: t('recurring.noAccounts'),
              body: t('recurring.noAccountsBody'),
            )
          : _body(t, list),
    );
  }

  /// What can still be edited when the account list will not load.
  ///
  /// Deliberately the same four fields the API accepted before the schedule
  /// and template became editable, because they are exactly the ones that do
  /// not depend on knowing which accounts exist.
  Widget _reducedForm(Translator t) {
    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NoticePanel(
          icon: Icons.cloud_off_rounded,
          title: t('recurring.reducedTitle'),
          body: t('recurring.reducedBody'),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.name')),
        TextField(
          key: const ValueKey('recurring-name'),
          controller: _name,
          decoration: InputDecoration(hintText: t('recurring.nameHint')),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.endsAt')),
        _DateField(
          fieldKey: 'recurring-ends',
          value: _endsAt == null
              ? t('recurring.endsNever')
              : DateFormatter.short(_endsAt!, ref.watch(localeProvider)),
          onPick: () => _pickDate(isStart: false),
          onClear: _endsAt == null ? null : () => setState(() => _endsAt = null),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.behaviour')),
        _SwitchRow(
          rowKey: 'recurring-auto-post',
          label: t('recurring.autoPost'),
          note: t('recurring.autoPostNote'),
          value: _autoPost,
          onChanged: (value) => setState(() => _autoPost = value),
        ),
        _SwitchRow(
          rowKey: 'recurring-paused',
          label: t('recurring.paused'),
          note: t('recurring.pausedNote'),
          value: _isPaused,
          onChanged: (value) => setState(() => _isPaused = value),
        ),
        const SizedBox(height: 26),
        NeonButton(
          key: const ValueKey('recurring-save'),
          label: t('recurring.save'),
          icon: Icons.save_rounded,
          expand: true,
          busy: _busy,
          onPressed: _busy ? null : _save,
        ),
        if (_errorKey != null) _InlineError(message: t(_errorKey!)),
      ],
    );
  }

  Widget _body(Translator t, List<Account> accounts) {
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final categories = ref.watch(categoriesProvider);

    // Lazy default rather than a setState: the first account is the one the
    // form opens on, and it decides the currency until the user says otherwise.
    if (_accountId == null) {
      _accountId = accounts.first.id;
      _currency = accounts.first.currency;
    }

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        SectionHeader(title: t('recurring.name')),
        TextField(
          key: const ValueKey('recurring-name'),
          controller: _name,
          decoration: InputDecoration(hintText: t('recurring.nameHint')),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.transactionType')),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final type in TransactionType.values)
              NeonChip(
                key: ValueKey('recurring-type-${type.name}'),
                label: t(transactionTypeKey(type)),
                accent: recurringAccentFor(
                  transactionAccent(type),
                  isDark: isDark,
                ),
                selected: _type == type,
                onTap: () => setState(() => _type = type),
              ),
          ],
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('tx.account')),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            // A rule can point at an account this list does not contain — one
            // that was archived, or that belongs to a workspace the caller can
            // no longer see. The id is preserved and resent either way, so the
            // chip exists to say that out loud; without it the row simply
            // renders with nothing selected and looks like a form the user
            // forgot to fill in.
            if (_unknownAccount(accounts))
              NeonChip(
                key: const ValueKey('recurring-account-unknown'),
                label: t('recurring.accountUnavailable'),
                accent: recurringAccentFor(NeonPalette.amber, isDark: isDark),
                selected: true,
                icon: Icons.help_outline_rounded,
              ),
            for (final account in accounts)
              NeonChip(
                key: ValueKey('recurring-account-${account.id}'),
                label: account.name,
                accent: recurringAccentFor(NeonPalette.cyan, isDark: isDark),
                selected: _accountId == account.id,
                onTap: () => setState(() {
                  _accountId = account.id;
                  _currency = account.currency;
                }),
              ),
          ],
        ),
        if (_unknownAccount(accounts)) ...[
          const SizedBox(height: 8),
          Text(
            t('recurring.accountUnavailableHint'),
            style: const TextStyle(fontSize: 11.5, height: 1.4, color: NeonPalette.textMuted),
          ),
        ],
        if (_type == TransactionType.transfer) ...[
          const SizedBox(height: 20),
          SectionHeader(title: t('recurring.counterAccount')),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final account in accounts)
                if (account.id != _accountId)
                  NeonChip(
                    key: ValueKey('recurring-counter-${account.id}'),
                    label: account.name,
                    accent:
                        recurringAccentFor(NeonPalette.violet, isDark: isDark),
                    selected: _counterAccountId == account.id,
                    onTap: () =>
                        setState(() => _counterAccountId = account.id),
                  ),
            ],
          ),
        ],
        const SizedBox(height: 20),
        SectionHeader(title: t('tx.amount')),
        TextField(
          key: const ValueKey('recurring-amount'),
          controller: _amount,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            hintText: t('recurring.amountHint'),
            suffixText: _currency.code,
          ),
        ),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final currency in Currency.all)
              NeonChip(
                key: ValueKey('recurring-currency-${currency.code}'),
                label: currency.code,
                accent: recurringAccentFor(NeonPalette.lime, isDark: isDark),
                selected: _currency == currency,
                onTap: () => setState(() => _currency = currency),
              ),
          ],
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('tx.category')),
        categories.when(
          loading: () => const ModuleLoading(),
          error: (_, __) => _Note(text: t('recurring.categoriesUnavailable')),
          data: (list) => Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              NeonChip(
                key: const ValueKey('recurring-category-none'),
                label: t('recurring.categoryNone'),
                accent: recurringAccentFor(NeonPalette.cyan, isDark: isDark),
                selected: _categoryId == null,
                onTap: () => setState(() => _categoryId = null),
              ),
              for (final category in list)
                NeonChip(
                  key: ValueKey('recurring-category-${category.id}'),
                  label: category.name,
                  accent: recurringAccentFor(NeonPalette.cyan, isDark: isDark),
                  selected: _categoryId == category.id,
                  onTap: () => setState(() => _categoryId = category.id),
                ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('tx.note')),
        TextField(
          key: const ValueKey('recurring-description'),
          controller: _description,
          decoration: InputDecoration(hintText: t('recurring.noteHint')),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('recurring-payee'),
          controller: _payee,
          decoration: InputDecoration(hintText: t('recurring.payeeHint')),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.frequency')),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final frequency in RecurringFrequency.values)
              NeonChip(
                key: ValueKey('recurring-freq-${frequency.wire}'),
                label: t(frequencyKey(frequency)),
                accent: recurringAccentFor(NeonPalette.amber, isDark: isDark),
                selected: _frequency == frequency,
                onTap: () => setState(() => _frequency = frequency),
              ),
          ],
        ),
        const SizedBox(height: 16),
        SectionHeader(title: t('recurring.interval')),
        _Stepper(
          valueKey: 'recurring-interval',
          value: scheduleSummary(
            _frequency,
            _interval,
            null,
            t: t,
            locale: locale,
          ),
          onDecrease:
              _interval > 1 ? () => setState(() => _interval -= 1) : null,
          onIncrease:
              _interval < 365 ? () => setState(() => _interval += 1) : null,
        ),
        if (_frequency.usesDayOfMonth) ...[
          const SizedBox(height: 20),
          SectionHeader(title: t('recurring.dayOfMonth')),
          _Stepper(
            valueKey: 'recurring-day',
            value: _dayOfMonth == null
                ? t('recurring.dayFromStart')
                : t(
                    'recurring.onDay',
                    args: {
                      'day': DateFormatter.number(_dayOfMonth!, locale.code),
                    },
                  ),
            onDecrease: _dayOfMonth == null
                ? null
                : () => setState(() {
                      final next = _dayOfMonth! - 1;
                      _dayOfMonth = next < 1 ? null : next;
                    }),
            onIncrease: (_dayOfMonth ?? 0) < 31
                ? () => setState(() => _dayOfMonth = (_dayOfMonth ?? 0) + 1)
                : null,
          ),
          const SizedBox(height: 8),
          _Note(text: t('recurring.dayOfMonthNote')),
        ],
        if (_frequency.usesDayOfWeek) ...[
          const SizedBox(height: 20),
          SectionHeader(title: t('recurring.dayOfWeek')),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              NeonChip(
                key: const ValueKey('recurring-weekday-none'),
                label: t('recurring.weekdayFromStart'),
                accent: recurringAccentFor(NeonPalette.amber, isDark: isDark),
                selected: _dayOfWeek == null,
                onTap: () => setState(() => _dayOfWeek = null),
              ),
              // Sunday first, because the wire is 0-based on Sunday and a chip
              // row that started elsewhere would put the labels out of step
              // with the number being sent.
              for (var day = 0; day < 7; day++)
                NeonChip(
                  key: ValueKey('recurring-weekday-$day'),
                  label: t(weekdayKey(day)),
                  accent: recurringAccentFor(NeonPalette.amber, isDark: isDark),
                  selected: _dayOfWeek == day,
                  onTap: () => setState(() => _dayOfWeek = day),
                ),
            ],
          ),
          const SizedBox(height: 8),
          _Note(text: t('recurring.weeklyNote')),
        ],
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.startsAt')),
        _DateField(
          fieldKey: 'recurring-starts',
          value: DateFormatter.short(_startsAt, locale),
          onPick: () => _pickDate(isStart: true),
          onClear: null,
        ),
        if (_isEditing) ...[
          const SizedBox(height: 8),
          _Note(text: t('recurring.startsAtNote')),
        ],
        const SizedBox(height: 16),
        SectionHeader(title: t('recurring.endsAt')),
        _DateField(
          fieldKey: 'recurring-ends',
          value: _endsAt == null
              ? t('recurring.endsNever')
              : DateFormatter.short(_endsAt!, locale),
          onPick: () => _pickDate(isStart: false),
          onClear:
              _endsAt == null ? null : () => setState(() => _endsAt = null),
        ),
        const SizedBox(height: 20),
        SectionHeader(title: t('recurring.behaviour')),
        _SwitchRow(
          rowKey: 'recurring-auto-post',
          label: t('recurring.autoPost'),
          note: t('recurring.autoPostNote'),
          value: _autoPost,
          onChanged: (value) => setState(() => _autoPost = value),
        ),
        // A rule that does not exist yet cannot be paused, and offering the
        // switch would only ask the user to create something already stopped.
        if (_isEditing)
          _SwitchRow(
            rowKey: 'recurring-paused',
            label: t('recurring.paused'),
            note: t('recurring.pausedNote'),
            value: _isPaused,
            onChanged: (value) => setState(() => _isPaused = value),
          ),
        const SizedBox(height: 26),
        NeonButton(
          key: const ValueKey('recurring-save'),
          label: t('recurring.save'),
          icon: Icons.save_rounded,
          expand: true,
          busy: _busy,
          onPressed: _busy ? null : _save,
        ),
        if (_errorKey != null) _InlineError(message: t(_errorKey!)),
      ],
    );
  }
}

class _DateField extends StatelessWidget {
  const _DateField({
    required this.fieldKey,
    required this.value,
    required this.onPick,
    required this.onClear,
  });

  final String fieldKey;
  final String value;
  final VoidCallback onPick;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = isDark ? NeonPalette.cyan : NeonPalette.lightCyan;

    return Row(
      children: [
        Expanded(
          child: GestureDetector(
            key: ValueKey(fieldKey),
            onTap: onPick,
            child: Container(
              padding: const EdgeInsetsDirectional.symmetric(
                horizontal: 14,
                vertical: 13,
              ),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
                border: NeonEffects.border(accent, alpha: 0.30),
              ),
              child: Row(
                children: [
                  Icon(Icons.event_rounded, size: 16, color: accent),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      value,
                      style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w600,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        if (onClear != null) ...[
          const SizedBox(width: 8),
          Semantics(
            button: true,
            child: GestureDetector(
              key: ValueKey('$fieldKey-clear'),
              onTap: onClear,
              child: Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
                  border: NeonEffects.border(
                    isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
                    alpha: 0.30,
                  ),
                ),
                child: Icon(
                  Icons.close_rounded,
                  size: 17,
                  color:
                      isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
                ),
              ),
            ),
          ),
        ],
      ],
    );
  }
}

/// A bounded number the user nudges rather than types.
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
              fontSize: 13.5,
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

class _SwitchRow extends StatelessWidget {
  const _SwitchRow({
    required this.rowKey,
    required this.label,
    required this.note,
    required this.value,
    required this.onChanged,
  });

  final String rowKey;
  final String label;
  final String note;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  note,
                  style: TextStyle(
                    fontSize: 11,
                    height: 1.5,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Switch(key: ValueKey(rowKey), value: value, onChanged: onChanged),
        ],
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
        color: isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
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
        key: const ValueKey('recurring-error'),
        style: TextStyle(
          fontSize: 12.5,
          height: 1.5,
          color: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
        ),
      ),
    );
  }
}
