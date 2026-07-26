import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/billing_repository.dart';
import '../../../data/modules_repository.dart' show clockProvider;
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'billing_dialogs.dart';
import 'billing_providers.dart';
import 'invoices_screen.dart';

/// The plan this workspace is on, what it costs, and every way to change it.
class BillingScreen extends ConsumerWidget {
  const BillingScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const BillingScreen());

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final subscription = ref.watch(subscriptionProvider);

    return ModulePage(
      title: t('billing.title'),
      subtitle: t('billing.subtitle'),
      child: subscription.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('billing.noAccessTitle'),
          permissionBody: t('billing.noAccessBody'),
        ),
        data: (snapshot) => _BillingBody(snapshot: snapshot),
      ),
    );
  }
}

class _BillingBody extends ConsumerWidget {
  const _BillingBody({required this.snapshot});

  final BillingSnapshot snapshot;

  /// Runs a write, then refetches both the subscription and the invoices.
  ///
  /// Refetching rather than patching: the server decides what a cancel or a
  /// plan change actually did — including which date it left on the row — and
  /// a locally guessed copy of that is exactly what this screen must not show.
  static Future<void> _run(
    BuildContext context,
    WidgetRef ref,
    Future<void> Function() action,
  ) async {
    try {
      await action();
      ref
        ..invalidate(subscriptionProvider)
        ..invalidate(invoicesProvider);
    } on BillingFailure catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(
          SnackBar(content: Text(ref.read(translatorProvider)(
            error.translationKey,
          ),),),
        );
    }
  }

  Future<void> _cancel(
    BuildContext context,
    WidgetRef ref,
    List<BillingPlan> plans,
  ) async {
    final subscription = snapshot.subscription;

    final confirmed = await confirmCancellation(
      context,
      subscription: subscription,
      currentPlan: _planByCode(plans, subscription.planCode),
      fallbackPlan: _fallbackPlan(plans),
      now: ref.read(clockProvider),
    );

    if (!confirmed || !context.mounted) return;

    await _run(context, ref, ref.read(billingRepositoryProvider).cancel);
  }

  Future<void> _choose(
    BuildContext context,
    WidgetRef ref,
    BillingPlan plan, {
    required bool isDowngrade,
  }) async {
    final confirmed =
        await confirmPlanChange(context, plan: plan, isDowngrade: isDowngrade);
    if (!confirmed || !context.mounted) return;

    await _run(
      context,
      ref,
      () => ref.read(billingRepositoryProvider).subscribe(plan.code),
    );
  }

  Future<void> _trial(
    BuildContext context,
    WidgetRef ref,
    BillingPlan plan,
  ) async {
    final confirmed = await confirmTrial(context, plan: plan);
    if (!confirmed || !context.mounted) return;

    await _run(
      context,
      ref,
      () => ref.read(billingRepositoryProvider).startTrial(plan.code),
    );
  }

  static BillingPlan? _planByCode(List<BillingPlan> plans, String code) {
    for (final plan in plans) {
      if (plan.code == code) return plan;
    }
    return null;
  }

  /// The plan everything degrades to. The catalogue is ordered by rank, so the
  /// first row is the least capable one — which is what `default_plan` is
  /// required to be.
  static BillingPlan? _fallbackPlan(List<BillingPlan> plans) =>
      plans.isEmpty ? null : plans.first;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final now = ref.watch(clockProvider);
    final plans = ref.watch(billingPlansProvider);
    final subscription = snapshot.subscription;

    final catalogue = plans.asData?.value ?? const <BillingPlan>[];

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        _CurrentPlanCard(
          snapshot: snapshot,
          plan: _planByCode(catalogue, subscription.planCode),
          now: now,
        ),
        const SizedBox(height: 16),
        if (snapshot.meteredUsage.isNotEmpty) ...[
          SectionHeader(title: t('billing.usageTitle')),
          for (final row in snapshot.meteredUsage) ...[
            _UsageRow(row: row, locale: locale, t: t),
            const SizedBox(height: 10),
          ],
          const SizedBox(height: 8),
        ],
        Row(
          children: [
            Expanded(
              child: NeonButton(
                key: const ValueKey('billing-invoices'),
                label: t('billing.invoices'),
                icon: Icons.receipt_rounded,
                variant: NeonButtonVariant.outline,
                expand: true,
                onPressed: () =>
                    Navigator.of(context).push(InvoicesScreen.route()),
              ),
            ),
            if (!subscription.isCancelled) ...[
              const SizedBox(width: 10),
              Expanded(
                child: NeonButton(
                  key: const ValueKey('billing-cancel'),
                  label: t('billing.cancel'),
                  icon: Icons.cancel_outlined,
                  variant: NeonButtonVariant.outline,
                  accent: NeonPalette.magenta,
                  expand: true,
                  onPressed: () => _cancel(context, ref, catalogue),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 24),
        SectionHeader(title: t('billing.plansTitle')),
        plans.when(
          loading: () => const ModuleLoading(),
          error: (error, _) => ModuleError(
            message: error is BillingFailure
                ? t(error.translationKey)
                : t('common.error'),
          ),
          data: (list) => Column(
            children: [
              for (var i = 0; i < list.length; i++) ...[
                _PlanCard(
                  plan: list[i],
                  locale: locale,
                  t: t,
                  isCurrent: list[i].code == subscription.effectivePlanCode,
                  isDowngrade: i <
                      _rankOf(list, subscription.effectivePlanCode),
                  canTrial: !subscription.hasUsedTrial && !list[i].isFree,
                  onChoose: () => _choose(
                    context,
                    ref,
                    list[i],
                    isDowngrade:
                        i < _rankOf(list, subscription.effectivePlanCode),
                  ),
                  onTrial: () => _trial(context, ref, list[i]),
                ),
                const SizedBox(height: 12),
              ],
            ],
          ),
        ),
      ],
    );
  }

  static int _rankOf(List<BillingPlan> plans, String code) {
    for (var i = 0; i < plans.length; i++) {
      if (plans[i].code == code) return i;
    }
    return 0;
  }
}

