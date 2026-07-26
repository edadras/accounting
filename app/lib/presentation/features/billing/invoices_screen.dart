import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/billing_repository.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'billing_providers.dart';

/// What was charged, when, and for which period.
///
/// A failed charge keeps its row: `ChangePlan` commits the invoice before it
/// throws, precisely so a decline is something the user can see rather than
/// something that only exists in a log.
class InvoicesScreen extends ConsumerWidget {
  const InvoicesScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const InvoicesScreen());

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final invoices = ref.watch(invoicesProvider);

    return ModulePage(
      title: t('billing.invoicesTitle'),
      subtitle: t('billing.invoicesSubtitle'),
      child: invoices.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('billing.noAccessTitle'),
          permissionBody: t('billing.noAccessBody'),
        ),
        data: (list) => list.isEmpty
            ? NoticePanel(
                key: const ValueKey('billing-invoices-empty'),
                icon: Icons.receipt_long_rounded,
                accent: NeonPalette.cyan,
                title: t('billing.invoicesEmptyTitle'),
                body: t('billing.invoicesEmptyBody'),
              )
            : ListView(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
                children: [
                  for (final invoice in list) ...[
                    _InvoiceRow(invoice: invoice, locale: locale, t: t),
                    const SizedBox(height: 10),
                  ],
                ],
              ),
      ),
    );
  }
}

class _InvoiceRow extends StatelessWidget {
  const _InvoiceRow({
    required this.invoice,
    required this.locale,
    required this.t,
  });

  final BillingInvoice invoice;
  final AppLocale locale;
  final Translator t;

  /// Paid is quiet, a decline is loud: the only row anyone needs to act on is
  /// the one that failed.
  static Color _accent(InvoiceStatus status, {required bool isDark}) =>
      switch (status) {
        InvoiceStatus.paid => isDark ? NeonPalette.lime : NeonPalette.lightLime,
        InvoiceStatus.pending => NeonPalette.amber,
        InvoiceStatus.failed =>
          isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
        InvoiceStatus.refunded =>
          isDark ? NeonPalette.violet : NeonPalette.lightViolet,
      };

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = _accent(invoice.status, isDark: isDark);

    final period = invoice.periodStart == null || invoice.periodEnd == null
        ? null
        : t('billing.invoicePeriod', args: {
            'from': DateFormatter.short(invoice.periodStart!, locale),
            'to': DateFormatter.short(invoice.periodEnd!, locale),
          },);

    return NeonCardShell(
      key: ValueKey('billing-invoice-${invoice.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      invoice.number,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textDirection: TextDirection.ltr,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      planLabel(t, invoice.planCode),
                      style: TextStyle(
                        fontSize: 11.5,
                        color: isDark
                            ? NeonPalette.textMuted
                            : NeonPalette.lightTextSecondary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    MoneyFormatter.format(
                      invoice.amount,
                      locale: locale.code,
                    ),
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w800,
                      color: isDark
                          ? NeonPalette.textPrimary
                          : NeonPalette.lightTextPrimary,
                    ),
                  ),
                  const SizedBox(height: 6),
                  NeonChip(
                    label: t(invoice.status.labelKey),
                    accent: accent,
                    selected: invoice.status != InvoiceStatus.paid,
                  ),
                ],
              ),
            ],
          ),
          if (period != null) ...[
            const SizedBox(height: 10),
            Text(
              period,
              style: TextStyle(
                fontSize: 11,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ],
          if (invoice.issuedAt != null) ...[
            const SizedBox(height: 4),
            Text(
              t('billing.invoiceIssued', args: {
                'date': DateFormatter.short(invoice.issuedAt!, locale),
              },),
              style: TextStyle(
                fontSize: 11,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
