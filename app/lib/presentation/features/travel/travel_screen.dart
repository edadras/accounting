import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/travel.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'trip_detail_screen.dart';

class TravelScreen extends ConsumerWidget {
  const TravelScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final trips = ref.watch(tripsProvider);

    return ModulePage(
      title: t('module.travel'),
      subtitle: t('module.travelHint'),
      child: trips.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => ModuleError(message: t('common.error')),
        data: (list) => ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
          children: [
            SectionHeader(title: t('travel.trips'), accent: NeonPalette.lime),
            for (final trip in list) ...[
              TripCard(trip: trip, localeCode: locale.code, locale: locale),
              const SizedBox(height: 12),
            ],
          ],
        ),
      ),
    );
  }
}

class TripCard extends ConsumerWidget {
  const TripCard({
    super.key,
    required this.trip,
    required this.localeCode,
    required this.locale,
  });

  final Trip trip;
  final String localeCode;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final transfers = trip.settlements();

    return NeonCardShell(
      accent: NeonPalette.lime,
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => TripDetailScreen(trip: trip)),
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
                      trip.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${trip.destination} · '
                      '${DateFormatter.short(trip.startsAt, locale)}',
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: NeonPalette.textMuted,
                      ),
                    ),
                  ],
                ),
              ),
              Text(
                MoneyFormatter.format(trip.total, locale: localeCode),
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                  color: NeonPalette.textPrimary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              NeonChip(
                label: DateFormatter.number(trip.members.length, localeCode),
                accent: NeonPalette.cyan,
                icon: Icons.group_rounded,
              ),
              const SizedBox(width: 8),
              Flexible(
                child: NeonChip(
                  label: transfers.isEmpty
                      ? t('travel.allSettled')
                      : t('travel.settlementHint', args: {
                          'count':
                              DateFormatter.number(transfers.length, localeCode),
                        },),
                  accent: transfers.isEmpty
                      ? NeonPalette.lime
                      : NeonPalette.amber,
                  selected: transfers.isNotEmpty,
                  icon: Icons.swap_horiz_rounded,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
