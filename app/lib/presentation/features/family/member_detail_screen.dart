import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/family_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'allowance_dialog.dart';
import 'family_labels.dart';
import 'family_providers.dart';
import 'member_form_screen.dart';

/// One member: what they spent this month against their cap, what they are
/// owed, and every allowance they have ever been paid.
class MemberDetailScreen extends ConsumerWidget {
  const MemberDetailScreen({super.key, required this.memberId});

  final String memberId;

  static Route<void> route(String memberId) => MaterialPageRoute<void>(
        builder: (_) => MemberDetailScreen(memberId: memberId),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final detail = ref.watch(memberDetailProvider(memberId));

    return ModulePage(
      title: detail.valueOrNull?.member.displayName ?? t('family.memberTitle'),
      subtitle: t('family.memberSubtitle'),
      child: detail.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('family.noAccessTitle'),
          permissionBody: t('family.noAccessBody'),
        ),
        data: (detail) => _MemberBody(detail: detail),
      ),
    );
  }
}

class _MemberBody extends ConsumerWidget {
  const _MemberBody({required this.detail});

  final MemberDetail detail;

  Future<void> _edit(BuildContext context, WidgetRef ref) async {
    final saved = await Navigator.of(context).push<bool>(
      MemberFormScreen.route(existing: detail.member),
    );
    if (saved ?? false) {
      ref.invalidate(memberDetailProvider(detail.member.id));
    }
  }

  Future<void> _pay(BuildContext context, WidgetRef ref) async {
    final t = ref.read(translatorProvider);
    final period = ref.read(familyPeriodProvider);

    final household = await ref.read(householdProvider.future);
    if (!context.mounted) return;

    final request = await pickAllowancePayment(
      context,
      member: detail.member,
      payers: household.payersFor(detail.member),
      period: period,
    );
    if (request == null || !context.mounted) return;

    try {
      await ref.read(familyRepositoryProvider).payAllowance(
            memberId: detail.member.id,
            payerMemberId: request.payerId,
            period: request.period,
          );

      ref
        ..invalidate(memberDetailProvider(detail.member.id))
        ..invalidate(householdProvider);
    } on FamilyFailure catch (error) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context)
        ..clearSnackBars()
        ..showSnackBar(SnackBar(content: Text(t(error.translationKey))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final period = ref.watch(familyPeriodProvider);
    final member = detail.member;
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final alreadyPaid = detail.isPaidFor(period);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        _ProfileCard(member: member, locale: locale),
        const SizedBox(height: 18),
        SectionHeader(
          title: t(
            'family.spendingHeading',
            args: {'period': familyPeriodLabel(period, locale.code)},
          ),
        ),
        _SpendingCard(spending: detail.spending, locale: locale),
        const SizedBox(height: 18),
        Row(
          children: [
            Expanded(
              child: NeonButton(
                key: const ValueKey('member-pay-allowance'),
                label: alreadyPaid
                    ? t('family.alreadyPaid')
                    : t('family.payAllowance'),
                icon: Icons.payments_rounded,
                accent: NeonPalette.lime,
                expand: true,
                // The server enforces one payment per member per month with a
                // unique index; offering the button anyway would only produce a
                // refusal the user cannot act on.
                onPressed: alreadyPaid || !member.canBePaid
                    ? null
                    : () => _pay(context, ref),
              ),
            ),
            const SizedBox(width: 10),
            NeonButton(
              key: const ValueKey('member-edit'),
              label: t('family.editMember'),
              icon: Icons.tune_rounded,
              variant: NeonButtonVariant.outline,
              onPressed: () => _edit(context, ref),
            ),
          ],
        ),
        if (!member.canBePaid) ...[
          const SizedBox(height: 10),
          Text(
            member.monthlyAllowance == null
                ? t('family.noAllowanceSet')
                : t('family.noAccountSet'),
            style: TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: isDark
                  ? NeonPalette.textMuted
                  : NeonPalette.lightTextSecondary,
            ),
          ),
        ],
        const SizedBox(height: 22),
        SectionHeader(title: t('family.allowanceHistory')),
        if (detail.payments.isEmpty)
          _EmptyLine(text: t('family.noPayments'))
        else
          for (final payment in detail.payments) ...[
            _PaymentRow(payment: payment, locale: locale),
            const SizedBox(height: 10),
          ],
      ],
    );
  }
}

