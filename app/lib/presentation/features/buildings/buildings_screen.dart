import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/money/quantity.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/buildings.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';

Color chargeAccent(ChargeStatus status) => switch (status) {
      ChargeStatus.paid => NeonPalette.lime,
      ChargeStatus.partial => NeonPalette.amber,
      ChargeStatus.unpaid => NeonPalette.magenta,
    };

String chargeStatusKey(ChargeStatus status) => switch (status) {
      ChargeStatus.paid => 'building.stPaid',
      ChargeStatus.partial => 'building.stPartial',
      ChargeStatus.unpaid => 'building.stUnpaid',
    };

class BuildingsScreen extends ConsumerWidget {
  const BuildingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final code = ref.watch(localeProvider).code;
    final building = ref.watch(buildingProvider);

    return ModulePage(
      title: t('module.buildings'),
      subtitle: t('module.buildingsHint'),
      child: building.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => ModuleError(message: t('common.error')),
        data: (data) {
          final debtors = data.debtors();

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
                      t('building.fund'),
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
                        MoneyFormatter.format(data.fundBalance, locale: code),
                        style: const TextStyle(
                          fontSize: 32,
                          fontWeight: FontWeight.w800,
                          color: NeonPalette.textPrimary,
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      '${data.name} · ${t(_formulaKey(data.formula))}',
                      style: const TextStyle(
                        fontSize: 11.5,
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
                      label: t('building.billed'),
                      value: MoneyFormatter.format(data.billed,
                          locale: code, compact: true,),
                      accent: NeonPalette.cyan,
                      icon: Icons.receipt_long_rounded,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StatTile(
                      label: t('building.collected'),
                      value: MoneyFormatter.format(data.collected,
                          locale: code, compact: true,),
                      accent: NeonPalette.lime,
                      icon: Icons.done_all_rounded,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StatTile(
                      label: t('building.outstanding'),
                      value: MoneyFormatter.format(data.outstanding,
                          locale: code, compact: true,),
                      accent: NeonPalette.magenta,
                      icon: Icons.warning_amber_rounded,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              NeonCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            t('building.collectionRate'),
                            style: const TextStyle(
                              fontSize: 12.5,
                              color: NeonPalette.textSecondary,
                            ),
                          ),
                        ),
                        Text(
                          QuantityFormatter.percent(
                            data.collectionRate * 100,
                            locale: code,
                          ),
                          style: const TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w800,
                            color: NeonPalette.lime,
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    NeonMeter(
                      fraction: data.collectionRate,
                      accent: NeonPalette.lime,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 22),
              SectionHeader(title: t('building.units')),
              UnitsGrid(building: data),
              const SizedBox(height: 22),
              SectionHeader(
                title: t('building.debtors'),
                accent: NeonPalette.magenta,
              ),
              if (debtors.isEmpty)
                NeonCard(
                  accent: NeonPalette.lime,
                  child: Text(
                    t('building.noDebtors'),
                    style: const TextStyle(
                      fontSize: 13,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
                )
              else
                for (final debt in debtors) ...[
                  DebtorCard(debt: debt, localeCode: code),
                  const SizedBox(height: 10),
                ],
            ],
          );
        },
      ),
    );
  }

  static String _formulaKey(ChargeFormula formula) => switch (formula) {
        ChargeFormula.fixed => 'building.formulaFixed',
        ChargeFormula.perArea => 'building.formulaPerArea',
        ChargeFormula.perResident => 'building.formulaPerResident',
        ChargeFormula.mixed => 'building.formulaMixed',
      };
}

/// A grid, not a list: twelve units read as a floor plan, and the eye finds the
/// magenta tiles without reading a single label.
class UnitsGrid extends ConsumerWidget {
  const UnitsGrid({super.key, required this.building});

  final Building building;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final code = ref.watch(localeProvider).code;

    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 1.15,
      ),
      itemCount: building.units.length,
      itemBuilder: (context, index) {
        final unit = building.units[index];
        final status = building.statusFor(unit.id);
        final accent = chargeAccent(status);

        return UnitTile(
          unit: unit,
          status: status,
          accent: accent,
          localeCode: code,
        );
      },
    );
  }
}

class UnitTile extends ConsumerWidget {
  const UnitTile({
    super.key,
    required this.unit,
    required this.status,
    required this.accent,
    required this.localeCode,
  });

  final BuildingUnit unit;
  final ChargeStatus status;
  final Color accent;
  final String localeCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCardShell(
      accent: accent,
      // Only the unpaid tiles glow — a grid where every tile glows tells the
      // manager nothing.
      glow: status == ChargeStatus.unpaid,
      padding: const EdgeInsetsDirectional.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  unit.unitNo,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: NeonPalette.textPrimary,
                  ),
                ),
              ),
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(shape: BoxShape.circle, color: accent),
              ),
            ],
          ),
          Text(
            unit.isOccupied
                ? (unit.residentLabel ?? '')
                : t('building.vacant'),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 10.5, color: NeonPalette.textMuted),
          ),
          Text(
            t('building.area', args: {
              'n': DateFormatter.number(unit.areaSquareMetres, localeCode),
            },),
            style: const TextStyle(fontSize: 10.5, color: NeonPalette.textMuted),
          ),
          Text(
            t(chargeStatusKey(status)),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
              color: accent,
            ),
          ),
        ],
      ),
    );
  }
}

class DebtorCard extends ConsumerWidget {
  const DebtorCard({
    super.key,
    required this.debt,
    required this.localeCode,
  });

  final UnitDebt debt;
  final String localeCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final months = t('building.monthsOwed', args: {
      'count': DateFormatter.number(debt.chargesCount, localeCode),
    },);
    final since = t('building.since', args: {'period': debt.oldestPeriod});

    return NeonCardShell(
      accent: NeonPalette.magenta,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  t('building.unitNo', args: {'no': debt.unit.unitNo}),
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${debt.unit.residentLabel ?? ''} · $months · $since',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 11,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Text(
            MoneyFormatter.format(debt.owed, locale: localeCode),
            style: const TextStyle(
              fontSize: 14.5,
              fontWeight: FontWeight.w800,
              color: NeonPalette.magenta,
            ),
          ),
        ],
      ),
    );
  }
}
