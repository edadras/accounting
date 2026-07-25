import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Dark by default: the neon palette is designed for it, and a finance app is
/// most often opened at a checkout counter or in bed.
final themeModeProvider = StateProvider<ThemeMode>((ref) => ThemeMode.dark);

/// Which tab of the shell is showing.
final selectedTabProvider = StateProvider<int>((ref) => 0);

/// Connectivity as the UI sees it. Wired to a real listener in M7; until then
/// it stays true, and every screen already handles it being false.
final isOnlineProvider = StateProvider<bool>((ref) => true);

/// How many local writes are still waiting to reach the server.
final pendingChangesProvider = StateProvider<int>((ref) => 0);
