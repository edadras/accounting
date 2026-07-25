import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/ledger_repository.dart';
import '../core/i18n/translator.dart';
import '../data/finora_backend.dart';
import '../sync/sync_controller.dart';

/// Keeps the sync loop alive for as long as the app is on screen, and feeds
/// `isOnlineProvider` / `pendingChangesProvider` from it.
///
/// It renders its child unchanged, so wrapping the app costs nothing visually —
/// the demo build simply does not wrap it.
class SyncScope extends ConsumerStatefulWidget {
  const SyncScope({required this.child, super.key});

  final Widget child;

  @override
  ConsumerState<SyncScope> createState() => _SyncScopeState();
}

class _SyncScopeState extends ConsumerState<SyncScope> {
  SyncController? _controller;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final controller = ref.read(syncBinderProvider)..start();
      _controller = controller;
      unawaited(controller.syncNow());

      // Server wording, applied over the bundled strings. Deliberately not
      // awaited: the app is fully usable on its built-in dictionary, so this
      // must never delay the first frame.
      unawaited(_loadTranslations());
    });
  }

  Future<void> _loadTranslations() async {
    final backend = ref.read(finoraBackendProvider);
    if (backend == null) return;

    final overrides = await backend.loadTranslations(
      ref.read(localeProvider).code,
    );

    if (!mounted || overrides.isEmpty) return;
    ref.read(translationOverridesProvider.notifier).state = overrides;
  }

  @override
  void dispose() {
    _controller?.stop();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Refetch every screen whenever the local store moves, whether the change
    // came from the user or from a pull.
    ref.listen(syncStateProvider, (_, __) {
      ref.read(ledgerRevisionProvider.notifier).state++;
    });

    return widget.child;
  }
}
