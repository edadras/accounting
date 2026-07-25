import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/money/quantity.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/investment.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'investment_screen.dart';

/// Realized versus unrealized side by side.
///
/// They are separated because they answer different questions: what is already
/// banked, and what is still exposed to the market.
class PositionDetailScreen extends ConsumerWidget {
  const PositionDetailScreen({super.key, required this.position});

  final InvestmentPosition position;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final total = profitAccent(position.totalProfit.minorUnits);

    return ModulePage(
      title: position.name,
      subtitle: position.symbol ?? t(investmentKindKey(position.kind)),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          NeonCard(
            accent: total,
            glow: true,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t('invest.value'),
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
                    MoneyFormatter.format(position.currentValue, locale: code),
                    style: const TextStyle(
                      fontSize: 30,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  '${QuantityFormatter.format(position.quantity, locale: code)} '
                  '${t(position.unitLabelKey)}',
                  style: const TextStyle(
                    fontSize: 12,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: t('invest.realized'),
                  value: MoneyFormatter.formatSigned(
                    position.realizedProfit,
                    locale: code,
                    compact: true,
                  ),
                  accent: profitAccent(position.realizedProfit.minorUnits),
                  icon: Icons.lock_rounded,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: StatTile(
                  label: t('invest.unrealized'),
                  value: MoneyFormatter.formatSigned(
                    position.unrealizedProfit,
                    locale: code,
                    compact: true,
                  ),
                  accent: profitAccent(position.unrealizedProfit.minorUnits),
                  icon: Icons.timeline_rounded,
                ),
              ),
            ],
          ),
          const SizedBox(height: 18),
          NeonCard(
            child: Column(
              children: [
                DetailRow(
                  label: t('invest.cost'),
                  value: MoneyFormatter.format(position.costBasis, locale: code),
                ),
                DetailRow(
                  label: t('invest.avgBuy'),
                  value: MoneyFormatter.format(position.avgBuyPrice, locale: code),
                ),
                DetailRow(
                  label: t('invest.price'),
                  value: MoneyFormatter.format(position.currentPrice, locale: code),
                ),
                DetailRow(
                  label: t('invest.profit'),
                  value: MoneyFormatter.formatSigned(
                    position.totalProfit,
                    locale: code,
                  ),
                  accent: total,
                  strong: true,
                ),
                DetailRow(
                  label: t('invest.roi'),
                  value: QuantityFormatter.percent(
                    position.roiPercent,
                    locale: code,
                    signed: true,
                  ),
                  accent: total,
                ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          SectionHeader(title: t('invest.trades'), accent: NeonPalette.violet),
          NeonCard(
            padding: const EdgeInsetsDirectional.symmetric(
              horizontal: 14,
              vertical: 6,
            ),
            child: Column(
              children: [
                for (final trade in position.trades)
                  _TradeRow(
                    trade: trade,
                    position: position,
                    localeCode: code,
                    locale: locale,
                    t: t,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TradeRow extends StatelessWidget {
  const _TradeRow({
    required this.trade,
    required this.position,
    required this.localeCode,
    required this.locale,
    required this.t,
  });

  final InvestmentTrade trade;
  final InvestmentPosition position;
  final String localeCode;
  final AppLocale locale;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final (String key, Color accent, IconData icon) = switch (trade.action) {
      TradeAction.buy => ('invest.buy', NeonPalette.cyan, Icons.add_rounded),
      TradeAction.sell => ('invest.sell', NeonPalette.lime, Icons.remove_rounded),
      TradeAction.dividend => (
          'invest.dividend',
          NeonPalette.lime,
          Icons.savings_rounded
        ),
      TradeAction.fee => ('invest.fee', NeonPalette.magenta, Icons.receipt_rounded),
      TradeAction.split => (
          'invest.split',
          NeonPalette.violet,
          Icons.call_split_rounded
        ),
    };

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 9),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(9),
            ),
            child: Icon(icon, size: 15, color: accent),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  t(key),
                  style: const TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  DateFormatter.short(trade.occurredAt, locale),
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                '${QuantityFormatter.format(trade.quantity, locale: localeCode)} '
                '× ${MoneyFormatter.format(trade.unitPrice, locale: localeCode, compact: true)}',
                style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: NeonPalette.textSecondary,
                ),
              ),
              if (!trade.realizedProfit.isZero) ...[
                const SizedBox(height: 3),
                Text(
                  MoneyFormatter.formatSigned(
                    trade.realizedProfit,
                    locale: localeCode,
                    compact: true,
                  ),
                  style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: profitAccent(trade.realizedProfit.minorUnits),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
