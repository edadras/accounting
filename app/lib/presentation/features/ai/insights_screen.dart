import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/ai/ai_models.dart';
import '../../widgets/neon_widgets.dart';
import 'ai_providers.dart';

class AiInsightsScreen extends ConsumerWidget {
  const AiInsightsScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const AiInsightsScreen());

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('ai.insights.title'))),
        body: const Padding(
          padding: EdgeInsetsDirectional.fromSTEB(16, 8, 16, 24),
          child: AiInsightsList(),
        ),
      ),
    );
  }
}

/// The insights list, usable on its own screen or embedded in the hub.
///
/// These are statistics, not generated prose: the numbers come from the ledger
/// (docs/08-ai-layer.md §6), which is both cheaper and impossible to
/// hallucinate.
class AiInsightsList extends ConsumerWidget {
  const AiInsightsList({super.key, this.shrinkWrap = false, this.limit});

  final bool shrinkWrap;
  final int? limit;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final insights = ref.watch(aiInsightsProvider);
    final dismissed = ref.watch(dismissedInsightsProvider);

    return insights.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (error, _) => Center(child: Text(t('common.error'))),
      data: (all) {
        var visible = all.where((i) => !dismissed.contains(i.id)).toList();
        if (limit != null && visible.length > limit!) {
          visible = visible.take(limit!).toList();
        }

        if (visible.isEmpty) {
          return Padding(
            padding: const EdgeInsetsDirectional.symmetric(vertical: 24),
            child: Text(
              t('ai.insights.empty'),
              style: const TextStyle(fontSize: 13, color: NeonPalette.textMuted),
            ),
          );
        }

        return ListView(
          shrinkWrap: shrinkWrap,
          physics: shrinkWrap ? const NeverScrollableScrollPhysics() : null,
          padding: EdgeInsetsDirectional.zero,
          children: [
            for (final insight in visible)
              Padding(
                padding: const EdgeInsetsDirectional.only(bottom: 12),
                child: Dismissible(
                  key: ValueKey(insight.id),
                  direction: DismissDirection.endToStart,
                  background: const _DismissBackground(),
                  onDismissed: (_) {
                    ref.read(dismissedInsightsProvider.notifier).state = {
                      ...dismissed,
                      insight.id,
                    };
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(t('ai.insights.dismissed'))),
                    );
                  },
                  child: _InsightCard(insight: insight, locale: locale),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _InsightCard extends ConsumerWidget {
  const _InsightCard({required this.insight, required this.locale});

  final AiInsight insight;
  final String locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    final (Color accent, IconData icon) = switch (insight.severity) {
      InsightSeverity.positive => (NeonPalette.lime, Icons.trending_up_rounded),
      InsightSeverity.info => (NeonPalette.violet, Icons.auto_awesome_rounded),
      InsightSeverity.warning => (NeonPalette.amber, Icons.warning_amber_rounded),
      InsightSeverity.critical => (NeonPalette.magenta, Icons.priority_high_rounded),
    };

    final message = t(
      insight.messageKey,
      args: {
        ...insight.args,
        if (insight.amount != null)
          'amount': MoneyFormatter.format(insight.amount!, locale: locale),
      },
    );

    return NeonCardShell(
      accent: accent,
      // Only the two severities that need acting on carry a glow.
      glow: insight.severity == InsightSeverity.critical ||
          insight.severity == InsightSeverity.warning,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(10),
              border: NeonEffects.border(accent, alpha: 0.35),
            ),
            child: Icon(icon, size: 16, color: accent),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(
                fontSize: 13,
                height: 1.6,
                fontWeight: FontWeight.w600,
                color: NeonPalette.textPrimary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DismissBackground extends StatelessWidget {
  const _DismissBackground();

  @override
  Widget build(BuildContext context) {
    return Container(
      alignment: AlignmentDirectional.centerEnd,
      padding: const EdgeInsetsDirectional.only(end: 20),
      decoration: BoxDecoration(
        color: NeonPalette.surfaceHigh,
        borderRadius: BorderRadius.circular(NeonEffects.radiusMd),
      ),
      child: const Icon(Icons.close_rounded, size: 18, color: NeonPalette.textMuted),
    );
  }
}
