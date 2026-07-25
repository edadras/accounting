import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/assets.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'asset_detail_screen.dart';

String assetKindKey(AssetKind kind) => switch (kind) {
      AssetKind.house => 'asset.kindHouse',
      AssetKind.land => 'asset.kindLand',
      AssetKind.car => 'asset.kindCar',
      AssetKind.gold => 'asset.kindGold',
      AssetKind.watch => 'asset.kindWatch',
      AssetKind.art => 'asset.kindArt',
      AssetKind.nft => 'asset.kindNft',
      AssetKind.other => 'asset.kindOther',
    };

IconData assetKindIcon(AssetKind kind) => switch (kind) {
      AssetKind.house => Icons.home_rounded,
      AssetKind.land => Icons.landscape_rounded,
      AssetKind.car => Icons.directions_car_rounded,
      AssetKind.gold => Icons.workspace_premium_rounded,
      AssetKind.watch => Icons.watch_rounded,
      AssetKind.art => Icons.palette_rounded,
      AssetKind.nft => Icons.token_rounded,
      AssetKind.other => Icons.inventory_2_rounded,
    };

/// Insurance is the only thing here that expires, so it is the only thing that
/// gets a warning colour.
Color? insuranceAccent(InsuranceState state) => switch (state) {
      InsuranceState.expired => NeonPalette.magenta,
      InsuranceState.expiringSoon => NeonPalette.amber,
      InsuranceState.covered || InsuranceState.none => null,
    };

class AssetsScreen extends ConsumerWidget {
  const AssetsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final currency = ref.watch(baseCurrencyProvider);
    final now = ref.watch(clockProvider);
    final assets = ref.watch(fixedAssetsProvider);

    return ModulePage(
      title: t('module.assets'),
      subtitle: t('module.assetsHint'),
      child: assets.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => ModuleError(message: t('common.error')),
        data: (list) {
          final total = list.fold(
            Money(0, currency),
            (sum, asset) => sum + asset.currentValue,
          );
          final atRisk = list
              .where((a) =>
                  a.insuranceStateOn(now) == InsuranceState.expired ||
                  a.insuranceStateOn(now) == InsuranceState.expiringSoon,)
              .toList();

          return ListView(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
            children: [
              NeonCard(
                accent: NeonPalette.cyan,
                glow: true,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      t('asset.totalValue'),
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
                        MoneyFormatter.format(total, locale: code),
                        style: const TextStyle(
                          fontSize: 32,
                          fontWeight: FontWeight.w800,
                          color: NeonPalette.textPrimary,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              if (atRisk.isNotEmpty) ...[
                const SizedBox(height: 14),
                _InsuranceWarning(assets: atRisk, now: now),
              ],
              const SizedBox(height: 22),
              SectionHeader(title: t('module.assets')),
              for (final asset in list) ...[
                AssetCard(asset: asset, localeCode: code, now: now),
                const SizedBox(height: 10),
              ],
            ],
          );
        },
      ),
    );
  }
}

class _InsuranceWarning extends ConsumerWidget {
  const _InsuranceWarning({required this.assets, required this.now});

  final List<FixedAsset> assets;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final code = ref.watch(localeProvider).code;
    final worst = assets.any(
      (a) => a.insuranceStateOn(now) == InsuranceState.expired,
    )
        ? NeonPalette.magenta
        : NeonPalette.amber;

    return NeonCard(
      accent: worst,
      glow: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.shield_outlined, size: 17, color: worst),
              const SizedBox(width: 8),
              Text(
                t('asset.insurance'),
                style: TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: worst,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          for (final asset in assets)
            Padding(
              padding: const EdgeInsetsDirectional.only(bottom: 5),
              child: Text(
                '${asset.name} — '
                '${_insuranceMessage(t, asset, now, code)}',
                style: const TextStyle(
                  fontSize: 12,
                  height: 1.5,
                  color: NeonPalette.textSecondary,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

String _insuranceMessage(
  Translator t,
  FixedAsset asset,
  DateTime now,
  String localeCode,
) {
  final state = asset.insuranceStateOn(now);
  return switch (state) {
    InsuranceState.expired => t('asset.expired'),
    InsuranceState.expiringSoon => t('asset.expiringSoon', args: {
        'days': DateFormatter.number(asset.insuranceDaysLeft(now) ?? 0, localeCode),
      },),
    InsuranceState.covered => t('asset.insured'),
    InsuranceState.none => t('asset.uninsured'),
  };
}

class AssetCard extends ConsumerWidget {
  const AssetCard({
    super.key,
    required this.asset,
    required this.localeCode,
    required this.now,
  });

  final FixedAsset asset;
  final String localeCode;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final insurance = asset.insuranceStateOn(now);
    final warning = insuranceAccent(insurance);
    final changeAccent =
        asset.hasGained ? NeonPalette.lime : NeonPalette.magenta;

    return NeonCardShell(
      accent: warning ?? NeonPalette.cyan,
      glow: insurance == InsuranceState.expired,
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => AssetDetailScreen(asset: asset),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: NeonPalette.cyan.withValues(alpha: 0.10),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  assetKindIcon(asset.kind),
                  size: 19,
                  color: NeonPalette.cyan,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      asset.name,
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
                      t(assetKindKey(asset.kind)),
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
                    MoneyFormatter.format(asset.currentValue,
                        locale: localeCode, compact: true,),
                    style: const TextStyle(
                      fontSize: 14.5,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    MoneyFormatter.formatSigned(
                      asset.changeSincePurchase,
                      locale: localeCode,
                      compact: true,
                    ),
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: changeAccent,
                    ),
                  ),
                ],
              ),
            ],
          ),
          if (warning != null) ...[
            const SizedBox(height: 12),
            NeonChip(
              label: _insuranceMessage(t, asset, now, localeCode),
              accent: warning,
              selected: true,
              icon: Icons.shield_outlined,
            ),
          ],
        ],
      ),
    );
  }
}
