import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_button.dart';
import 'auth_controller.dart';

/// Ends the session: `POST /auth/logout`, then the token store is emptied
/// whether or not the server was reachable.
///
/// Builds to nothing when no backend is configured — there is no session to end
/// in the demo build, and an inert button would only invite a tap.
class SignOutButton extends ConsumerWidget {
  const SignOutButton({super.key, this.expand = true});

  final bool expand;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(authBackendProvider) == null) return const SizedBox.shrink();

    final t = ref.watch(translatorProvider);

    return NeonButton(
      label: t('auth.signOut'),
      icon: Icons.logout_rounded,
      variant: NeonButtonVariant.outline,
      accent: NeonPalette.magenta,
      expand: expand,
      onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
    );
  }
}
