import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/travel.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'trip_map.dart';

/// Where a trip's money was spent (docs/02-modules.md §8).
///
/// There is no basemap here and the screen says so: no tile server is reachable
/// from this build, so what is drawn is a scatter of the recorded coordinates
/// inside a frame. That is a smaller claim than a map, and it is a true one —
/// the relative arrangement of the expenses is real even though the coastline
/// behind them is missing.
class TripMapScreen extends ConsumerStatefulWidget {
  const TripMapScreen({super.key, required this.trip});

  final Trip trip;

  static Route<void> route(Trip trip) => MaterialPageRoute<void>(
        builder: (_) => TripMapScreen(trip: trip),
      );

  @override
  ConsumerState<TripMapScreen> createState() => _TripMapScreenState();
}

class _TripMapScreenState extends ConsumerState<TripMapScreen> {
  String? _selectedId;

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final trip = widget.trip;

    // Only the counts and the single-point flag are read from this layout, and
    // none of them depend on the box; the plot lays itself out again against
    // its real constraints.
    final layout = TripMapProjection.layout(
      trip.expenses,
      size: const Size(320, 260),
    );

    final selected = _expenseById(trip, _selectedId);

    return ModulePage(
      title: t('travel.map'),
      subtitle: trip.name,
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          if (layout.isEmpty)
            _NoCoordinates(t: t, unplaced: layout.unplaced, localeCode: code)
          else ...[
            NeonCard(
              padding: const EdgeInsetsDirectional.all(10),
              child: TripMapPlot(
                expenses: trip.expenses,
                selectedId: _selectedId,
                onSelect: (expense) =>
                    setState(() => _selectedId = expense.id),
                semanticsLabel: (expense) =>
                    '${expense.title} '
                    '${MoneyFormatter.format(expense.amount, locale: code)}',
              ),
            ),
            const SizedBox(height: 14),
            TripMapLegend(layout: layout, t: t, localeCode: code),
            const SizedBox(height: 18),
            SectionHeader(
              title: t('travel.mapSelected'),
              accent: NeonPalette.violet,
            ),
            if (selected == null)
              _Hint(text: t('travel.mapSelectHint'))
            else
              SelectedExpenseCard(
                expense: selected,
                trip: trip,
                locale: locale,
                t: t,
              ),
          ],
        ],
      ),
    );
  }

  static SplitExpense? _expenseById(Trip trip, String? id) {
    if (id == null) return null;
    for (final expense in trip.expenses) {
      if (expense.id == id) return expense;
    }
    return null;
  }
}

/// Everything the plot cannot say for itself.
///
/// The largest-expense swatch glows because the marker it explains does; the
/// other two swatches do not, because nothing about them is urgent.
class TripMapLegend extends StatelessWidget {
  const TripMapLegend({
    super.key,
    required this.layout,
    required this.t,
    required this.localeCode,
  });

  final TripMapLayout layout;
  final Translator t;
  final String localeCode;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCard(
      padding: const EdgeInsetsDirectional.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t('travel.mapSchematic'),
            style: const TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: NeonPalette.textMuted,
            ),
          ),
          const SizedBox(height: 12),
          _LegendRow(
            accent: isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
            label: t('travel.mapSizeLegend'),
          ),
          _LegendRow(
            accent: NeonPalette.amber,
            glow: true,
            label: t('travel.mapLargest'),
          ),
          if (layout.isSinglePoint)
            _LegendRow(
              accent: NeonPalette.violet,
              label: t('travel.mapSamePlace'),
            ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              NeonChip(
                label: t('travel.mapPlaced', args: {
                  'count': DateFormatter.number(layout.placed, localeCode),
                },),
                accent: NeonPalette.cyan,
                icon: Icons.place_rounded,
              ),
              if (layout.unplaced > 0)
                NeonChip(
                  label: t('travel.mapUnplaced', args: {
                    'count': DateFormatter.number(layout.unplaced, localeCode),
                  },),
                  accent: NeonPalette.textMuted,
                  icon: Icons.location_off_rounded,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _LegendRow extends StatelessWidget {
  const _LegendRow({
    required this.accent,
    required this.label,
    this.glow = false,
  });

  final Color accent;
  final String label;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 5),
      child: Row(
        children: [
          Container(
            width: 12,
            height: 12,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: accent.withValues(alpha: 0.25),
              border: Border.all(color: accent, width: 1.4),
              boxShadow: glow && isDark
                  ? NeonEffects.glowTight(accent, intensity: 0.8)
                  : null,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                fontSize: 12,
                color: isDark
                    ? NeonPalette.textSecondary
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// The tapped marker, written out.
class SelectedExpenseCard extends StatelessWidget {
  const SelectedExpenseCard({
    super.key,
    required this.expense,
    required this.trip,
    required this.locale,
    required this.t,
  });

  final SplitExpense expense;
  final Trip trip;
  final AppLocale locale;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final payer = trip.memberById(expense.payerId);

    return NeonCard(
      accent: NeonPalette.violet,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            expense.title,
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
          const SizedBox(height: 10),
          DetailRow(
            label: t('travel.spent'),
            value: MoneyFormatter.format(expense.amount, locale: locale.code),
            accent: NeonPalette.violet,
            strong: true,
          ),
          DetailRow(
            label: t('travel.paidBy'),
            value: payer?.name ?? '',
          ),
          DetailRow(
            label: t('travel.occurredAt'),
            value: DateFormatter.short(expense.occurredAt, locale),
          ),
          DetailRow(
            label: t('travel.coordinates'),
            // A coordinate pair is Latin text with its own reading order; the
            // isolate stops it being reshuffled inside a Persian line.
            value: '\u{2068}${_degrees(expense.latitude!)}, '
                '${_degrees(expense.longitude!)}\u{2069}',
          ),
        ],
      ),
    );
  }

  static String _degrees(double value) => value.toStringAsFixed(4);
}

class _NoCoordinates extends StatelessWidget {
  const _NoCoordinates({
    required this.t,
    required this.unplaced,
    required this.localeCode,
  });

  final Translator t;
  final int unplaced;
  final String localeCode;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCard(
      accent: NeonPalette.textMuted,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(
                Icons.location_off_rounded,
                size: 20,
                color: NeonPalette.textMuted,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  t('travel.mapEmpty'),
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            t('travel.mapEmptyHint'),
            style: const TextStyle(
              fontSize: 12,
              height: 1.6,
              color: NeonPalette.textMuted,
            ),
          ),
          if (unplaced > 0) ...[
            const SizedBox(height: 12),
            NeonChip(
              label: t('travel.mapUnplaced', args: {
                'count': DateFormatter.number(unplaced, localeCode),
              },),
              accent: NeonPalette.textMuted,
              icon: Icons.location_off_rounded,
            ),
          ],
        ],
      ),
    );
  }
}

class _Hint extends StatelessWidget {
  const _Hint({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsetsDirectional.only(start: 2, bottom: 4),
        child: Text(
          text,
          style: const TextStyle(fontSize: 12, color: NeonPalette.textMuted),
        ),
      );
}
