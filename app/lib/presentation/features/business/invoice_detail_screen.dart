import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/money/quantity.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/business.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'business_screen.dart';

class InvoiceDetailScreen extends ConsumerWidget {
  const InvoiceDetailScreen({
    super.key,
    required this.invoice,
    this.contact,
  });

  final Invoice invoice;
  final BusinessContact? contact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final now = ref.watch(clockProvider);
    final status = invoice.effectiveStatus(now);
    final accent = invoiceStatusAccent(status);

    return ModulePage(
      title: invoice.number,
      subtitle: contact?.name,
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          NeonCard(
            accent: accent,
            glow: status == InvoiceStatus.overdue,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        t('biz.total'),
                        style: const TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w600,
                          color: NeonPalette.textSecondary,
                        ),
                      ),
                    ),
                    NeonChip(
                      label: t(invoiceStatusKey(status)),
                      accent: accent,
                      selected: true,
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: Text(
                    MoneyFormatter.format(invoice.total, locale: code),
                    style: const TextStyle(
                      fontSize: 30,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Text(
                      '${t('biz.issued')} '
                      '${DateFormatter.short(invoice.issueDate, locale)}',
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: NeonPalette.textMuted,
                      ),
                    ),
                    if (invoice.dueDate != null) ...[
                      const SizedBox(width: 12),
                      Text(
                        '${t('biz.due')} '
                        '${DateFormatter.short(invoice.dueDate!, locale)}',
                        style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w600,
                          color: status == InvoiceStatus.overdue
                              ? NeonPalette.magenta
                              : NeonPalette.textMuted,
                        ),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          SectionHeader(title: t('biz.items'), accent: NeonPalette.violet),
          NeonCard(
            accent: NeonPalette.violet,
            padding: const EdgeInsetsDirectional.symmetric(
              horizontal: 16,
              vertical: 8,
            ),
            child: Column(
              children: [
                for (final item in invoice.items)
                  _ItemRow(item: item, localeCode: code, t: t),
              ],
            ),
          ),
          const SizedBox(height: 18),
          NeonCard(
            child: Column(
              children: [
                DetailRow(
                  label: t('biz.subtotal'),
                  value: MoneyFormatter.format(invoice.subtotal, locale: code),
                ),
                if (!invoice.discount.isZero)
                  DetailRow(
                    label: t('biz.discount'),
                    value: MoneyFormatter.formatSigned(
                      invoice.discount.negated,
                      locale: code,
                    ),
                    accent: NeonPalette.lime,
                  ),
                DetailRow(
                  label: t('biz.tax'),
                  value: MoneyFormatter.format(invoice.tax, locale: code),
                ),
                DetailRow(
                  label: t('biz.total'),
                  value: MoneyFormatter.format(invoice.total, locale: code),
                  strong: true,
                ),
                DetailRow(
                  label: t('biz.paid'),
                  value: MoneyFormatter.format(invoice.paid, locale: code),
                  accent: NeonPalette.lime,
                ),
                DetailRow(
                  label: t('biz.outstanding'),
                  value: MoneyFormatter.format(invoice.outstanding, locale: code),
                  accent: invoice.outstanding.isZero
                      ? NeonPalette.textMuted
                      : accent,
                  strong: true,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({
    required this.item,
    required this.localeCode,
    required this.t,
  });

  final InvoiceItem item;
  final String localeCode;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 9),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  item.description,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${QuantityFormatter.format(item.quantity, locale: localeCode)}'
                  ' × '
                  '${MoneyFormatter.format(item.unitPrice, locale: localeCode)}'
                  '${item.taxRatePercent == 0 ? '' : '  ·  ${t('biz.tax')} ${QuantityFormatter.percent(item.taxRatePercent.toDouble(), locale: localeCode)}'}',
                  maxLines: 2,
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Text(
            MoneyFormatter.format(item.lineTotal, locale: localeCode),
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              color: NeonPalette.violet,
            ),
          ),
        ],
      ),
    );
  }
}
