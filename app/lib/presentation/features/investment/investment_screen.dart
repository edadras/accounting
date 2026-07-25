import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/money/quantity.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/investment.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'position_detail_screen.dart';

String investmentKindKey(InvestmentKind kind) => switch (kind) {
      InvestmentKind.gold => 'invest.kindGold',
      InvestmentKind.fx => 'invest.kindFx',
      InvestmentKind.stock => 'invest.kindStock',
      InvestmentKind.etf => 'invest.kindEtf',
      InvestmentKind.crypto => 'invest.kindCrypto',
      InvestmentKind.realEstate => 'invest.kindRealEstate',
      InvestmentKind.vehicle => 'invest.kindVehicle',
      InvestmentKind.startup => 'invest.kindStartup',
    };

/// Green up, magenta down. A portfolio row that needs to be read to be
/// understood has failed.
Color profitAccent(int minorUnits) =>
    minorUnits < 0 ? NeonPalette.magenta : NeonPalette.lime;

class InvestmentScreen extends ConsumerWidget {
  const InvestmentScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final code = ref.watch(localeProvider).code;
    final portfolio = ref.watch(portfolioProvider);

    return ModulePage(
      title: t('module.investment'),
      subtitle: t('module.investmentHint'),
      child: portfolio.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => ModuleError(message: t('common.error')),
        data: (data) {
          final accent = profitAccent(data.totalProfit.minorUnits);

          return ListView(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
            children: [
              NeonCard(
                accent: NeonPalette.violet,
                glow: true,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      t('invest.portfolio'),
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
                        MoneyFormatter.format(data.totalValue, locale: code),
                        style: const TextStyle(
                          fontSize: 32,
                          fontWeight: FontWeight.w800,
                          color: NeonPalette.textPrimary,
                        ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Icon(
                          data.totalProfit.isNegative
                              ? Icons.trending_down_rounded
                              : Icons.trending_up_rounded,
                          size: 16,
                          color: accent,
                        ),
                        const SizedBox(width: 6),
                        Text(
                          MoneyFormatter.formatSigned(data.totalProfit, locale: code),
                          style: TextStyle(
                            fontSize: 13.5,
                            fontWeight: FontWeight.w700,
                            color: accent,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Text(
                          QuantityFormatter.percent(
                            data.roiPercent,
                            locale: code,
                            signed: true,
                          ),
                          style: TextStyle(
                            fontSize: 13.5,
                            fontWeight: FontWeight.w800,
                            color: accent,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 14),
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: t('invest.cost'),
                      value: MoneyFormatter.format(data.totalCost,
                          locale: code, compact: true,),
                      accent: NeonPalette.cyan,
                      icon: Icons.account_balance_wallet_rounded,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StatTile(
                      label: t('invest.profit'),
                      value: MoneyFormatter.format(data.totalProfit,
                          locale: code, compact: true,),
                      accent: accent,
                      icon: Icons.show_chart_rounded,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 22),
              SectionHeader(
                title: t('invest.portfolio'),
                accent: NeonPalette.violet,
              ),
              for (final position in data.positions) ...[
                PositionCard(position: position, localeCode: code),
                const SizedBox(height: 10),
              ],
            ],
          );
        },
      ),
    );
  }
}

class PositionCard extends ConsumerWidget {
  const PositionCard({
    super.key,
    required this.position,
    required this.localeCode,
  });

  final InvestmentPosition position;
  final String localeCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final accent = profitAccent(position.totalProfit.minorUnits);

    return NeonCardShell(
      accent: accent,
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => PositionDetailScreen(position: position),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      position.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${t(investmentKindKey(position.kind))} · '
                      '${QuantityFormatter.format(position.quantity, locale: localeCode)} '
                      '${t(position.unitLabelKey)}',
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
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    MoneyFormatter.format(position.currentValue, locale: localeCode),
                    style: const TextStyle(
                      fontSize: 14.5,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    QuantityFormatter.percent(
                      position.roiPercent,
                      locale: localeCode,
                      signed: true,
                    ),
                    style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w800,
                      color: accent,
                    ),
                  ),
                ],
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${t('invest.cost')} '
                  '${MoneyFormatter.format(position.costBasis, locale: localeCode, compact: true)}',
                  style: const TextStyle(
                    fontSize: 11,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ),
              Text(
                MoneyFormatter.formatSigned(
                  position.totalProfit,
                  locale: localeCode,
                  compact: true,
                ),
                style: TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                  color: accent,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
