import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/billing_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import 'billing_providers.dart';

/// Asks before cancelling, and answers the three questions a cancellation
/// screen owes the person reading it: what stops working, on what date, and
/// what happens to the data.
///
/// The date is not decoration and is never omitted. `CancelSubscription` keeps
/// the paid plan alive until `renews_at` and only a trial is cut off at once —
/// a dialog that said "your plan ends now" would be wrong in the common case,
/// and one that said nothing about the date is the one people report as a dark
/// pattern.
Future<bool> confirmCancellation(
  BuildContext context, {
  required Subscription subscription,
  required BillingPlan? currentPlan,
  required BillingPlan? fallbackPlan,
  required DateTime now,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => _CancelDialog(
      subscription: subscription,
      currentPlan: currentPlan,
      fallbackPlan: fallbackPlan,
      now: now,
    ),
  );

  return confirmed ?? false;
}

class _CancelDialog extends ConsumerWidget {
  const _CancelDialog({
    required this.subscription,
    required this.currentPlan,
    required this.fallbackPlan,
    required this.now,
  });

  final Subscription subscription;
  final BillingPlan? currentPlan;
  final BillingPlan? fallbackPlan;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    final outcome = subscription.cancellationOutcome(now);
    final current = planLabel(
      t,
      subscription.planCode,
      fallback: currentPlan?.name,
    );
    final fallback = planLabel(
      t,
      fallbackPlan?.code ?? 'free',
      fallback: fallbackPlan?.name,
    );

    // Only computable when both plans are on hand. Without them the dialog says
    // less rather than guessing at a list of features.
    final lost = currentPlan == null || fallbackPlan == null
        ? const <String>[]
        : fallbackPlan!.featuresLostAgainst(currentPlan!);

    return _DialogShell(
      accent: NeonPalette.magenta,
      children: [
        _Title(t('billing.cancelTitle', args: {'plan': current})),
        const SizedBox(height: 14),
        _WhenPanel(
          key: const ValueKey('billing-cancel-when'),
          text: outcome.isImmediate
              ? (subscription.status == SubscriptionStatus.trialing
                  ? t('billing.cancelTrialNow', args: {'plan': fallback})
                  : t('billing.cancelNow', args: {'plan': fallback}))
              : t(
                  'billing.cancelKeepsUntil',
                  args: {
                    'plan': current,
                    'date': DateFormatter.short(outcome.endsAt!, locale),
                    'fallback': fallback,
                  },
                ),
        ),
        const SizedBox(height: 14),
        _Body(
          lost.isEmpty
              ? t('billing.cancelLosesNothing', args: {'plan': fallback})
              : t('billing.cancelLoses', args: {'plan': fallback}),
        ),
        if (lost.isNotEmpty) ...[
          const SizedBox(height: 8),
          for (final feature in lost)
            _Bullet(text: t('billing.feature.$feature')),
        ],
        const SizedBox(height: 14),
        _Body(t('billing.cancelDataKept')),
        const SizedBox(height: 20),
        _Actions(
          accent: NeonPalette.magenta,
          cancelLabel: t('billing.cancelKeepPlan'),
          confirmLabel: t('billing.cancelConfirm'),
          confirmKey: const ValueKey('billing-cancel-confirm'),
          onConfirm: () => Navigator.of(context).pop(true),
        ),
      ],
    );
  }
}

/// Asks before a plan change, which the server performs immediately.
///
/// `ChangePlan` charges and switches in the same request, in both directions —
/// there is no end-of-period grace here, so the wording says "now" and means
/// it. Presenting this as deferred would be the mirror image of the mistake the
/// cancel dialog avoids.
Future<bool> confirmPlanChange(
  BuildContext context, {
  required BillingPlan plan,
  required bool isDowngrade,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) =>
        _PlanChangeDialog(plan: plan, isDowngrade: isDowngrade),
  );

  return confirmed ?? false;
}

class _PlanChangeDialog extends ConsumerWidget {
  const _PlanChangeDialog({required this.plan, required this.isDowngrade});

  final BillingPlan plan;
  final bool isDowngrade;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final name = planLabel(t, plan.code, fallback: plan.name);

