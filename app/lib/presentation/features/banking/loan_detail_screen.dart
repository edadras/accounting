import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/money/quantity.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/banking.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';

/// The amortisation table, plus the one number a borrower actually wants: how
/// much of this is behind them.
class LoanDetailScreen extends ConsumerWidget {
  const LoanDetailScreen({super.key, required this.loan});

  final Loan loan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final next = loan.nextDue();

    return ModulePage(
      title: loan.title,
      subtitle: loan.lender,
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          NeonCard(
            accent: NeonPalette.violet,
            glow: true,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t('bank.outstanding'),
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: NeonPalette.textSecondary,
                  ),
                ),
                const SizedBox(height: 10),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: Text(
                    MoneyFormatter.format(loan.outstanding, locale: code),
                    style: const TextStyle(
                      fontSize: 30,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                NeonMeter(
                  fraction: loan.progress,
                  accent: NeonPalette.violet,
                  height: 11,
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        t('bank.installmentsPaid', args: {
                          'paid': DateFormatter.number(loan.paidInstallments, code),
                          'total': DateFormatter.number(loan.schedule.length, code),
                        },),
                        style: const TextStyle(
                          fontSize: 11.5,
                          color: NeonPalette.textMuted,
                        ),
                      ),
                    ),
                    Text(
                      QuantityFormatter.percent(loan.progress * 100, locale: code),
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w800,
                        color: NeonPalette.violet,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          NeonCard(
            child: Column(
              children: [
                DetailRow(
                  label: t('bank.principal'),
                  value: MoneyFormatter.format(loan.principal, locale: code),
                ),
                DetailRow(
                  label: t('bank.rate'),
                  value: QuantityFormatter.percent(loan.annualRatePercent, locale: code),
                ),
                DetailRow(
                  label: t('bank.totalInterest'),
                  value: MoneyFormatter.format(loan.totalInterest, locale: code),
                  accent: NeonPalette.amber,
                ),
                DetailRow(
                  label: t('bank.totalPayable'),
                  value: MoneyFormatter.format(loan.totalPayable, locale: code),
                  strong: true,
                ),
                DetailRow(
                  label: t('bank.repaid'),
                  value: MoneyFormatter.format(loan.paidToDate, locale: code),
                  accent: NeonPalette.lime,
                ),
                if (next != null)
                  DetailRow(
                    label: t('bank.nextDue'),
                    value: '${DateFormatter.short(next.dueDate, locale)} · '
                        '${MoneyFormatter.format(next.totalAmount, locale: code)}',
                    accent: NeonPalette.amber,
                  ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          SectionHeader(
            title: t('bank.schedule'),
            accent: NeonPalette.violet,
            actionLabel: loan.interestType == LoanInterestType.compound
                ? t('bank.compound')
                : t('bank.simple'),
          ),
          NeonCard(
            padding: const EdgeInsetsDirectional.symmetric(
              horizontal: 14,
              vertical: 6,
            ),
            child: Column(
              children: [
                for (final installment in loan.schedule)
                  InstallmentRow(
                    installment: installment,
                    localeCode: code,
                    locale: locale,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class InstallmentRow extends ConsumerWidget {
  const InstallmentRow({
    super.key,
    required this.installment,
    required this.localeCode,
    required this.locale,
  });

  final LoanInstallment installment;
  final String localeCode;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final paid = installment.status == InstallmentStatus.paid;
    final accent = paid ? NeonPalette.lime : NeonPalette.textSecondary;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 9),
      child: Row(
        children: [
          SizedBox(
            width: 34,
            child: Text(
              DateFormatter.number(installment.number, localeCode),
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: accent,
              ),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  DateFormatter.short(installment.dueDate, locale),
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${t('bank.principalPart')} '
                  '${MoneyFormatter.format(installment.principalPart, locale: localeCode, compact: true)}'
                  '  ·  ${t('bank.interestPart')} '
                  '${MoneyFormatter.format(installment.interestPart, locale: localeCode, compact: true)}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                MoneyFormatter.format(installment.totalAmount, locale: localeCode),
                style: const TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w800,
                  color: NeonPalette.textPrimary,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                paid ? t('bank.stPaid') : t('bank.stDue'),
                style: TextStyle(fontSize: 10.5, color: accent),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
