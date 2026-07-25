import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/local/local_store.dart';
import '../presentation/app_state.dart';
import 'conflict.dart';
import 'sync_engine.dart';

enum SyncPhase { idle, syncing, offline, failed }

final class SyncState {
  const SyncState({
    this.phase = SyncPhase.idle,
    this.pending = 0,
    this.conflicts = 0,
    this.errorCode,
    this.lastServerTime,
  });

  final SyncPhase phase;
  final int pending;
  final int conflicts;

  /// Stable `ApiException.code`, for the UI to translate.
  final String? errorCode;
  final DateTime? lastServerTime;

  bool get isOnline => phase != SyncPhase.offline;
  bool get hasConflicts => conflicts > 0;

  SyncState copyWith({
    SyncPhase? phase,
    int? pending,
    int? conflicts,
    String? errorCode,
    bool clearError = false,
    DateTime? lastServerTime,
  }) =>
      SyncState(
        phase: phase ?? this.phase,
        pending: pending ?? this.pending,
        conflicts: conflicts ?? this.conflicts,
        errorCode: clearError ? null : (errorCode ?? this.errorCode),
        lastServerTime: lastServerTime ?? this.lastServerTime,
      );
}

/// Owns the sync loop and the connectivity story the UI tells.
///
/// There is no connectivity plugin behind this: whether the server is reachable
/// is decided by whether the last request reached it, which is the only signal
/// that actually matters — a phone can hold five bars and still not reach the
/// API.
final class SyncController extends ChangeNotifier {
  SyncController({
    required this.engine,
    required this.store,
    this.interval = const Duration(seconds: 30),
  }) {
    _state = _withStoreCounts(const SyncState());
    store.addListener(_onStoreChanged);
  }

  final SyncEngine engine;
  final LocalStore store;
  final Duration interval;

  Timer? _timer;
  bool _running = false;
  late SyncState _state;

  SyncState get state => _state;
  List<SyncConflict> get conflicts => store.conflicts;

  void start() {
    _timer?.cancel();
    _timer = Timer.periodic(interval, (_) => unawaited(syncNow()));
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> syncNow() async {
    if (_running) return;
    _running = true;
    _emit(_state.copyWith(phase: SyncPhase.syncing, clearError: true));

    try {
      final outcome = await engine.syncNow();

      _emit(_withStoreCounts(SyncState(
        phase: switch (outcome) {
          _ when outcome.succeeded => SyncPhase.idle,
          _ when !outcome.reachedServer => SyncPhase.offline,
          _ => SyncPhase.failed,
        },
        errorCode: outcome.error?.code,
        lastServerTime: store.lastServerTime,
      ),),);
    } finally {
      _running = false;
    }
  }

  void _onStoreChanged() => _emit(_withStoreCounts(_state));

  SyncState _withStoreCounts(SyncState base) => base.copyWith(
        pending: store.pendingCount,
        conflicts: store.conflicts.length,
        lastServerTime: store.lastServerTime,
      );

  void _emit(SyncState next) {
    _state = next;
    notifyListeners();
  }

  @override
  void dispose() {
    stop();
    store.removeListener(_onStoreChanged);
    super.dispose();
  }
}

/// Overridden by [FinoraBackend]; unset in the demo build, where there is no
/// server to talk to.
final syncControllerProvider = Provider<SyncController>(
  (ref) => throw UnimplementedError(
    'syncControllerProvider must be overridden with FinoraBackend.overrides',
  ),
);

/// Pushes sync state into the providers the shell already watches, so the
/// offline banner and the pending-change count show the real thing.
final syncBinderProvider = Provider<SyncController>((ref) {
  final controller = ref.watch(syncControllerProvider);

  void apply() {
    ref.read(isOnlineProvider.notifier).state = controller.state.isOnline;
    ref.read(pendingChangesProvider.notifier).state = controller.state.pending;
    ref.read(syncStateProvider.notifier).state = controller.state;
  }

  controller.addListener(apply);
  ref.onDispose(() => controller.removeListener(apply));

  return controller;
});

final syncStateProvider = StateProvider<SyncState>((ref) => const SyncState());
