import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../domain/entities.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../../widgets/transaction_row.dart';
import '../more/more_screen.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final summary = ref.watch(summaryProvider);
    final categories = ref.watch(categoriesProvider).valueOrNull ?? const <Category>[];

    return summary.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (error, _) => Center(child: Text(t('common.error'))),
      data: (data) => ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 120),
        children: [
          _NetWorthCard(summary: data, locale: locale, label: t('dashboard.netWorth')),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: t('dashboard.today'),
                  value: MoneyFormatter.format(data.spentToday, locale: locale, compact: true),
                  accent: NeonPalette.magenta,
                  icon: Icons.today_rounded,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: StatTile(
                  label: t('dashboard.thisMonth'),
                  value: MoneyFormatter.format(data.spentThisMonth, locale: locale, compact: true),
                  accent: NeonPalette.violet,
                  icon: Icons.calendar_month_rounded,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: StatTile(
                  label: t('dashboard.income'),
                  value: MoneyFormatter.format(data.earnedThisMonth, locale: locale, compact: true),
                  accent: NeonPalette.lime,
                  icon: Icons.trending_up_rounded,
                ),
              ),
            ],
          ),
          const SizedBox(height: 22),
          if (data.byCategory.isNotEmpty) ...[
            _InsightCard(summary: data, locale: locale, label: t('dashboard.aiInsight')),
            const SizedBox(height: 22),
            SectionHeader(title: t('dashboard.spendingByCategory'), accent: NeonPalette.violet),
            NeonCard(
              accent: NeonPalette.violet,
              child: NeonBarChart(
                rows: [
                  for (final slice in data.byCategory)
                    NeonBarRow(
                      label: slice.label,
                      value: slice.amount.minorUnits.toDouble(),
                      formattedValue:
                          MoneyFormatter.format(slice.amount, locale: locale, compact: true),
                      accent: NeonPalette.forSeed(slice.categoryId),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 22),
          ],
          SectionHeader(title: t('dashboard.recent'), accent: NeonPalette.cyan),
          NeonCard(
            padding: const EdgeInsetsDirectional.symmetric(horizontal: 12, vertical: 8),
            child: Column(
              children: [
                for (final transaction in data.recent)
                  TransactionRow(
                    transaction: transaction,
                    locale: locale,
                    categoryLabel: categories
                        .where((c) => c.id == transaction.categoryId)
                        .map((c) => c.name)
                        .firstOrNull,
                  ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          // Entry point to the six vertical modules. It sits at the foot of the
          // dashboard rather than in the bottom bar: five tabs is already the
          // most a thumb can aim at, and these are deliberate visits.
          NeonCardShell(
            key: const ValueKey('more-hub-entry'),
            accent: NeonPalette.violet,
            padding: const EdgeInsetsDirectional.all(18),
            onTap: () => MoreScreen.open(context),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: NeonPalette.violet.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Icon(
                    Icons.apps_rounded,
                    size: 19,
                    color: NeonPalette.violet,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        t('more.title'),
                        style: const TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w700,
                          color: NeonPalette.textPrimary,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        t('more.hint'),
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
                Icon(
                  Directionality.of(context) == TextDirection.rtl
                      ? Icons.chevron_left_rounded
                      : Icons.chevron_right_rounded,
                  size: 20,
                  color: NeonPalette.violet,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The one element on the screen that glows at full strength.
class _NetWorthCard extends StatelessWidget {
  const _NetWorthCard({
    required this.summary,
    required this.locale,
    required this.label,
  });

  final DashboardSummary summary;
  final String locale;
  final String label;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final positive = !summary.netWorth.isNegative;
    final accent = positive ? NeonPalette.cyan : NeonPalette.magenta;

    return NeonCard(
      accent: accent,
      glow: true,
      padding: const EdgeInsetsDirectional.all(22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 7,
                height: 7,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: accent,
                  boxShadow: isDark ? NeonEffects.glowTight(accent) : null,
                ),
              ),
              const SizedBox(width: 8),
              Text(
                label,
                style: const TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.4,
                  color: NeonPalette.textSecondary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(
              MoneyFormatter.format(summary.netWorth, locale: locale),
              style: TextStyle(
                fontSize: 38,
                fontWeight: FontWeight.w800,
                height: 1.05,
                letterSpacing: -0.5,
                color: isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
                shadows: isDark ? NeonEffects.textGlow(accent) : null,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Statistical, not generative: the share is computed from the ledger, and the
/// LLM layer in M5 only rewords it. Cheaper, and it cannot hallucinate a number.
class _InsightCard extends StatelessWidget {
  const _InsightCard({
    required this.summary,
    required this.locale,
    required this.label,
  });

  final DashboardSummary summary;
  final String locale;
  final String label;

  @override
  Widget build(BuildContext context) {
    final share = (summary.topCategoryShare * 100).round();
    final top = summary.byCategory.first;

    return NeonCard(
      accent: NeonPalette.violet,
      padding: const EdgeInsetsDirectional.all(18),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              gradient: NeonEffects.hero(NeonPalette.violet, NeonPalette.cyan),
              borderRadius: BorderRadius.circular(11),
            ),
            child: const Icon(Icons.auto_awesome_rounded, size: 18, color: Colors.white),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 0.4,
                    color: NeonPalette.violet,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  '$share٪ — ${top.label}',
                  style: const TextStyle(
                    fontSize: 14,
                    height: 1.5,
                    fontWeight: FontWeight.w600,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  MoneyFormatter.format(top.amount, locale: locale),
                  style: const TextStyle(fontSize: 12, color: NeonPalette.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
