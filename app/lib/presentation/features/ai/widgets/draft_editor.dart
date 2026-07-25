import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/i18n/translator.dart';
import '../../../../core/money/money.dart';
import '../../../../core/money/money_formatter.dart';
import '../../../../core/theme/neon_palette.dart';
import '../../../../data/ledger_repository.dart';
import '../../../../domain/ai/ai_models.dart';
import '../../../../domain/entities.dart' as domain;
import '../../../widgets/neon_button.dart';
import '../../../widgets/neon_card.dart';
import '../../../widgets/neon_widgets.dart';
import '../ai_format.dart';
import '../ai_providers.dart';
import 'confidence_badge.dart';
import 'review_banner.dart';

/// The confirmation step every capture path funnels through.
///
/// It is deliberately the only way a draft can reach the ledger: text, voice
/// and receipt capture all end here, so "AI never writes without confirmation"
/// is enforced by there being exactly one door.
class DraftEditor extends ConsumerStatefulWidget {
  const DraftEditor({
    super.key,
    required this.draft,
    this.onSettled,
  });

  final TransactionDraft draft;

  /// Called with true after a save, false after a discard.
  final void Function(bool saved)? onSettled;

  @override
  ConsumerState<DraftEditor> createState() => _DraftEditorState();
}

class _DraftEditorState extends ConsumerState<DraftEditor> {
  late TransactionDraft _draft = widget.draft;
  late final TextEditingController _amount = TextEditingController(
    text: MoneyFormatter.format(
      widget.draft.amount.value,
      showSymbol: false,
      isolate: false,
    ),
  );
  late final TextEditingController _note =
      TextEditingController(text: widget.draft.description ?? '');

  String? _error;
  bool _saving = false;

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Color get _accent => switch (_draft.type.value) {
        domain.TransactionType.income => NeonPalette.income,
        domain.TransactionType.expense => NeonPalette.expense,
        domain.TransactionType.transfer => NeonPalette.transfer,
      };

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _draft.occurredAt.value,
      firstDate: DateTime(2000),
      lastDate: DateTime(2100),
    );
    if (picked == null) return;
    setState(() {
      // A date the user chose is certain by definition.
      _draft = _draft.copyWith(occurredAt: Confident(picked, 1));
    });
  }

  Future<void> _confirm() async {
    final t = ref.read(translatorProvider);
    final edited = Money.tryParse(_amount.text, _draft.amount.value.currency);
    if (edited == null || !edited.isPositive) {
      setState(() => _error = t('tx.amountInvalid'));
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    final note = _note.text.trim();
    final ready = _draft.copyWith(
      amount: Confident(edited, _draft.amount.confidence),
      description: note.isEmpty ? null : note,
    );

    final saved = await recordDraft(ref, ready);
    if (!mounted) return;

    setState(() => _saving = false);

    if (!saved) {
      setState(() => _error = t('common.error'));
      return;
    }

    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(t('tx.saved'))));
    widget.onSettled?.call(true);
  }

  void _dismiss() {
    final t = ref.read(translatorProvider);
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(t('ai.draft.dismissed'))));
    widget.onSettled?.call(false);
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final categories =
        ref.watch(categoriesProvider).valueOrNull ?? const <domain.Category>[];
    final currency = _draft.amount.value.currency;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (_draft.needsReview) ...[
          ReviewBanner(message: t('ai.draft.review')),
          const SizedBox(height: 12),
        ],
        NeonCard(
          accent: _accent,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      t('ai.draft.title'),
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                  ),
                  ConfidenceBadge(confidence: _draft.confidence),
                ],
              ),
              if (_draft.sourceText.isNotEmpty) ...[
                const SizedBox(height: 10),
                Text(
                  '${t('ai.draft.source')}: «${_draft.sourceText}»',
                  style: const TextStyle(
                    fontSize: 12,
                    height: 1.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
              const SizedBox(height: 18),
              _FieldLabel(label: t('ai.draft.type')),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final type in domain.TransactionType.values)
                    NeonChip(
                      label: t('tx.${type.name}'),
                      selected: _draft.type.value == type,
                      accent: switch (type) {
                        domain.TransactionType.income => NeonPalette.income,
                        domain.TransactionType.expense => NeonPalette.expense,
                        domain.TransactionType.transfer => NeonPalette.transfer,
                      },
                      onTap: () => setState(
                        () => _draft = _draft.copyWith(type: Confident(type, 1)),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 18),
              _FieldLabel(
                label: '${t('tx.amount')} · ${t('ai.draft.currency')}',
                trailing: ConfidenceBadge(confidence: _draft.amount.confidence),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: _amount,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9۰-۹٠-٩.,٫٬\s]')),
                ],
                style: TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.w800,
                  color: _accent,
                ),
                decoration: InputDecoration(
                  suffixText: currency.symbol,
                  suffixStyle: const TextStyle(
                    fontSize: 14,
                    color: NeonPalette.textSecondary,
                  ),
                  errorText: _error,
                ),
              ),
              if (_draft.currencyConfidence < kConfidenceFloor) ...[
                const SizedBox(height: 8),
                ConfidenceBadge(confidence: _draft.currencyConfidence),
              ],
              const SizedBox(height: 18),
              _FieldLabel(
                label: t('tx.date'),
                trailing: ConfidenceBadge(
                  confidence: _draft.occurredAt.confidence,
                ),
              ),
              const SizedBox(height: 8),
              NeonChip(
                label: AiFormat.day(_draft.occurredAt.value, locale),
                icon: Icons.event_rounded,
                selected: true,
                accent: NeonPalette.cyan,
                onTap: _pickDate,
              ),
              if (categories.isNotEmpty) ...[
                const SizedBox(height: 18),
                _FieldLabel(
                  label: t('tx.category'),
                  trailing: _draft.categoryId == null
                      ? null
                      : ConfidenceBadge(
                          confidence: _draft.categoryId!.confidence,
                        ),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final category in categories)
                      NeonChip(
                        label: category.name,
                        accent: NeonPalette.forSeed(category.id),
                        selected: _draft.categoryId?.value == category.id,
                        onTap: () => setState(() {
                          _draft = _draft.categoryId?.value == category.id
                              ? _draft.copyWith(clearCategory: true)
                              : _draft.copyWith(
                                  categoryId: Confident(category.id, 1),
                                );
                        }),
                      ),
                  ],
                ),
              ],
              const SizedBox(height: 18),
              _FieldLabel(label: t('tx.note')),
              const SizedBox(height: 8),
              TextField(
                controller: _note,
                decoration: InputDecoration(hintText: t('tx.note')),
              ),
              const SizedBox(height: 18),
              Text(
                t('ai.draft.notice'),
                style: const TextStyle(
                  fontSize: 11.5,
                  height: 1.6,
                  color: NeonPalette.textMuted,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Row(
          children: [
            Expanded(
              child: NeonButton(
                label: t('ai.draft.confirm'),
                icon: Icons.check_rounded,
                accent: _accent,
                expand: true,
                busy: _saving,
                onPressed: _confirm,
              ),
            ),
            const SizedBox(width: 12),
            NeonButton(
              label: t('ai.draft.dismiss'),
              variant: NeonButtonVariant.outline,
              accent: NeonPalette.textSecondary,
              onPressed: _saving ? null : _dismiss,
            ),
          ],
        ),
      ],
    );
  }
}

class _FieldLabel extends StatelessWidget {
  const _FieldLabel({required this.label, this.trailing});

  final String label;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: const TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: NeonPalette.textSecondary,
            ),
          ),
        ),
        if (trailing != null) trailing!,
      ],
    );
  }
}
