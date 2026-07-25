import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/i18n/translator.dart';
import '../core/theme/neon_effects.dart';
import '../core/theme/neon_palette.dart';
import 'app_state.dart';
import 'features/accounts/accounts_screen.dart';
import 'features/dashboard/dashboard_screen.dart';
import 'features/reports/reports_screen.dart';
import 'features/settings/settings_screen.dart';
import 'features/transactions/quick_add_sheet.dart';
import 'features/transactions/transactions_screen.dart';
import 'widgets/neon_widgets.dart';

class AppShell extends ConsumerWidget {
  const AppShell({super.key});

  static const _tabs = <(String, IconData)>[
    ('nav.dashboard', Icons.grid_view_rounded),
    ('nav.transactions', Icons.receipt_long_rounded),
    ('nav.reports', Icons.insights_rounded),
    ('nav.accounts', Icons.account_balance_wallet_rounded),
    ('nav.settings', Icons.settings_rounded),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final index = ref.watch(selectedTabProvider);
    final isOnline = ref.watch(isOnlineProvider);
    final pending = ref.watch(pendingChangesProvider);

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(
          title: Text(t(_tabs[index].$1)),
          actions: [
            if (!isOnline)
              Padding(
                padding: const EdgeInsetsDirectional.only(end: 12),
                child: NeonChip(
                  label: pending > 0
                      ? '${t('common.offline')} · $pending ${t('common.pendingChanges')}'
                      : t('common.offline'),
                  accent: NeonPalette.amber,
                  selected: true,
                  icon: Icons.cloud_off_rounded,
                ),
              ),
          ],
        ),
        body: SafeArea(
          top: false,
          child: IndexedStack(
            index: index,
            children: const [
              DashboardScreen(),
              TransactionsScreen(),
              ReportsScreen(),
              AccountsScreen(),
              SettingsScreen(),
            ],
          ),
        ),
        floatingActionButtonLocation: FloatingActionButtonLocation.centerFloat,
        floatingActionButton: _QuickAddButton(
          onPressed: () => QuickAddSheet.show(context),
        ),
        bottomNavigationBar: _NeonNavBar(
          index: index,
          labels: [for (final tab in _tabs) t(tab.$1)],
          icons: [for (final tab in _tabs) tab.$2],
          onChanged: (value) => ref.read(selectedTabProvider.notifier).state = value,
        ),
      ),
    );
  }
}

/// The single most-used action in the product gets the brightest element on the
/// screen and a permanent, thumb-reachable position.
class _QuickAddButton extends StatelessWidget {
  const _QuickAddButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 34),
      child: GestureDetector(
        onTap: onPressed,
        child: Container(
          width: 58,
          height: 58,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: NeonEffects.hero(NeonPalette.cyan, NeonPalette.violet),
            boxShadow: isDark ? NeonEffects.glow(NeonPalette.cyan) : NeonEffects.lift,
          ),
          child: const Icon(Icons.add_rounded, size: 28, color: Colors.white),
        ),
      ),
    );
  }
}

class _NeonNavBar extends StatelessWidget {
  const _NeonNavBar({
    required this.index,
    required this.labels,
    required this.icons,
    required this.onChanged,
  });

  final int index;
  final List<String> labels;
  final List<IconData> icons;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      decoration: BoxDecoration(
        color: isDark
            ? NeonPalette.surface.withValues(alpha: 0.94)
            : Colors.white,
        border: Border(
          top: BorderSide(color: NeonPalette.cyan.withValues(alpha: isDark ? 0.16 : 0.10)),
        ),
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 62,
          child: Row(
            children: [
              for (var i = 0; i < labels.length; i++) ...[
                Expanded(child: _NavItem(
                  label: labels[i],
                  icon: icons[i],
                  selected: i == index,
                  onTap: () => onChanged(i),
                ),),
                // Gap in the middle for the floating add button.
                if (i == labels.length ~/ 2 - 1) const SizedBox(width: 68),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final active = isDark ? NeonPalette.cyan : NeonPalette.lightCyan;
    final idle = isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary;
    final color = selected ? active : idle;

    return Semantics(
      selected: selected,
      button: true,
      label: label,
      child: InkResponse(
        onTap: onTap,
        radius: 42,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AnimatedContainer(
              duration: NeonEffects.fast,
              curve: NeonEffects.curve,
              padding: const EdgeInsetsDirectional.symmetric(horizontal: 14, vertical: 5),
              decoration: BoxDecoration(
                color: selected ? active.withValues(alpha: 0.12) : Colors.transparent,
                borderRadius: BorderRadius.circular(999),
                boxShadow: selected && isDark
                    ? NeonEffects.glowTight(active, intensity: 0.45)
                    : null,
              ),
              child: Icon(icon, size: 20, color: color),
            ),
            const SizedBox(height: 3),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 10.5,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                color: color,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
