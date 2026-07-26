import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../data/modules_repository.dart' show clockProvider;
import '../../../domain/entities.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../../widgets/transaction_row.dart';

class TransactionsScreen extends ConsumerStatefulWidget {
  const TransactionsScreen({super.key});

  @override
  ConsumerState<TransactionsScreen> createState() => _TransactionsScreenState();
}

class _TransactionsScreenState extends ConsumerState<TransactionsScreen> {
  TransactionType? _filter;

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final now = ref.watch(clockProvider);
    final locale = ref.watch(localeProvider).code;
    final transactions = ref.watch(transactionsProvider);
    final categories = ref.watch(categoriesProvider).valueOrNull ?? const <Category>[];
    final accounts = ref.watch(accountsProvider).valueOrNull ?? const <Account>[];

    return Column(
      children: [
        Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 12),
          child: Row(
            children: [
              NeonChip(
                label: t('dashboard.viewAll'),
                selected: _filter == null,
                onTap: () => setState(() => _filter = null),
              ),
              const SizedBox(width: 8),
              for (final type in TransactionType.values) ...[
                NeonChip(
                  label: t('tx.${type.name}'),
                  selected: _filter == type,
                  accent: switch (type) {
                    TransactionType.income => NeonPalette.income,
                    TransactionType.expense => NeonPalette.expense,
                    TransactionType.transfer => NeonPalette.transfer,
                  },
                  onTap: () => setState(() => _filter = type),
                ),
                const SizedBox(width: 8),
              ],
            ],
          ),
        ),
        Expanded(
          child: transactions.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (error, _) => Center(child: Text(t('common.error'))),
            data: (all) {
              final rows =
                  _filter == null ? all : all.where((tx) => tx.type == _filter).toList();

              if (rows.isEmpty) {
                return _EmptyState(
                  title: t('tx.empty'),
                  hint: t('tx.emptyHint'),
                );
              }

              return ListView.builder(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 120),
                itemCount: rows.length,
                itemBuilder: (context, index) {
                  final transaction = rows[index];
                  final showHeader = index == 0 ||
                      !_sameDay(rows[index - 1].occurredAt, transaction.occurredAt);

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (showHeader)
                        Padding(
                          padding: EdgeInsetsDirectional.only(top: index == 0 ? 0 : 18, bottom: 8),
                          child: Text(
                            _dayLabel(transaction.occurredAt, t, now),
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                              color: NeonPalette.textMuted,
                            ),
                          ),
                        ),
                      NeonCard(
                        blur: false,
                        padding: const EdgeInsetsDirectional.symmetric(
                          horizontal: 10,
                          vertical: 2,
                        ),
                        accent: switch (transaction.type) {
                          TransactionType.income => NeonPalette.income,
                          TransactionType.expense => NeonPalette.expense,
                          TransactionType.transfer => NeonPalette.transfer,
                        },
                        child: TransactionRow(
                          transaction: transaction,
                          locale: locale,
                          categoryLabel: categories
                              .where((c) => c.id == transaction.categoryId)
                              .map((c) => c.name)
                              .firstOrNull,
                          accountLabel: accounts
                              .where((a) => a.id == transaction.accountId)
                              .map((a) => a.name)
                              .firstOrNull,
                        ),
                      ),
                      const SizedBox(height: 8),
                    ],
                  );
                },
              );
            },
          ),
        ),
      ],
    );
  }

  static bool _sameDay(DateTime a, DateTime b) =>
      a.year == b.year && a.month == b.month && a.day == b.day;

  /// Reads the injected clock, not the wall clock.
  ///
  /// With `DateTime.now()` here, "Today" was decided by when the frame was
  /// drawn while the seeded rows sat at offsets from a pinned clock — so the
  /// transactions goldens passed all day and failed the moment the date rolled
  /// over, on a suite nobody had touched.
  String _dayLabel(DateTime date, Translator t, DateTime now) {
    if (_sameDay(date, now)) return t('common.today');
    if (_sameDay(date, now.subtract(const Duration(days: 1)))) {
      return t('common.yesterday');
    }
    return '${date.year}/${date.month.toString().padLeft(2, '0')}/'
        '${date.day.toString().padLeft(2, '0')}';
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.title, required this.hint});

  final String title;
  final String hint;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsetsDirectional.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: NeonPalette.cyan.withValues(alpha: 0.08),
                border: Border.all(color: NeonPalette.cyan.withValues(alpha: 0.25)),
              ),
              child: const Icon(Icons.receipt_long_rounded,
                  color: NeonPalette.cyan, size: 30,),
            ),
            const SizedBox(height: 18),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w700,
                color: NeonPalette.textPrimary,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              hint,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 13, color: NeonPalette.textMuted),
            ),
          ],
        ),
      ),
    );
  }
}
