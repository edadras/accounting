import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/currency.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/family_repository.dart';
import '../../../data/ledger_repository.dart';
import '../../../data/remote/money_codec.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'family_labels.dart';
import 'family_providers.dart';

/// Add somebody to the household, or change what they are given.
///
/// The currency is only asked for when adding: `FamilyMemberController::update`
/// does not accept one, because re-denominating a member would silently
/// re-price allowances that have already been paid.
class MemberFormScreen extends ConsumerStatefulWidget {
  const MemberFormScreen({super.key, this.existing});

  final HouseholdMember? existing;

  /// Pops with `true` when something was written.
  static Route<bool> route({HouseholdMember? existing}) =>
      MaterialPageRoute<bool>(
        builder: (_) => MemberFormScreen(existing: existing),
      );

  @override
  ConsumerState<MemberFormScreen> createState() => _MemberFormScreenState();
}

class _MemberFormScreenState extends ConsumerState<MemberFormScreen> {
  late final TextEditingController _name =
      TextEditingController(text: widget.existing?.displayName ?? '');

  late final TextEditingController _allowance = TextEditingController(
    text: _amountText(widget.existing?.monthlyAllowance),
  );

  late final TextEditingController _cap = TextEditingController(
    text: _amountText(widget.existing?.spendingCap),
  );

  late FamilyRole _role = widget.existing?.role ?? FamilyRole.child;
  late Currency? _currency = widget.existing?.currency;
  late String? _accountId = widget.existing?.accountId;

  bool _busy = false;
  String? _errorKey;
  String? _validationKey;

  bool get _isEdit => widget.existing != null;

  static String _amountText(Money? money) =>
      money == null ? '' : MoneyCodec.decimalString(money);

  @override
  void dispose() {
    _name.dispose();
    _allowance.dispose();
    _cap.dispose();
    super.dispose();
  }

  /// The first thing wrong with the form, as a translation key, or null.
  String? _validate({
    required String name,
    required String allowanceText,
    required Money? allowance,
    required String capText,
    required Money? cap,
  }) {
    if (name.isEmpty) return 'family.nameRequired';
    if (allowanceText.isNotEmpty && allowance == null) {
      return 'family.allowanceInvalid';
    }
    if (capText.isNotEmpty && cap == null) return 'family.capInvalid';
    return null;
  }

  Future<void> _save() async {
    final Currency currency = _currency ?? ref.read(baseCurrencyProvider);
    final name = _name.text.trim();

    // An empty field means "no ceiling", which is not the same as a ceiling of
    // zero — one leaves the member unmeasured, the other forbids them
    // everything.
    final allowanceText = _allowance.text.trim();
    final capText = _cap.text.trim();

    final allowance =
        allowanceText.isEmpty ? null : Money.tryParse(allowanceText, currency);
    final cap = capText.isEmpty ? null : Money.tryParse(capText, currency);

    final validation = _validate(
      name: name,
      allowanceText: allowanceText,
      allowance: allowance,
      capText: capText,
      cap: cap,
    );

    if (validation != null) {
      setState(() {
        _validationKey = validation;
        _errorKey = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _validationKey = null;
      _errorKey = null;
    });

    final repository = ref.read(familyRepositoryProvider);
    final existing = widget.existing;

    try {
      if (existing == null) {
        await repository.addMember(
          displayName: name,
          role: _role,
          currency: currency,
          monthlyAllowance: allowance,
          spendingCap: cap,
          accountId: _accountId,
        );
      } else {
        await repository.updateMember(
          existing.id,
          displayName: name,
          role: _role,
          monthlyAllowance: allowance,
          spendingCap: cap,
          clearAllowance: allowance == null,
          clearCap: cap == null,
          accountId: _accountId,
        );
      }

      ref.invalidate(householdProvider);

      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on FamilyFailure catch (error) {
      if (!mounted) return;
      setState(() {
        _errorKey = error.translationKey;
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final accounts = ref.watch(accountsProvider);
    final Currency currency = _currency ?? ref.watch(baseCurrencyProvider);

    return ModulePage(
      title: t(_isEdit ? 'family.editTitle' : 'family.addTitle'),
      subtitle: t(_isEdit ? 'family.editSubtitle' : 'family.addSubtitle'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
        children: [
          _Field(
            label: t('family.name'),
            child: TextField(
              key: const ValueKey('member-name'),
              controller: _name,
              textInputAction: TextInputAction.next,
            ),
          ),
          const SizedBox(height: 18),
          _Field(
            label: t('family.role'),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final role in FamilyRole.values)
                  NeonChip(
                    key: ValueKey('member-role-${role.name}'),
                    label: familyRoleLabel(t, role),
                    accent: NeonPalette.cyan,
                    selected: role == _role,
                    onTap: () => setState(() => _role = role),
                  ),
              ],
            ),
          ),
          if (!_isEdit) ...[
            const SizedBox(height: 18),
            _Field(
              label: t('family.currency'),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final option in Currency.all)
                    NeonChip(
                      key: ValueKey('member-currency-${option.code}'),
                      label: option.code,
                      accent: NeonPalette.violet,
                      selected: option == currency,
                      onTap: () => setState(() => _currency = option),
                    ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 18),
          _Field(
            label: t('family.allowanceIn', args: {'currency': currency.code}),
            child: _AmountField(
              fieldKey: const ValueKey('member-allowance'),
              controller: _allowance,
              hint: t('family.optional'),
            ),
          ),
          const SizedBox(height: 18),
          _Field(
            label: t('family.capIn', args: {'currency': currency.code}),
            child: _AmountField(
              fieldKey: const ValueKey('member-cap'),
              controller: _cap,
              hint: t('family.optional'),
            ),
          ),
          const SizedBox(height: 18),
          _Field(
            label: t('family.account'),
            child: accounts.when(
              loading: () => const ModuleLoading(),
              error: (_, __) => _Note(text: t('family.accountsUnavailable')),
              data: (list) => Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final account in list)
                    NeonChip(
                      key: ValueKey('member-account-${account.id}'),
                      label: account.name,
                      accent: NeonPalette.lime,
                      selected: account.id == _accountId,
                      onTap: () => setState(() => _accountId = account.id),
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 8),
          _Note(text: t('family.accountHelp')),
          if (_validationKey != null || _errorKey != null) ...[
            const SizedBox(height: 16),
            _Note(
              text: t(_validationKey ?? _errorKey!),
              accent: NeonPalette.magenta,
            ),
          ],
          const SizedBox(height: 24),
          NeonButton(
            key: const ValueKey('member-save'),
            label: t(_isEdit ? 'family.saveChanges' : 'family.addMember'),
            icon: Icons.check_rounded,
            expand: true,
            busy: _busy,
            onPressed: _busy ? null : _save,
          ),
        ],
      ),
    );
  }
}

class _AmountField extends StatelessWidget {
  const _AmountField({
    required this.fieldKey,
    required this.controller,
    required this.hint,
  });

  final Key fieldKey;
  final TextEditingController controller;
  final String hint;

  @override
  Widget build(BuildContext context) {
    return TextField(
      key: fieldKey,
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: true),
      inputFormatters: [
        // Digits in any of the three shapes the app accepts, plus separators.
        // Money.tryParse remains the authority on what is a valid amount.
        FilteringTextInputFormatter.allow(RegExp(r'[0-9۰-۹٠-٩.,٫٬\s]')),
      ],
      decoration: InputDecoration(hintText: hint),
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
        fontSize: 11.5,
        height: 1.5,
        color: accent ??
            (isDark
                ? NeonPalette.textMuted
                : NeonPalette.lightTextSecondary),
      ),
    );
  }
}
