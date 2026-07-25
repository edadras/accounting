import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ulid/ulid.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../domain/entities.dart' as domain;
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../ai/ai_hub_screen.dart';

/// Quick capture.
///
/// The roadmap's acceptance criterion is under five seconds and at most three
/// taps, so: the keypad is already up, the type defaults to expense, and the
/// account and category fall back to the most likely choice. Everything except
/// the amount is optional.
class QuickAddSheet extends ConsumerStatefulWidget {
  const QuickAddSheet({super.key});

  static Future<void> show(BuildContext context) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const QuickAddSheet(),
    );
  }

  @override
  ConsumerState<QuickAddSheet> createState() => _QuickAddSheetState();
}

class _QuickAddSheetState extends ConsumerState<QuickAddSheet> {
  final _amountController = TextEditingController();
  final _noteController = TextEditingController();
  final _amountFocus = FocusNode();

  domain.TransactionType _type = domain.TransactionType.expense;
  String? _accountId;
  String? _categoryId;
  String? _error;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _amountFocus.requestFocus());
  }

  @override
  void dispose() {
    _amountController.dispose();
    _noteController.dispose();
    _amountFocus.dispose();
    super.dispose();
  }

  Color get _accent => switch (_type) {
        domain.TransactionType.income => NeonPalette.income,
        domain.TransactionType.expense => NeonPalette.expense,
        domain.TransactionType.transfer => NeonPalette.transfer,
      };

  Future<void> _save() async {
    final t = ref.read(translatorProvider);
    final currency = ref.read(baseCurrencyProvider);
    final accounts = ref.read(accountsProvider).valueOrNull ?? const <domain.Account>[];

    final text = _amountController.text.trim();
    if (text.isEmpty) {
      setState(() => _error = t('tx.amountRequired'));
      return;
    }

    final amount = Money.tryParse(text, currency);
    if (amount == null || !amount.isPositive) {
      setState(() => _error = t('tx.amountInvalid'));
      return;
    }

    final accountId = _accountId ?? accounts.firstOrNull?.id;
    if (accountId == null) {
      setState(() => _error = t('common.error'));
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    // The id is minted here, on the device. An offline record therefore already
    // holds its final identity and sync never has to remap it.
    await ref.read(ledgerRepositoryProvider).record(
          domain.Transaction(
            id: Ulid().toString(),
            type: _type,
            amount: amount,
            baseAmount: amount,
            accountId: accountId,
            categoryId: _type == domain.TransactionType.transfer ? null : _categoryId,
            occurredAt: DateTime.now(),
            description: _noteController.text.trim().isEmpty
                ? null
                : _noteController.text.trim(),
          ),
        );

    ref.read(ledgerRevisionProvider.notifier).state++;

    if (!mounted) return;

    Navigator.of(context).pop();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(t('tx.saved'))),
    );
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final accounts = ref.watch(accountsProvider).valueOrNull ?? const <domain.Account>[];
    final categories = ref.watch(categoriesProvider).valueOrNull ?? const <domain.Category>[];
    final currency = ref.watch(baseCurrencyProvider);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Container(
        decoration: const BoxDecoration(
          color: NeonPalette.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          border: Border(top: BorderSide(color: NeonPalette.hairline)),
        ),
        padding: const EdgeInsetsDirectional.fromSTEB(20, 10, 20, 24),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  margin: const EdgeInsetsDirectional.only(bottom: 18),
                  decoration: BoxDecoration(
                    color: NeonPalette.textMuted,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      t('tx.new'),
                      style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                  ),
                  // The AI capture flows hang off the button people already
                  // press to record money, rather than a tab of their own.
                  // Kept to a text link so the sheet does not get taller.
                  GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: () {
                      final navigator = Navigator.of(context);
                      navigator.pop();
                      navigator.push(AiHubScreen.route());
                    },
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.auto_awesome_rounded,
                            size: 15, color: NeonPalette.violet,),
                        const SizedBox(width: 6),
                        Text(
                          t('ai.entry'),
                          style: const TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: NeonPalette.violet,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  for (final type in domain.TransactionType.values) ...[
                    NeonChip(
                      label: t('tx.${type.name}'),
                      selected: _type == type,
                      accent: switch (type) {
                        domain.TransactionType.income => NeonPalette.income,
                        domain.TransactionType.expense => NeonPalette.expense,
                        domain.TransactionType.transfer => NeonPalette.transfer,
                      },
                      onTap: () => setState(() => _type = type),
                    ),
                    const SizedBox(width: 8),
                  ],
                ],
              ),
              const SizedBox(height: 20),
              TextField(
                controller: _amountController,
                focusNode: _amountFocus,
                // Persian and Arabic digits are accepted too; Money.tryParse
                // normalises them.
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9۰-۹٠-٩.,٫٬\s]')),
                ],
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 34,
                  fontWeight: FontWeight.w800,
                  color: _accent,
                ),
                decoration: InputDecoration(
                  hintText: '0',
                  hintStyle: TextStyle(
                    fontSize: 34,
                    fontWeight: FontWeight.w800,
                    color: _accent.withValues(alpha: 0.25),
                  ),
                  suffixText: currency.symbol,
                  suffixStyle: const TextStyle(
                    fontSize: 15,
                    color: NeonPalette.textSecondary,
                  ),
                  errorText: _error,
                ),
                onSubmitted: (_) => _save(),
              ),
              const SizedBox(height: 18),
              if (accounts.isNotEmpty) ...[
                Text(t('tx.account'), style: _labelStyle),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final account in accounts)
                      NeonChip(
                        label: account.name,
                        accent: NeonPalette.cyan,
                        selected: (_accountId ?? accounts.first.id) == account.id,
                        onTap: () => setState(() => _accountId = account.id),
                      ),
                  ],
                ),
                const SizedBox(height: 16),
              ],
              if (_type != domain.TransactionType.transfer && categories.isNotEmpty) ...[
                Text(t('tx.category'), style: _labelStyle),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final category in categories)
                      NeonChip(
                        label: category.name,
                        accent: NeonPalette.forSeed(category.id),
                        selected: _categoryId == category.id,
                        onTap: () => setState(() => _categoryId = category.id),
                      ),
                  ],
                ),
                const SizedBox(height: 16),
              ],
              TextField(
                controller: _noteController,
                decoration: InputDecoration(hintText: t('tx.note')),
                textInputAction: TextInputAction.done,
                onSubmitted: (_) => _save(),
              ),
              const SizedBox(height: 20),
              NeonButton(
                label: t('tx.save'),
                icon: Icons.check_rounded,
                accent: _accent,
                expand: true,
                busy: _saving,
                onPressed: _save,
              ),
            ],
          ),
        ),
      ),
    );
  }

  static const _labelStyle = TextStyle(
    fontSize: 12,
    fontWeight: FontWeight.w600,
    color: NeonPalette.textSecondary,
  );
}
