import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/ai/ai_models.dart';
import '../../../domain/entities.dart' as domain;
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'ai_format.dart';
import 'ai_providers.dart';
import 'widgets/confidence_badge.dart';
import 'widgets/draft_editor.dart';
import 'widgets/review_banner.dart';

/// What OCR read off a receipt, laid out so a human can check it in seconds.
///
/// Two things are non-negotiable here: a field the model is unsure about is
/// visibly flagged, and a receipt whose lines do not add up to its total says
/// so in words. Hiding either would make the screen look better and the data
/// worse.
class ReceiptReviewScreen extends ConsumerStatefulWidget {
  const ReceiptReviewScreen({
    super.key,
    this.reference = 'sample',
    this.receipt,
  });

  /// Handle for the captured image. The fake OCR keys its fixture off it.
  final String reference;

  /// Pre-extracted result, for callers (and tests) that already have one.
  final ReceiptDraft? receipt;

  static Route<void> route({String reference = 'sample'}) =>
      MaterialPageRoute<void>(
        builder: (_) => ReceiptReviewScreen(reference: reference),
      );

  @override
  ConsumerState<ReceiptReviewScreen> createState() => _ReceiptReviewScreenState();
}

class _ReceiptReviewScreenState extends ConsumerState<ReceiptReviewScreen> {
  ReceiptDraft? _receipt;
  TransactionDraft? _draft;

  @override
  void initState() {
    super.initState();
    if (widget.receipt != null) {
      _receipt = widget.receipt;
    } else {
      unawaited(_load());
    }
  }

  Future<void> _load() async {
    final receipt =
        await ref.read(aiRepositoryProvider).extractReceipt(widget.reference);
    if (!mounted) return;
    setState(() => _receipt = receipt);
  }

  void _toDraft(ReceiptDraft receipt) {
    setState(() {
      _draft = TransactionDraft(
        sourceText: receipt.merchant.value,
        type: const Confident(domain.TransactionType.expense, 0.95),
        amount: receipt.total,
        occurredAt: receipt.occurredAt,
        currencyConfidence: 0.95,
        description: receipt.merchant.value,
      );
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final receipt = _receipt;

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(
          title: Text(t('ai.receipt.title')),
          actions: [
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 12),
              child: NeonChip(label: t('ai.simulated'), accent: NeonPalette.amber),
            ),
          ],
        ),
        body: receipt == null
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 32),
                children: [
                  if (!receipt.balances) ...[
                    ReviewBanner(
                      message: t(
                        'ai.receipt.mismatch',
                        args: {
                          'amount': MoneyFormatter.format(
                            receipt.discrepancy.absolute,
                            locale: locale,
                          ),
                        },
                      ),
                    ),
                    const SizedBox(height: 14),
                  ],
                  NeonCard(
                    accent: NeonPalette.violet,
                    child: Column(
                      children: [
                        _Field(
                          label: t('ai.receipt.merchant'),
                          value: receipt.merchant.value,
                          confidence: receipt.merchant.confidence,
                        ),
                        const _Rule(),
                        _Field(
                          label: t('tx.date'),
                          value: AiFormat.day(receipt.occurredAt.value, locale),
                          confidence: receipt.occurredAt.confidence,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  SectionHeader(
                    title: t('ai.receipt.items'),
                    accent: NeonPalette.cyan,
                  ),
                  NeonCard(
                    child: Column(
                      children: [
                        for (final line in receipt.lines) ...[
                          _LineRow(line: line, locale: locale),
                          if (line != receipt.lines.last) const _Rule(),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  NeonCard(
                    accent: receipt.balances
                        ? NeonPalette.cyan
                        : NeonPalette.amber,
                    child: Column(
                      children: [
                        _Field(
                          label: t('ai.receipt.itemsTotal'),
                          value: MoneyFormatter.format(
                            receipt.lineTotal,
                            locale: locale,
                          ),
                        ),
                        const _Rule(),
                        _Field(
                          label: t('ai.receipt.tax'),
                          value:
                              MoneyFormatter.format(receipt.tax.value, locale: locale),
                          confidence: receipt.tax.confidence,
                        ),
                        const _Rule(),
                        _Field(
                          label: t('ai.receipt.total'),
                          value: MoneyFormatter.format(
                            receipt.total.value,
                            locale: locale,
                          ),
                          confidence: receipt.total.confidence,
                          emphasis: true,
                        ),
                        const SizedBox(height: 12),
                        Align(
                          alignment: AlignmentDirectional.centerStart,
                          child: Text(
                            receipt.balances
                                ? t('ai.receipt.balanced')
                                : t(
                                    'ai.receipt.mismatch',
                                    args: {
                                      'amount': MoneyFormatter.format(
                                        receipt.discrepancy.absolute,
                                        locale: locale,
                                      ),
                                    },
                                  ),
                            style: TextStyle(
                              fontSize: 11.5,
                              height: 1.6,
                              color: receipt.balances
                                  ? NeonPalette.textMuted
                                  : NeonPalette.amber,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 20),
                  if (_draft == null)
                    NeonButton(
                      label: t('ai.capture.action'),
                      icon: Icons.auto_awesome_rounded,
                      accent: NeonPalette.violet,
                      expand: true,
                      onPressed: () => _toDraft(receipt),
                    )
                  else
                    DraftEditor(
                      draft: _draft!,
                      onSettled: (saved) {
                        if (!mounted) return;
                        if (saved) {
                          Navigator.of(context).pop();
                        } else {
                          setState(() => _draft = null);
                        }
                      },
                    ),
                ],
              ),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({
    required this.label,
    required this.value,
    this.confidence,
    this.emphasis = false,
  });

  final String label;
  final String value;
  final double? confidence;
  final bool emphasis;

  @override
  Widget build(BuildContext context) {
    final uncertain = confidence != null && confidence! < kConfidenceFloor;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: NeonPalette.textSecondary,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  value,
                  style: TextStyle(
                    fontSize: emphasis ? 18 : 14,
                    fontWeight: emphasis ? FontWeight.w800 : FontWeight.w600,
                    color: uncertain
                        ? NeonPalette.amber
                        : NeonPalette.textPrimary,
                  ),
                ),
              ],
            ),
          ),
          if (confidence != null) ...[
            const SizedBox(width: 10),
            ConfidenceBadge(confidence: confidence!),
          ],
        ],
      ),
    );
  }
}

class _LineRow extends StatelessWidget {
  const _LineRow({required this.line, required this.locale});

  final ReceiptLine line;
  final String locale;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 9),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  line.label,
                  style: const TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${AiFormat.digits(line.quantity.toString(), locale)} × '
                  '${MoneyFormatter.format(line.unitPrice, locale: locale)}',
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          Text(
            MoneyFormatter.format(line.total, locale: locale),
            style: const TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color: NeonPalette.cyan,
            ),
          ),
        ],
      ),
    );
  }
}

class _Rule extends StatelessWidget {
  const _Rule();

  @override
  Widget build(BuildContext context) {
    return Container(
      height: 1,
      color: NeonPalette.cyan.withValues(alpha: 0.08),
    );
  }
}
