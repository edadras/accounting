import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../domain/entities.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';

class AccountsScreen extends ConsumerWidget {
  const AccountsScreen({super.key});

  static IconData _iconFor(AccountType type) => switch (type) {
        AccountType.cash => Icons.payments_rounded,
        AccountType.bank => Icons.account_balance_rounded,
        AccountType.card => Icons.credit_card_rounded,
        AccountType.wallet => Icons.wallet_rounded,
        AccountType.fund => Icons.savings_rounded,
        AccountType.pettyCash => Icons.point_of_sale_rounded,
        AccountType.crypto => Icons.currency_bitcoin_rounded,
        AccountType.gold => Icons.workspace_premium_rounded,
        AccountType.fx => Icons.currency_exchange_rounded,
      };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final currency = ref.watch(baseCurrencyProvider);
    final accounts = ref.watch(accountsProvider);

    return accounts.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (error, _) => Center(child: Text(t('common.error'))),
      data: (list) {
        // Every seeded account shares the base currency here. Once accounts in
        // other currencies exist, this total has to convert first — a plain sum
        // across currencies would be meaningless.
        final total = Money(
          list.fold<int>(0, (sum, a) => sum + a.balance.minorUnits),
          currency,
        );

        return ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 120),
          children: [
            NeonCard(
              accent: NeonPalette.cyan,
              glow: true,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    t('accounts.total'),
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
                      MoneyFormatter.format(total, locale: locale),
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
            const SizedBox(height: 22),
            SectionHeader(title: t('accounts.title')),
            for (final account in list) ...[
              NeonCardShell(
                accent: account.balance.isNegative
                    ? NeonPalette.magenta
                    : NeonPalette.forSeed(account.id),
                padding: const EdgeInsetsDirectional.all(16),
                child: Row(
                  children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        color: NeonPalette.forSeed(account.id).withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(13),
                        border: Border.all(
                          color: NeonPalette.forSeed(account.id).withValues(alpha: 0.3),
                        ),
                      ),
                      child: Icon(
                        _iconFor(account.type),
                        size: 20,
                        color: NeonPalette.forSeed(account.id),
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            account.name,
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
                            t('accounts.${account.type.name}'),
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: NeonPalette.textMuted,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Text(
                      MoneyFormatter.format(account.balance, locale: locale),
                      style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: account.balance.isNegative
                            ? NeonPalette.magenta
                            : NeonPalette.textPrimary,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 10),
            ],
          ],
        );
      },
    );
  }
}
