import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/banking.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'loan_detail_screen.dart';

/// Cheques and loans — the two things in this app that carry a deadline.
class BankingScreen extends ConsumerStatefulWidget {
  const BankingScreen({super.key});

  @override
  ConsumerState<BankingScreen> createState() => _BankingScreenState();
}

class _BankingScreenState extends ConsumerState<BankingScreen> {
  bool _showLoans = false;

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);

    return ModulePage(
      title: t('module.banking'),
      subtitle: t('module.bankingHint'),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 12),
            child: Row(
              children: [
                NeonChip(
                  label: t('bank.cheques'),
                  selected: !_showLoans,
                  onTap: () => setState(() => _showLoans = false),
                ),
                const SizedBox(width: 10),
                NeonChip(
                  label: t('bank.loans'),
                  accent: NeonPalette.violet,
                  selected: _showLoans,
                  onTap: () => setState(() => _showLoans = true),
                ),
              ],
            ),
          ),
          Expanded(
            child: _showLoans ? const _LoansTab() : const _ChequesTab(),
          ),
        ],
      ),
    );
  }
}

class _ChequesTab extends ConsumerWidget {
  const _ChequesTab();

  static const _order = [
    ChequeStatus.issued,
    ChequeStatus.inProgress,
    ChequeStatus.draft,
    ChequeStatus.cleared,
    ChequeStatus.bounced,
    ChequeStatus.voided,
  ];

  static String statusKey(ChequeStatus status) => switch (status) {
        ChequeStatus.draft => 'bank.stDraft',
        ChequeStatus.issued => 'bank.stIssued',
        ChequeStatus.inProgress => 'bank.stInProgress',
        ChequeStatus.cleared => 'bank.stCleared',
        ChequeStatus.bounced => 'bank.stBounced',
        ChequeStatus.voided => 'bank.stVoid',
      };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final currency = ref.watch(baseCurrencyProvider);
    final now = ref.watch(clockProvider);
    final cheques = ref.watch(chequesProvider);

