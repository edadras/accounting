import 'package:flutter/material.dart';

import '../../core/money/money_formatter.dart';
import '../../core/theme/neon_effects.dart';
import '../../core/theme/neon_palette.dart';
import '../../domain/entities.dart';

/// One line in the transaction list.
///
/// The sign and the colour carry the meaning: green-lime in, magenta out, cyan
/// moved. Reading the row should not require reading the number.
class TransactionRow extends StatelessWidget {
  const TransactionRow({
    super.key,
    required this.transaction,
    required this.locale,
    this.categoryLabel,
    this.accountLabel,
    this.onTap,
  });

  final Transaction transaction;
  final String locale;
  final String? categoryLabel;
  final String? accountLabel;
  final VoidCallback? onTap;

  Color get _accent => switch (transaction.type) {
        TransactionType.income => NeonPalette.income,
        TransactionType.expense => NeonPalette.expense,
        TransactionType.transfer => NeonPalette.transfer,
      };

  IconData get _icon => switch (transaction.type) {
        TransactionType.income => Icons.south_west_rounded,
        TransactionType.expense => Icons.north_east_rounded,
        TransactionType.transfer => Icons.swap_horiz_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final title = transaction.description?.trim().isNotEmpty == true
        ? transaction.description!
        : (categoryLabel ?? '—');

    final signed = transaction.type == TransactionType.transfer
        ? MoneyFormatter.format(transaction.amount, locale: locale)
        : MoneyFormatter.formatSigned(
            transaction.type == TransactionType.expense
                ? transaction.amount.negated
                : transaction.amount,
            locale: locale,
          );

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(NeonEffects.radiusMd),
        child: Padding(
          padding: const EdgeInsetsDirectional.symmetric(vertical: 10, horizontal: 4),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: _accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                  border: NeonEffects.border(_accent, alpha: 0.3),
                ),
                child: Icon(_icon, size: 18, color: _accent),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontSize: 14,
                              fontWeight: FontWeight.w600,
                              color: isDark
                                  ? NeonPalette.textPrimary
                                  : NeonPalette.lightTextPrimary,
                            ),
                          ),
                        ),
                        if (transaction.pendingSync) ...[
                          const SizedBox(width: 6),
                          // Not yet on the server. Shown, never hidden — the
                          // user should always know what is still local.
                          Icon(Icons.cloud_upload_outlined,
                              size: 13, color: NeonPalette.amber),
                        ],
                      ],
                    ),
                    const SizedBox(height: 3),
                    Text(
                      [categoryLabel, accountLabel]
                          .whereType<String>()
                          .where((s) => s.isNotEmpty)
                          .join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: NeonPalette.textMuted,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Text(
                signed,
                style: TextStyle(
                  fontSize: 14.5,
                  fontWeight: FontWeight.w800,
                  color: _accent,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
