import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/data_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import 'data_format.dart';
import 'data_providers.dart';

/// Shown for as long as the grace period is open.
///
/// It glows because it is the one piece of state on the screen the user may
/// have forgotten about and would very much want to act on, and it carries the
/// way out rather than only the warning.
class ScheduledDeletionBanner extends ConsumerStatefulWidget {
  const ScheduledDeletionBanner({super.key});

  @override
  ConsumerState<ScheduledDeletionBanner> createState() =>
      _ScheduledDeletionBannerState();
}

class _ScheduledDeletionBannerState
    extends ConsumerState<ScheduledDeletionBanner> {
  bool _busy = false;
  DataOpsException? _failure;

  Future<void> _restore() async {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    setState(() {
      _busy = true;
      _failure = null;
    });

    try {
      await repository.cancelDeletion();
      if (!mounted) return;
      ref.read(scheduledDeletionProvider.notifier).state = null;
      setState(() => _busy = false);
    } on DataOpsException catch (error) {
      if (!mounted) return;
      setState(() {
        _failure = error;
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheduled = ref.watch(scheduledDeletionProvider);
    if (scheduled == null) return const SizedBox.shrink();

    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final failure = _failure;

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 16),
      child: NeonCardShell(
        accent: NeonPalette.magenta,
        glow: true,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(
                  Icons.warning_amber_rounded,
                  size: 18,
                  color: NeonPalette.magenta,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    t(
                      'data.banner.scheduled',
                      args: {'date': dateLabel(scheduled.purgeAfter, locale)},
                    ),
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                ),
              ],
            ),
            if (failure != null) ...[
              const SizedBox(height: 8),
              Text(
                t(failure.translationKey),
                style: const TextStyle(
                  fontSize: 12,
                  color: NeonPalette.magenta,
                ),
              ),
            ],
            const SizedBox(height: 12),
            NeonButton(
              label: t('data.banner.restore'),
              icon: Icons.undo_rounded,
              accent: NeonPalette.lime,
              expand: true,
              busy: _busy,
              onPressed: _restore,
            ),
          ],
        ),
      ),
    );
  }
}
