import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/assets.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'assets_screen.dart';

class AssetDetailScreen extends ConsumerWidget {
  const AssetDetailScreen({super.key, required this.asset});

  final FixedAsset asset;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final now = ref.watch(clockProvider);
    final curve = asset.depreciationCurve();
    final insurance = asset.insuranceStateOn(now);
    final warning = insuranceAccent(insurance);
    final changeAccent =
        asset.hasGained ? NeonPalette.lime : NeonPalette.magenta;

    final methodKey = switch (asset.method) {
      DepreciationMethod.none => 'asset.methodNone',
      DepreciationMethod.linear => 'asset.methodLinear',
      DepreciationMethod.declining => 'asset.methodDeclining',
    };

    return ModulePage(
      title: asset.name,
      subtitle: t(assetKindKey(asset.kind)),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          NeonCard(
            accent: NeonPalette.cyan,
            glow: true,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  t('asset.current'),
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
                    MoneyFormatter.format(asset.currentValue, locale: code),
                    style: const TextStyle(
                      fontSize: 30,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  '${t('asset.change')} '
                  '${MoneyFormatter.formatSigned(asset.changeSincePurchase, locale: code)}',
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w700,
                    color: changeAccent,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          NeonCard(
            child: Column(
              children: [
                DetailRow(
                  label: t('asset.purchase'),
                  value: MoneyFormatter.format(asset.purchasePrice, locale: code),
                ),
                DetailRow(
                  label: t('tx.date'),
                  value: DateFormatter.short(asset.purchaseDate, locale),
                ),
                DetailRow(label: t('asset.depreciation'), value: t(methodKey)),
                if (asset.usefulLifeYears != null)
                  DetailRow(
                    label: t('asset.usefulLife'),
                    value: t('asset.years', args: {
                      'count':
                          DateFormatter.number(asset.usefulLifeYears!, code),
                    },),
                  ),
                if (!asset.salvageValue.isZero)
                  DetailRow(
                    label: t('asset.salvage'),
                    value:
                        MoneyFormatter.format(asset.salvageValue, locale: code),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          NeonCard(
            accent: warning ?? NeonPalette.lime,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(
                      Icons.shield_outlined,
                      size: 16,
                      color: warning ?? NeonPalette.lime,
                    ),
                    const SizedBox(width: 8),
                    Text(
                      t('asset.insurance'),
                      style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: warning ?? NeonPalette.lime,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Text(
                  _message(t, asset, now, code),
                  style: const TextStyle(
                    fontSize: 13,
                    height: 1.5,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                if (asset.insuranceProvider != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    asset.insuranceProvider!,
                    style: const TextStyle(
                      fontSize: 11.5,
                      color: NeonPalette.textMuted,
                    ),
                  ),
                ],
              ],
            ),
          ),
          if (curve.isNotEmpty) ...[
            const SizedBox(height: 22),
            SectionHeader(
              title: t('asset.depreciation'),
              accent: NeonPalette.violet,
              actionLabel: t('asset.bookValue'),
            ),
            NeonCard(
              accent: NeonPalette.violet,
              child: DepreciationChart(rows: curve, localeCode: code),
            ),
          ],
        ],
      ),
    );
  }

  static String _message(
    Translator t,
    FixedAsset asset,
    DateTime now,
    String code,
  ) =>
      switch (asset.insuranceStateOn(now)) {
        InsuranceState.expired => t('asset.expired'),
        InsuranceState.expiringSoon => t('asset.expiringSoon', args: {
            'days': DateFormatter.number(asset.insuranceDaysLeft(now) ?? 0, code),
          },),
        InsuranceState.covered => t('asset.insured'),
        InsuranceState.none => t('asset.uninsured'),
      };
}

/// Closing book value per year.
///
/// Drawn as columns against the purchase price rather than as a line, because
/// the reader's question is "how much is left", and a bar answers that without
/// a y-axis.
class DepreciationChart extends StatelessWidget {
  const DepreciationChart({
    super.key,
    required this.rows,
    required this.localeCode,
  });

  final List<DepreciationYear> rows;
  final String localeCode;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final peak = rows.first.opening.minorUnits;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          height: 130,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (final row in rows)
                Expanded(
                  child: Padding(
                    padding:
                        const EdgeInsetsDirectional.symmetric(horizontal: 2),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        Expanded(
                          child: LayoutBuilder(
                            builder: (context, constraints) {
                              final fraction = peak == 0
                                  ? 0.0
                                  : row.closing.minorUnits / peak;
                              return TweenAnimationBuilder<double>(
                                tween: Tween(
                                  begin: 0,
                                  end: fraction.clamp(0.0, 1.0),
                                ),
                                duration: NeonEffects.medium,
                                curve: NeonEffects.curve,
                                builder: (context, value, _) => Align(
                                  alignment: Alignment.bottomCenter,
                                  child: Container(
                                    height: math.max(
                                      3,
                                      constraints.maxHeight * value,
                                    ),
                                    decoration: BoxDecoration(
                                      gradient: NeonEffects.hero(
                                        NeonPalette.violet
                                            .withValues(alpha: 0.45),
                                        NeonPalette.violet,
                                      ),
                                      borderRadius: BorderRadius.circular(4),
                                      boxShadow: isDark
                                          ? NeonEffects.glowTight(
                                              NeonPalette.violet,
                                              intensity: 0.35,
                                            )
                                          : null,
                                    ),
                                  ),
                                ),
                              );
                            },
                          ),
                        ),
                        const SizedBox(height: 7),
                        Text(
                          DateFormatter.number(row.year, localeCode),
                          style: const TextStyle(
                            fontSize: 10,
                            color: NeonPalette.textMuted,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Expanded(
              child: Text(
                MoneyFormatter.format(rows.first.opening,
                    locale: localeCode, compact: true,),
                style: const TextStyle(
                  fontSize: 11.5,
                  color: NeonPalette.textMuted,
                ),
              ),
            ),
            Text(
              MoneyFormatter.format(rows.last.closing,
                  locale: localeCode, compact: true,),
              style: const TextStyle(
                fontSize: 11.5,
                fontWeight: FontWeight.w700,
                color: NeonPalette.violet,
              ),
            ),
          ],
        ),
      ],
    );
  }
}