/// The plan in force, and the one date that matters about it.
class _CurrentPlanCard extends ConsumerWidget {
  const _CurrentPlanCard({
    required this.snapshot,
    required this.plan,
    required this.now,
  });

  final BillingSnapshot snapshot;
  final BillingPlan? plan;
  final DateTime now;

  Color _accent(SubscriptionStatus status, {required bool isDark}) =>
      switch (status) {
        SubscriptionStatus.active => isDark
            ? NeonPalette.cyan
            : NeonPalette.lightCyan,
        SubscriptionStatus.trialing => NeonPalette.lime,
        SubscriptionStatus.pastDue => NeonPalette.amber,
        SubscriptionStatus.cancelled => NeonPalette.magenta,
      };

  /// The single line that says when anything changes.
  ///
  /// A cancelled subscription inside its paid period says so with its date —
  /// the screen never claims access has already gone when the server is still
  /// serving it, and never claims it continues when the row says otherwise.
  String _timing(Translator t, AppLocale locale) {
    final subscription = snapshot.subscription;
    final trialEnds = subscription.trialEndsAt;
    final renews = subscription.renewsAt;

    if (subscription.isCancelled) {
      return subscription.keepsAccessUntilPeriodEnd(now)
          ? t(
              'billing.cancelledUntil',
              args: {'date': DateFormatter.short(renews!, locale)},
            )
          : t('billing.cancelledEnded');
    }

    if (subscription.onTrial && trialEnds != null) {
      return t(
        'billing.trialEnds',
        args: {'date': DateFormatter.short(trialEnds, locale)},
      );
    }

    if (renews != null) {
      return t(
        'billing.renewsOn',
        args: {'date': DateFormatter.short(renews, locale)},
      );
    }

    return t('billing.noRenewal');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final subscription = snapshot.subscription;
    final accent = _accent(subscription.status, isDark: isDark);

    final effective = planLabel(
      t,
      subscription.effectivePlanCode,
      fallback: plan?.code,
    );

    return NeonCard(
      key: const ValueKey('billing-current-plan'),
      accent: accent,
      glow: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  t('billing.currentPlan'),
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.3,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              NeonChip(
                key: const ValueKey('billing-status'),
                label: t(subscription.status.labelKey),
                accent: accent,
                selected: true,
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            effective,
            key: const ValueKey('billing-plan-name'),
            style: TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.w800,
              height: 1.1,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            _timing(t, locale),
            key: const ValueKey('billing-timing'),
            style: TextStyle(
              fontSize: 12.5,
              height: 1.6,
              fontWeight: FontWeight.w600,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
          // The row still names the plan it was bought on while the entitlements
          // have already dropped back. Saying both is the only honest reading.
          if (subscription.effectivePlanCode != subscription.planCode) ...[
            const SizedBox(height: 8),
            Text(
              t(
                'billing.effectiveNote',
                args: {
                  'named': planLabel(t, subscription.planCode),
                  'effective': effective,
                },
              ),
              style: TextStyle(
                fontSize: 11.5,
                height: 1.5,
                color: isDark
                    ? NeonPalette.textMuted
                    : NeonPalette.lightTextSecondary,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _UsageRow extends StatelessWidget {
  const _UsageRow({required this.row, required this.locale, required this.t});

  final ({String key, int used, int limit}) row;
  final AppLocale locale;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final atLimit = row.used >= row.limit;
    final accent = atLimit
        ? NeonPalette.amber
        : (isDark ? NeonPalette.cyan : NeonPalette.lightCyan);

    return NeonCardShell(
      key: ValueKey('billing-usage-${row.key}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  t('billing.limit.${row.key}'),
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: isDark
                        ? NeonPalette.textSecondary
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ),
              Text(
                t('billing.usageOf', args: {
                  'used': DateFormatter.number(row.used, locale.code),
                  'limit': DateFormatter.number(row.limit, locale.code),
                },),
                style: TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: isDark
                      ? NeonPalette.textPrimary
                      : NeonPalette.lightTextPrimary,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          NeonMeter(
            fraction: row.limit == 0 ? 1 : row.used / row.limit,
            accent: accent,
          ),
        ],
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.plan,
    required this.locale,
    required this.t,
    required this.isCurrent,
    required this.isDowngrade,
    required this.canTrial,
    required this.onChoose,
    required this.onTrial,
  });

  final BillingPlan plan;
  final AppLocale locale;
  final Translator t;
  final bool isCurrent;
  final bool isDowngrade;
  final bool canTrial;
  final VoidCallback onChoose;
  final VoidCallback onTrial;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = isCurrent
        ? (isDark ? NeonPalette.cyan : NeonPalette.lightCyan)
        : (isDark ? NeonPalette.violet : NeonPalette.lightViolet);

    final name = planLabel(t, plan.code, fallback: plan.name);

    return NeonCardShell(
      key: ValueKey('billing-plan-${plan.code}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  name,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              if (isCurrent)
                NeonChip(
                  label: t('billing.currentBadge'),
                  accent: accent,
                  selected: true,
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            plan.isFree
                ? t('billing.priceFree')
                : t('billing.priceEvery', args: {
                    'price':
                        MoneyFormatter.format(plan.price, locale: locale.code),
                    'interval': t(plan.interval.labelKey),
                  },),
            style: TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w700,
              color: accent,
            ),
          ),
          const SizedBox(height: 12),
          for (final entry in plan.limits.entries)
            _PlanLine(
              text: entry.value == null
                  ? t('billing.limitUnlimited',
                      args: {'name': t('billing.limit.${entry.key}')},)
                  : t('billing.limitCount', args: {
                      'name': t('billing.limit.${entry.key}'),
                      'count':
                          DateFormatter.number(entry.value!, locale.code),
                    },),
            ),
          if (plan.includedFeatures.isNotEmpty) ...[
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final feature in plan.includedFeatures)
                  NeonChip(
                    label: t('billing.feature.$feature'),
                    accent: accent,
                  ),
              ],
            ),
          ],
          if (!isCurrent) ...[
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: NeonButton(
                    key: ValueKey('billing-choose-${plan.code}'),
                    label: isDowngrade
                        ? t('billing.chooseDown')
                        : t('billing.chooseUp'),
                    variant: NeonButtonVariant.outline,
                    accent: accent,
                    expand: true,
                    onPressed: onChoose,
                  ),
                ),
                if (canTrial) ...[
                  const SizedBox(width: 10),
                  Expanded(
                    child: NeonButton(
                      key: ValueKey('billing-trial-${plan.code}'),
                      label: t('billing.startTrial'),
                      variant: NeonButtonVariant.ghost,
                      accent: NeonPalette.lime,
                      expand: true,
                      onPressed: onTrial,
                    ),
                  ),
                ],
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _PlanLine extends StatelessWidget {
  const _PlanLine({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 3),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 11.5,
          height: 1.5,
          color:
              isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}
