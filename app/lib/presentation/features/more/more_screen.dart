import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_widgets.dart';
import '../alerts/alerts_screen.dart';
import '../assets/assets_screen.dart';
import '../banking/banking_screen.dart';
import '../budget/budget_screen.dart';
import '../buildings/buildings_screen.dart';
import '../business/business_screen.dart';
import '../documents/documents_screen.dart';
import '../family/family_screen.dart';
import '../investment/investment_screen.dart';
import '../payroll/employees_screen.dart';
import '../payroll/payroll_runs_screen.dart';
import '../payroll/tax_rules_screen.dart';
import '../recurring/recurring_screen.dart';
import '../search/search_screen.dart';
import '../travel/travel_screen.dart';
import 'module_scaffold.dart';

/// One module the hub can open.
final class ModuleEntry {
  const ModuleEntry({
    required this.id,
    required this.titleKey,
    required this.hintKey,
    required this.icon,
    required this.accent,
    required this.open,
  });

  final String id;
  final String titleKey;
  final String hintKey;
  final IconData icon;
  final Color accent;
  final Widget Function() open;
}

/// The bottom bar holds the five screens a person opens daily; these are
/// deliberate visits, so they live one tap deeper instead of squeezing the
/// tab bar to a dozen targets.
///
/// Accents are semantic, not decorative: amber where everything has a due date,
/// violet for investment, magenta where the number that matters is what is
/// overdue.
const moduleEntries = <ModuleEntry>[
  ModuleEntry(
    id: 'banking',
    titleKey: 'module.banking',
    hintKey: 'module.bankingHint',
    icon: Icons.account_balance_rounded,
    accent: NeonPalette.amber,
    open: BankingScreen.new,
  ),
  ModuleEntry(
    id: 'investment',
    titleKey: 'module.investment',
    hintKey: 'module.investmentHint',
    icon: Icons.trending_up_rounded,
    accent: NeonPalette.violet,
    open: InvestmentScreen.new,
  ),
  ModuleEntry(
    id: 'assets',
    titleKey: 'module.assets',
    hintKey: 'module.assetsHint',
    icon: Icons.inventory_2_rounded,
    accent: NeonPalette.cyan,
    open: AssetsScreen.new,
  ),
  ModuleEntry(
    id: 'travel',
    titleKey: 'module.travel',
    hintKey: 'module.travelHint',
    icon: Icons.flight_takeoff_rounded,
    accent: NeonPalette.lime,
    open: TravelScreen.new,
  ),
  ModuleEntry(
    id: 'buildings',
    titleKey: 'module.buildings',
    hintKey: 'module.buildingsHint',
    icon: Icons.apartment_rounded,
    accent: NeonPalette.cyan,
    open: BuildingsScreen.new,
  ),
  ModuleEntry(
    id: 'business',
    titleKey: 'module.business',
    hintKey: 'module.businessHint',
    icon: Icons.storefront_rounded,
    accent: NeonPalette.magenta,
    open: BusinessScreen.new,
  ),
  ModuleEntry(
    id: 'documents',
    titleKey: 'documents.title',
    hintKey: 'documents.hint',
    icon: Icons.folder_copy_rounded,
    accent: NeonPalette.violet,
    open: DocumentsScreen.new,
  ),
  ModuleEntry(
    id: 'search',
    titleKey: 'search.title',
    hintKey: 'search.subtitle',
    icon: Icons.search_rounded,
    accent: NeonPalette.cyan,
    open: SearchScreen.new,
  ),
  ModuleEntry(
    id: 'alerts',
    titleKey: 'alerts.title',
    hintKey: 'alerts.subtitle',
    icon: Icons.notifications_active_rounded,
    accent: NeonPalette.magenta,
    open: AlertsScreen.new,
  ),
  ModuleEntry(
    id: 'budget',
    titleKey: 'budget.manageTitle',
    hintKey: 'budget.manageSubtitle',
    icon: Icons.pie_chart_rounded,
    accent: NeonPalette.violet,
    open: BudgetScreen.new,
  ),
  ModuleEntry(
    id: 'recurring',
    titleKey: 'recurring.title',
    hintKey: 'recurring.subtitle',
    icon: Icons.event_repeat_rounded,
    accent: NeonPalette.amber,
    open: RecurringScreen.new,
  ),
  ModuleEntry(
    id: 'family',
    titleKey: 'family.title',
    hintKey: 'family.subtitle',
    icon: Icons.family_restroom_rounded,
    accent: NeonPalette.lime,
    open: FamilyScreen.new,
  ),
  ModuleEntry(
    id: 'payroll-runs',
    titleKey: 'payroll.runs',
    hintKey: 'payroll.runsSubtitle',
    icon: Icons.payments_rounded,
    accent: NeonPalette.amber,
    open: PayrollRunsScreen.new,
  ),
  ModuleEntry(
    id: 'employees',
    titleKey: 'payroll.employees',
    hintKey: 'payroll.employeesSubtitle',
    icon: Icons.badge_rounded,
    accent: NeonPalette.cyan,
    open: EmployeesScreen.new,
  ),
  ModuleEntry(
    id: 'tax-rules',
    titleKey: 'payroll.taxRules',
    hintKey: 'payroll.taxRulesSubtitle',
    icon: Icons.rule_folder_rounded,
    accent: NeonPalette.violet,
    open: TaxRulesScreen.new,
  ),
];

class MoreScreen extends ConsumerWidget {
  const MoreScreen({super.key});

  static Future<void> open(BuildContext context) => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => const MoreScreen()),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return ModulePage(
      title: t('more.title'),
      subtitle: t('more.hint'),
      child: ListView(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 32),
        children: [
          for (final entry in moduleEntries) ...[
            ModuleTile(entry: entry),
            const SizedBox(height: 12),
          ],
        ],
      ),
    );
  }
}

class ModuleTile extends ConsumerWidget {
  const ModuleTile({super.key, required this.entry});

  final ModuleEntry entry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCardShell(
      key: ValueKey('module-${entry.id}'),
      accent: entry.accent,
      padding: const EdgeInsetsDirectional.all(18),
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => entry.open()),
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: entry.accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(13),
              border: NeonEffects.border(entry.accent, alpha: 0.3),
            ),
            child: Icon(entry.icon, size: 21, color: entry.accent),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  t(entry.titleKey),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  t(entry.hintKey),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 11.5,
                    height: 1.4,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Icon(
            Directionality.of(context) == TextDirection.rtl
                ? Icons.chevron_left_rounded
                : Icons.chevron_right_rounded,
            size: 20,
            color: entry.accent,
          ),
        ],
      ),
    );
  }
}
