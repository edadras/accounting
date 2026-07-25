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

/// The screen the travel module exists for.
///
/// Settlement comes first and glows; the expense list is below it. Everyone
/// already knows what they spent — what they want is one line telling them who
/// to pay.
class TripDetailScreen extends ConsumerWidget {
  const TripDetailScreen({super.key, required this.trip});

  final Trip trip;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final code = locale.code;
    final transfers = trip.settlements();
    final balances = trip.balances();

    return ModulePage(
      title: trip.name,
      subtitle: trip.destination,
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: t('travel.spent'),
                  value: MoneyFormatter.format(trip.total,
                      locale: code, compact: true,),
                  accent: NeonPalette.cyan,
                  icon: Icons.payments_rounded,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: StatTile(
                  label: t('travel.perHead'),
                  value: MoneyFormatter.format(trip.perHead,
                      locale: code, compact: true,),
                  accent: NeonPalette.violet,
                  icon: Icons.group_rounded,
                ),
              ),
            ],
          ),
          const SizedBox(height: 22),
          SectionHeader(
            title: t('travel.settlement'),
            accent: transfers.isEmpty ? NeonPalette.lime : NeonPalette.amber,
          ),
          NeonCard(
            accent: transfers.isEmpty ? NeonPalette.lime : NeonPalette.amber,
            glow: true,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  transfers.isEmpty
                      ? t('travel.allSettled')
                      : t('travel.settlementHint', args: {
                          'count':
                              DateFormatter.number(transfers.length, code),
                        },),
                  style: const TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                if (transfers.isNotEmpty) const SizedBox(height: 16),
                for (final transfer in transfers)
                  SettlementRow(
                    transfer: transfer,
                    localeCode: code,
                    label: t('travel.transfer', args: {
                      'from': transfer.from.name,
                      'to': transfer.to.name,
                    },),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          SectionHeader(title: t('travel.balances'), accent: NeonPalette.cyan),
          NeonCard(
            padding: const EdgeInsetsDirectional.symmetric(
              horizontal: 16,
              vertical: 8,
            ),
            child: Column(
              children: [
                for (final balance in balances)
                  _BalanceRow(balance: balance, localeCode: code, t: t),
              ],
            ),
          ),
          const SizedBox(height: 22),
          SectionHeader(title: t('travel.expenses'), accent: NeonPalette.violet),
          NeonCard(
            accent: NeonPalette.violet,
            padding: const EdgeInsetsDirectional.symmetric(
              horizontal: 16,
              vertical: 8,
            ),
            child: Column(
              children: [
                for (final expense in trip.expenses)
                  _ExpenseRow(
                    expense: expense,
                    trip: trip,
                    localeCode: code,
                    locale: locale,
                    t: t,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// "Mina → Sara  ₺2,700". One row, one payment, no arithmetic left for the
/// reader. Public so a test can count the suggested transfers.
class SettlementRow extends StatelessWidget {
  const SettlementRow({
    super.key,
    required this.transfer,
    required this.localeCode,
    required this.label,
  });

  final SettlementTransfer transfer;
  final String localeCode;

  /// Spoken form for screen readers, where an arrow means nothing.
  final String label;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Semantics(
      label: '$label '
          '${MoneyFormatter.format(transfer.amount, locale: localeCode)}',
      child: Padding(
        padding: const EdgeInsetsDirectional.only(bottom: 10),
        child: Row(
          children: [
            _Avatar(name: transfer.from.name, accent: NeonPalette.magenta),
            const SizedBox(width: 10),
            Expanded(
              child: Row(
                children: [
                  Flexible(
                    child: Text(
                      transfer.from.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                  ),
                  const Padding(
                    padding: EdgeInsetsDirectional.symmetric(horizontal: 8),
                    // A directional arrow so the payment reads left-to-right in
                    // English and right-to-left in Persian without relabelling.
                    child: Icon(
                      Icons.arrow_forward_rounded,
                      size: 15,
                      color: NeonPalette.amber,
                    ),
                  ),
                  Flexible(
                    child: Text(
                      transfer.to.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: isDark
                            ? NeonPalette.textPrimary
                            : NeonPalette.lightTextPrimary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 10),
            Text(
              MoneyFormatter.format(transfer.amount, locale: localeCode),
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w800,
                color: NeonPalette.amber,
                shadows:
                    isDark ? NeonEffects.textGlow(NeonPalette.amber, intensity: 0.5) : null,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name, required this.accent});

  final String name;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final seedAccent = NeonPalette.forSeed(name);

    return Container(
      width: 30,
      height: 30,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: seedAccent.withValues(alpha: 0.14),
        border: Border.all(color: seedAccent.withValues(alpha: 0.4)),
      ),
      child: Text(
        name.characters.first,
        style: TextStyle(
          fontSize: 13,
          fontWeight: FontWeight.w700,
          color: seedAccent,
        ),
      ),
    );
  }
}

class _BalanceRow extends StatelessWidget {
  const _BalanceRow({
    required this.balance,
    required this.localeCode,
    required this.t,
  });

  final MemberBalance balance;
  final String localeCode;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = balance.isCreditor
        ? NeonPalette.lime
        : balance.isDebtor
            ? NeonPalette.magenta
            : NeonPalette.textMuted;
    final stateKey = balance.isCreditor
        ? 'travel.isOwed'
        : balance.isDebtor
            ? 'travel.owes'
            : 'travel.even';

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 9),
      child: Row(
        children: [
          _Avatar(name: balance.member.name, accent: accent),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  balance.member.name,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${t('travel.paidLabel')} '
                  '${MoneyFormatter.format(balance.paid, locale: localeCode, compact: true)}'
                  '  ·  ${t('travel.shareLabel')} '
                  '${MoneyFormatter.format(balance.owed, locale: localeCode, compact: true)}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 10.5,
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
                MoneyFormatter.formatSigned(balance.balance, locale: localeCode),
                style: TextStyle(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w800,
                  color: accent,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                t(stateKey),
                style: TextStyle(fontSize: 10.5, color: accent),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ExpenseRow extends StatelessWidget {
  const _ExpenseRow({
    required this.expense,
    required this.trip,
    required this.localeCode,
    required this.locale,
    required this.t,
  });

  final SplitExpense expense;
  final Trip trip;
  final String localeCode;
  final AppLocale locale;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final payer = trip.memberById(expense.payerId);
    final share = expense.shares(trip.members)[expense.payerId];

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 9),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  expense.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  '${t('travel.paidBy')} ${payer?.name ?? ''} · '
                  '${DateFormatter.short(expense.occurredAt, locale)}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                MoneyFormatter.format(expense.amount, locale: localeCode),
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w800,
                  color: NeonPalette.violet,
                ),
              ),
              if (share != null) ...[
                const SizedBox(height: 3),
                Text(
                  '${t('travel.shareLabel')} '
                  '${MoneyFormatter.format(share, locale: localeCode, compact: true)}',
                  style: const TextStyle(
                    fontSize: 10.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}