class _ProfileCard extends ConsumerWidget {
  const _ProfileCard({required this.member, required this.locale});

  final HouseholdMember member;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = familyRoleAccent(member.role, isDark: isDark);
    final allowance = member.monthlyAllowance;
    final cap = member.spendingCap;

    return NeonCardShell(
      key: ValueKey('member-profile-${member.id}'),
      accent: accent,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  member.displayName,
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              NeonChip(
                label: familyRoleLabel(t, member.role),
                accent: accent,
                selected: true,
              ),
            ],
          ),
          const SizedBox(height: 8),
          DetailRow(
            label: t('family.allowance'),
            value: allowance == null
                ? t('family.notSet')
                : MoneyFormatter.format(allowance, locale: locale.code),
          ),
          DetailRow(
            label: t('family.cap'),
            value: cap == null
                ? t('family.notSet')
                : MoneyFormatter.format(cap, locale: locale.code),
          ),
          if (member.birthDate != null)
            DetailRow(
              label: t('family.birthDate'),
              value: DateFormatter.short(member.birthDate!, locale),
            ),
        ],
      ),
    );
  }
}

class _SpendingCard extends ConsumerWidget {
  const _SpendingCard({required this.spending, required this.locale});

  final MemberSpending? spending;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final row = spending;

    if (row == null) {
      return _EmptyLine(text: t('family.noSpendingYet'));
    }

    final overCap = row.isComparable && row.isOverCap;
    final accent = overCap
        ? (isDark ? NeonPalette.magenta : NeonPalette.lightMagenta)
        : (isDark ? NeonPalette.cyan : NeonPalette.lightCyan);

    return NeonCardShell(
      key: const ValueKey('member-spending'),
      accent: accent,
      glow: overCap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          DetailRow(
            label: t('family.spent'),
            value: MoneyFormatter.format(row.spent, locale: locale.code),
            strong: true,
            accent: accent,
          ),
          if (row.isComparable) ...[
            DetailRow(
              label: t('family.cap'),
              value: MoneyFormatter.format(row.cap!, locale: locale.code),
            ),
            DetailRow(
              label: overCap ? t('family.overCap') : t('family.capRemaining'),
              value: MoneyFormatter.format(
                row.remaining!.absolute,
                locale: locale.code,
              ),
              accent: accent,
            ),
            const SizedBox(height: 8),
            NeonMeter(fraction: row.meterFraction, accent: accent),
          ] else
            Padding(
              padding: const EdgeInsetsDirectional.only(top: 6),
              child: Text(
                t('family.capNotComparable'),
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.5,
                  color: isDark
                      ? NeonPalette.textMuted
                      : NeonPalette.lightTextSecondary,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _PaymentRow extends ConsumerWidget {
  const _PaymentRow({required this.payment, required this.locale});

  final AllowancePayment payment;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCardShell(
      key: ValueKey('allowance-payment-${payment.id}'),
      accent: isDark ? NeonPalette.lime : NeonPalette.lightLime,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  familyPeriodLabel(payment.period, locale.code),
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  payment.paidAt == null
                      ? t('family.paidUnknown')
                      : t('family.paidOn', args: {
                          'date': DateFormatter.short(payment.paidAt!, locale),
                        },),
                  style: TextStyle(
                    fontSize: 11,
                    color: isDark
                        ? NeonPalette.textMuted
                        : NeonPalette.lightTextSecondary,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Text(
            MoneyFormatter.format(payment.amount, locale: locale.code),
            style: TextStyle(
              fontSize: 13.5,
              fontWeight: FontWeight.w800,
              color: isDark ? NeonPalette.lime : NeonPalette.lightLime,
            ),
          ),
        ],
      ),
    );
  }
}

class _EmptyLine extends StatelessWidget {
  const _EmptyLine({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 12),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 12.5,
          color: isDark
              ? NeonPalette.textMuted
              : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}
