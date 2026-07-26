import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/entities.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../security/security_providers.dart';
import '../security/security_widgets.dart';
import 'auth_controller.dart';

/// Which workspace this session is in.
///
/// Not a preference: `X-Workspace-Id` is a required header, so until one is
/// chosen every other request in the app fails. Shown only when the account has
/// more than one — a single workspace is chosen for the user.
class WorkspacePickerScreen extends ConsumerWidget {
  const WorkspacePickerScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const WorkspacePickerScreen());

  /// The workspace kinds the dictionary has a word for. Anything else keeps the
  /// server's own name rather than rendering a raw key at the user.
  static const _knownTypes = {
    'personal',
    'business',
    'building',
    'travel',
    'family',
  };

  static const _icons = <String, IconData>{
    'personal': Icons.person_outline_rounded,
    'business': Icons.storefront_outlined,
    'building': Icons.apartment_rounded,
    'travel': Icons.flight_takeoff_rounded,
    'family': Icons.diversity_3_rounded,
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final state = ref.watch(authControllerProvider);

    return SecurityScaffold(
      title: t('workspace.title'),
      children: [
        SectionHeader(title: t('workspace.choose')),
        NeonCard(
          child: Text(
            t('auth.workspaceHint'),
            style: const TextStyle(
              fontSize: 13,
              height: 1.6,
              color: NeonPalette.textSecondary,
            ),
          ),
        ),
        if (state.error != null) ...[
          const SizedBox(height: 14),
          SecurityNotice.failure(message: securityErrorText(t, state.error)),
        ],
        const SizedBox(height: 14),
        for (final workspace in state.workspaces) ...[
          _WorkspaceTile(
            workspace: workspace,
            subtitle: _knownTypes.contains(workspace.type)
                ? t('workspace.${workspace.type}')
                : workspace.baseCurrency.code,
            icon: _icons[workspace.type] ?? Icons.workspaces_outline,
            enabled: !state.busy,
            onTap: () async {
              await ref
                  .read(authControllerProvider.notifier)
                  .selectWorkspace(workspace.id);
              if (context.mounted && Navigator.of(context).canPop()) {
                Navigator.of(context).pop();
              }
            },
          ),
          const SizedBox(height: 12),
        ],
      ],
    );
  }
}

class _WorkspaceTile extends StatelessWidget {
  const _WorkspaceTile({
    required this.workspace,
    required this.subtitle,
    required this.icon,
    required this.enabled,
    required this.onTap,
  });

  final Workspace workspace;
  final String subtitle;
  final IconData icon;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return NeonCard(
      onTap: enabled ? onTap : null,
      child: Row(
        children: [
          Icon(icon, size: 22, color: NeonPalette.cyan),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  workspace.name,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  subtitle,
                  style: const TextStyle(
                    fontSize: 12,
                    color: NeonPalette.textSecondary,
                  ),
                ),
              ],
            ),
          ),
          // Material chevrons do not mirror themselves, and one pointing the
          // wrong way on a Persian screen reads as "back".
          Icon(
            Directionality.of(context) == TextDirection.rtl
                ? Icons.chevron_left_rounded
                : Icons.chevron_right_rounded,
            size: 20,
            color: NeonPalette.textMuted,
          ),
        ],
      ),
    );
  }
}
