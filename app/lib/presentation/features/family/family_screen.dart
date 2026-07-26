import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/family_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'family_labels.dart';
import 'family_providers.dart';
import 'member_detail_screen.dart';
import 'member_form_screen.dart';

/// The household: everyone in it, and what each of them has spent this month
/// against the ceiling they were given.
class FamilyScreen extends ConsumerWidget {
  const FamilyScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const FamilyScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final household = ref.watch(householdProvider);

    return ModulePage(
      title: t('family.title'),
      subtitle: t('family.subtitle'),
      child: household.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('family.noAccessTitle'),
          permissionBody: t('family.noAccessBody'),
        ),
        data: (household) => _HouseholdBody(household: household),
      ),
    );
  }
}

class _HouseholdBody extends ConsumerWidget {
  const _HouseholdBody({required this.household});

  final Household household;

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final saved = await Navigator.of(context).push<bool>(
      MemberFormScreen.route(),
    );
    if (saved ?? false) ref.invalidate(householdProvider);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        NeonButton(
          key: const ValueKey('family-add-member'),
          label: t('family.addMember'),
          icon: Icons.person_add_alt_1_rounded,
          expand: true,
          onPressed: () => _add(context, ref),
        ),
        const SizedBox(height: 22),
        SectionHeader(
          title: t(
            'family.periodHeading',
            args: {
              'period': familyPeriodLabel(household.report.period, locale.code),
            },
          ),
        ),
        if (household.members.isEmpty)
          _EmptyLine(text: t('family.empty'))
        else
          for (final member in household.members) ...[
            _MemberRow(
              member: member,
              spending: household.spendingFor(member.id),
              locale: locale,
              onOpen: () async {
                await Navigator.of(context).push(
                  MemberDetailScreen.route(member.id),
                );
                ref.invalidate(householdProvider);
              },
            ),
            const SizedBox(height: 12),
          ],
      ],
    );
  }
}

class _MemberRow extends ConsumerWidget {
  const _MemberRow({
    required this.member,
    required this.spending,
    required this.locale,
    required this.onOpen,
  });

  final HouseholdMember member;
  final MemberSpending? spending;
  final AppLocale locale;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final row = spending;

    // Only a member who is actually over their cap earns the alarm colour — and
    // therefore the glow. A cap that cannot be compared is not a breach.
    final overCap = row != null && row.isComparable && row.isOverCap;

    final accent = overCap
        ? (isDark ? NeonPalette.magenta : NeonPalette.lightMagenta)
        : familyRoleAccent(member.role, isDark: isDark);

    return NeonCardShell(
      key: ValueKey('family-member-${member.id}'),
      accent: accent,
      glow: overCap,
      onTap: onOpen,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  member.displayName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 14.5,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              NeonChip(
                label: familyRoleLabel(t, member.role),
                accent: accent,
                selected: true,
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (row == null)
            _EmptyLine(text: t('family.noSpendingYet'))
          else ...[
            if (row.isComparable)
              NeonMeter(fraction: row.meterFraction, accent: accent),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: Text(
                    row.isComparable
                        ? '${MoneyFormatter.format(
                            row.spent,
                            locale: locale.code,
                            compact: true,
                          )} / ${MoneyFormatter.format(
                            row.cap!,
                            locale: locale.code,
                            compact: true,
                          )}'
                        : MoneyFormatter.format(
                            row.spent,
                            locale: locale.code,
                            compact: true,
                          ),
                    style: TextStyle(
                      fontSize: 11.5,
                      color: isDark
                          ? NeonPalette.textSecondary
                          : NeonPalette.lightTextSecondary,
                    ),
                  ),
                ),
                Text(
                  _standing(t, row),
                  key: ValueKey('family-standing-${member.id}'),
                  textAlign: TextAlign.end,
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w700,
                    color: accent,
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  String _standing(Translator t, MemberSpending row) {
    if (!row.isComparable) return t('family.noCap');
    if (row.isOverCap) {
      return '${t('family.overCap')} '
          '${MoneyFormatter.format(
        row.remaining!.absolute,
        locale: locale.code,
        compact: true,
      )}';
    }
    return '${t('family.capRemaining')} '
        '${MoneyFormatter.format(
      row.remaining!,
      locale: locale.code,
      compact: true,
    )}';
  }
}

class _EmptyLine extends StatelessWidget {
  const _EmptyLine({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 10),
      child: Text(
        text,
        style: TextStyle(
          fontSize: 12,
          color: isDark
              ? NeonPalette.textMuted
              : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}