    return cheques.when(
      loading: () => const ModuleLoading(),
      error: (error, _) => ModuleError(message: t('common.error')),
      data: (list) {
        if (list.isEmpty) {
          return ModuleError(message: t('bank.noCheques'));
        }

        Money sum(bool Function(Cheque) test) => list.where(test).fold(
              Money(0, currency),
              (total, cheque) => total + cheque.amount,
            );

        final incoming = sum((c) =>
            !c.status.isSettled && c.direction == ChequeDirection.received,);
        final outgoing = sum((c) =>
            !c.status.isSettled && c.direction != ChequeDirection.received,);

        return ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
          children: [
            Row(
              children: [
                Expanded(
                  child: StatTile(
                    label: t('bank.inflow'),
                    value: MoneyFormatter.format(incoming,
                        locale: locale, compact: true,),
                    accent: NeonPalette.lime,
                    icon: Icons.south_west_rounded,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: StatTile(
                    label: t('bank.outflow'),
                    value: MoneyFormatter.format(outgoing,
                        locale: locale, compact: true,),
                    accent: NeonPalette.magenta,
                    icon: Icons.north_east_rounded,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            for (final status in _order)
              if (list.any((c) => c.status == status)) ...[
                GroupHeading(
                  title: t(statusKey(status)),
                  accent: _accentFor(status),
                  trailing: DateFormatter.number(
                    list.where((c) => c.status == status).length,
                    locale,
                  ),
                ),
                for (final cheque
                    in list.where((c) => c.status == status).toList()
                      ..sort((a, b) => a.dueDate.compareTo(b.dueDate))) ...[
                  ChequeCard(cheque: cheque, now: now),
                  const SizedBox(height: 10),
                ],
                const SizedBox(height: 8),
              ],
          ],
        );
      },
    );
  }

  static Color _accentFor(ChequeStatus status) => switch (status) {
        ChequeStatus.cleared => NeonPalette.lime,
        ChequeStatus.bounced => NeonPalette.magenta,
        ChequeStatus.voided => NeonPalette.textMuted,
        ChequeStatus.draft => NeonPalette.textSecondary,
        _ => NeonPalette.cyan,
      };
}

class ChequeCard extends ConsumerWidget {
  const ChequeCard({super.key, required this.cheque, required this.now});

  final Cheque cheque;
  final DateTime now;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final urgency = cheque.urgencyOn(now);
    final accent = accentForUrgency(urgency, cheque.direction);

    final directionKey = switch (cheque.direction) {
      ChequeDirection.received => 'bank.dirReceived',
      ChequeDirection.issued => 'bank.dirIssued',
      ChequeDirection.guarantee => 'bank.dirGuarantee',
    };

    return NeonCardShell(
      accent: accent,
      // Only an overdue cheque glows: it is the one row on the screen that
      // costs money if it is scrolled past.
      glow: urgency == ChequeUrgency.overdue,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                cheque.direction == ChequeDirection.received
                    ? Icons.south_west_rounded
                    : Icons.north_east_rounded,
                size: 16,
                color: accent,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  cheque.partyName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 14.5,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
              ),
              Text(
                MoneyFormatter.format(cheque.amount, locale: locale.code),
                style: TextStyle(
                  fontSize: 14.5,
                  fontWeight: FontWeight.w800,
                  color: accent,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  '${t(directionKey)} · ${t('bank.chequeNo')} ${cheque.number}'
                  '${cheque.bankName == null ? '' : ' · ${cheque.bankName}'}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              const Icon(Icons.event_rounded,
                  size: 14, color: NeonPalette.textMuted,),
              const SizedBox(width: 6),
              Text(
                DateFormatter.short(cheque.dueDate, locale),
                style: const TextStyle(
                  fontSize: 11.5,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const Spacer(),
              ChequeUrgencyBadge(
                chequeId: cheque.id,
                urgency: urgency,
                accent: accent,
                label: _urgencyLabel(t, urgency, cheque.daysUntilDue(now), locale.code),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static String _urgencyLabel(
    Translator t,
    ChequeUrgency urgency,
    int days,
    String localeCode,
  ) {
    final count = DateFormatter.number(days.abs(), localeCode);
    return switch (urgency) {
      ChequeUrgency.overdue => t('bank.overdueBy', args: {'days': count}),
      ChequeUrgency.dueSoon => days == 0
          ? t('bank.dueToday')
          : t('bank.dueIn', args: {'days': count}),
      ChequeUrgency.upcoming => t('bank.dueIn', args: {'days': count}),
      ChequeUrgency.settled => t(_ChequesTab.statusKey(ChequeStatus.cleared)),
    };
  }

  /// Amber for approaching, magenta for overdue — the two states the module
  /// exists to surface.
  static Color accentForUrgency(
    ChequeUrgency urgency,
    ChequeDirection direction,
  ) =>
      switch (urgency) {
        ChequeUrgency.overdue => NeonPalette.magenta,
        ChequeUrgency.dueSoon => NeonPalette.amber,
        ChequeUrgency.upcoming => direction == ChequeDirection.received
            ? NeonPalette.lime
            : NeonPalette.cyan,
        ChequeUrgency.settled => NeonPalette.textMuted,
      };
}

/// Public so a test can assert that an overdue cheque is marked differently
/// from an upcoming one without reaching into private render objects.
class ChequeUrgencyBadge extends StatelessWidget {
  const ChequeUrgencyBadge({
    super.key,
    required this.chequeId,
    required this.urgency,
    required this.accent,
    required this.label,
  });

  final String chequeId;
  final ChequeUrgency urgency;
  final Color accent;
  final String label;

  @override
  Widget build(BuildContext context) {
    return NeonChip(
      label: label,
      accent: accent,
      selected: urgency == ChequeUrgency.overdue ||
          urgency == ChequeUrgency.dueSoon,
      icon: urgency == ChequeUrgency.overdue
          ? Icons.priority_high_rounded
          : null,
    );
  }
}

class _LoansTab extends ConsumerWidget {
  const _LoansTab();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final loans = ref.watch(loansProvider);

    return loans.when(
      loading: () => const ModuleLoading(),
      error: (error, _) => ModuleError(message: t('common.error')),
      data: (list) => ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
        children: [
          for (final loan in list) ...[
            LoanCard(loan: loan, localeCode: locale.code),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }
}

class LoanCard extends ConsumerWidget {
  const LoanCard({super.key, required this.loan, required this.localeCode});

  final Loan loan;
  final String localeCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCard(
      accent: NeonPalette.violet,
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => LoanDetailScreen(loan: loan)),
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
                      loan.title,
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
                      loan.lender,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: NeonPalette.textMuted,
                      ),
                    ),
                  ],
                ),
              ),
              Text(
                MoneyFormatter.format(loan.outstanding, locale: localeCode),
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w800,
                  color: NeonPalette.violet,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          NeonMeter(fraction: loan.progress, accent: NeonPalette.violet),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: Text(
                  t('bank.installmentsPaid', args: {
                    'paid': DateFormatter.number(loan.paidInstallments, localeCode),
                    'total': DateFormatter.number(loan.schedule.length, localeCode),
                  },),
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ),
              Text(
                '${t('bank.repaid')} '
                '${MoneyFormatter.format(loan.paidToDate, locale: localeCode, compact: true)}',
                style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: NeonPalette.violet,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