    return _DialogShell(
      accent: isDowngrade ? NeonPalette.amber : NeonPalette.cyan,
      children: [
        _Title(t('billing.changeTitle', args: {'plan': name})),
        const SizedBox(height: 14),
        _WhenPanel(
          key: const ValueKey('billing-change-when'),
          accent: isDowngrade ? NeonPalette.amber : NeonPalette.cyan,
          text: plan.isFree
              ? t('billing.changeFreeNow', args: {'plan': name})
              : t(
                  'billing.changeChargedNow',
                  args: {
                    'price': MoneyFormatter.format(
                      plan.price,
                      locale: locale.code,
                    ),
                    'interval': t(plan.interval.labelKey),
                  },
                ),
        ),
        if (isDowngrade) ...[
          const SizedBox(height: 14),
          _Body(t('billing.changeDowngradeNote')),
        ],
        const SizedBox(height: 20),
        _Actions(
          accent: isDowngrade ? NeonPalette.amber : NeonPalette.cyan,
          cancelLabel: t('common.cancel'),
          confirmLabel: t('billing.changeConfirm'),
          confirmKey: const ValueKey('billing-change-confirm'),
          onConfirm: () => Navigator.of(context).pop(true),
        ),
      ],
    );
  }
}

/// Asks before a trial starts, saying what it ends in.
Future<bool> confirmTrial(
  BuildContext context, {
  required BillingPlan plan,
}) async {
  final confirmed = await showDialog<bool>(
    context: context,
    builder: (context) => _TrialDialog(plan: plan),
  );

  return confirmed ?? false;
}

class _TrialDialog extends ConsumerWidget {
  const _TrialDialog({required this.plan});

  final BillingPlan plan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final name = planLabel(t, plan.code, fallback: plan.name);

    return _DialogShell(
      accent: NeonPalette.lime,
      children: [
        _Title(t('billing.trialTitle', args: {'plan': name})),
        const SizedBox(height: 14),
        _WhenPanel(
          key: const ValueKey('billing-trial-when'),
          accent: NeonPalette.lime,
          text: t('billing.trialBody', args: {'plan': name}),
        ),
        const SizedBox(height: 14),
        _Body(t('billing.trialOnce')),
        const SizedBox(height: 20),
        _Actions(
          accent: NeonPalette.lime,
          cancelLabel: t('common.cancel'),
          confirmLabel: t('billing.trialConfirm'),
          confirmKey: const ValueKey('billing-trial-confirm'),
          onConfirm: () => Navigator.of(context).pop(true),
        ),
      ],
    );
  }
}

/// The one sentence the dialog exists for, framed so it cannot be skimmed past.
class _WhenPanel extends StatelessWidget {
  const _WhenPanel({
    super.key,
    required this.text,
    this.accent = NeonPalette.magenta,
  });

  final String text;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      width: double.infinity,
      padding: const EdgeInsetsDirectional.all(12),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: accent.withValues(alpha: 0.35)),
      ),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 13,
          height: 1.6,
          fontWeight: FontWeight.w600,
          color:
              isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
        ),
      ),
    );
  }
}

class _Bullet extends StatelessWidget {
  const _Bullet({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final color =
        isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary;

    return Padding(
      padding: const EdgeInsetsDirectional.only(start: 6, bottom: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.remove_rounded, size: 13, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(fontSize: 12.5, height: 1.5, color: color),
            ),
          ),
        ],
      ),
    );
  }
}

class _DialogShell extends StatelessWidget {
  const _DialogShell({required this.accent, required this.children});

  final Color accent;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: Colors.transparent,
      elevation: 0,
      insetPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 40),
      child: SingleChildScrollView(
        child: NeonCard(
          accent: accent,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: children,
          ),
        ),
      ),
    );
  }
}

class _Title extends StatelessWidget {
  const _Title(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 16,
        fontWeight: FontWeight.w800,
        color: isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 12.5,
        height: 1.6,
        color:
            isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary,
      ),
    );
  }
}

class _Actions extends StatelessWidget {
  const _Actions({
    required this.accent,
    required this.cancelLabel,
    required this.confirmLabel,
    required this.confirmKey,
    required this.onConfirm,
  });

  final Color accent;
  final String cancelLabel;
  final String confirmLabel;
  final Key confirmKey;
  final VoidCallback onConfirm;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        Flexible(
          child: NeonButton(
            label: cancelLabel,
            variant: NeonButtonVariant.ghost,
            accent: NeonPalette.textSecondary,
            onPressed: () => Navigator.of(context).pop(false),
          ),
        ),
        const SizedBox(width: 10),
        Flexible(
          child: NeonButton(
            key: confirmKey,
            label: confirmLabel,
            accent: accent,
            onPressed: onConfirm,
          ),
        ),
      ],
    );
  }
}
