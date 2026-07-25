import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/data_repository.dart';
import '../../../data/modules_repository.dart' show clockProvider;
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'data_export_screen.dart';
import 'data_format.dart';
import 'data_providers.dart';
import 'deletion_banner.dart';

/// The right to be deleted (docs/07-security.md §8), as a screen.
///
/// Written for two people at once: the one who means it, who is owed a plain
/// list of what goes and the exact date it becomes unrecoverable, and the one
/// who changed their mind, who is owed a way back that is never harder to find
/// than the way out. When a deletion is pending, the way back is the only
/// primary action on the screen.
class AccountDeletionScreen extends ConsumerStatefulWidget {
  const AccountDeletionScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const AccountDeletionScreen(),
      );

  @override
  ConsumerState<AccountDeletionScreen> createState() =>
      _AccountDeletionScreenState();
}

class _AccountDeletionScreenState extends ConsumerState<AccountDeletionScreen> {
  final _password = TextEditingController();

  bool _busy = false;
  DataOpsException? _failure;
  String? _passwordError;
  bool _restored = false;

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  Future<void> _schedule() async {
    final repository = ref.read(dataRepositoryProvider);
    if (repository == null) return;

    final password = _password.text.trim();
    if (password.isEmpty) {
      setState(() {
        _passwordError = 'data.delete.passwordRequired';
        _failure = null;
      });
      return;
    }

    setState(() {
      _busy = true;
      _failure = null;
      _passwordError = null;
      _restored = false;
    });

    try {
      final scheduled = await repository.scheduleDeletion(password: password);
      if (!mounted) return;
      ref.read(scheduledDeletionProvider.notifier).state = scheduled;
      _password.clear();
      setState(() => _busy = false);
    } on DataOpsException catch (error) {
      if (!mounted) return;

      // "Already scheduled" is not really a failure of this screen: the server
      // is telling us the state we were trying to reach, deadline included.
      final deadline = error.purgeAfter;
      if (error.code == DataOpsException.codeDeletionAlreadyScheduled &&
          deadline != null) {
        ref.read(scheduledDeletionProvider.notifier).state = ScheduledDeletion(
          purgeAfter: deadline,
          graceDays: DataRepository.defaultGraceDays,
        );
      }

      setState(() {
        _failure = error;
        _busy = false;
      });
    }
  }

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
      setState(() {
        _busy = false;
        _restored = true;
      });
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
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final repository = ref.watch(dataRepositoryProvider);
    final scheduled = ref.watch(scheduledDeletionProvider);
    final failure = _failure;

    final graceDays = scheduled?.graceDays ?? DataRepository.defaultGraceDays;
    final deadline = scheduled?.purgeAfter ??
        ref.watch(clockProvider).add(Duration(days: graceDays));

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('data.delete.title'))),
        body: SafeArea(
          top: false,
          child: ListView(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
            children: [
              const ScheduledDeletionBanner(),
              if (repository == null)
                NeonCard(
                  accent: NeonPalette.amber,
                  child: Text(
                    t('data.unavailable'),
                    style: const TextStyle(
                      fontSize: 13,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
                )
              else ...[
                if (scheduled != null)
                  _RestorePanel(
                    busy: _busy,
                    deadline: dateLabel(scheduled.purgeAfter, locale),
                    onRestore: _restore,
                  )
                else
                  _DeleteForm(
                    controller: _password,
                    busy: _busy,
                    passwordErrorKey: _passwordError,
                    graceDays: graceDays,
                    deadline: dateLabel(deadline, locale),
                    onConfirm: _schedule,
                    onExport: () => Navigator.of(context).push(
                      DataExportScreen.route(),
                    ),
                  ),
                if (_restored) ...[
                  const SizedBox(height: 14),
                  NeonCardShell(
                    accent: NeonPalette.lime,
                    child: Row(
                      children: [
                        const Icon(
                          Icons.check_circle_outline_rounded,
                          size: 18,
                          color: NeonPalette.lime,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            t('data.delete.restored'),
                            style: const TextStyle(
                              fontSize: 13,
                              color: NeonPalette.textPrimary,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
                if (failure != null) ...[
                  const SizedBox(height: 14),
                  NeonCardShell(
                    accent: NeonPalette.magenta,
                    child: Text(
                      t(failure.translationKey),
                      style: const TextStyle(
                        fontSize: 13,
                        color: NeonPalette.magenta,
                      ),
                    ),
                  ),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// The pending state: what is going to happen, when, and one button to stop it.
class _RestorePanel extends ConsumerWidget {
  const _RestorePanel({
    required this.busy,
    required this.deadline,
    required this.onRestore,
  });

  final bool busy;
  final String deadline;
  final VoidCallback onRestore;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCard(
      accent: NeonPalette.lime,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            t('data.delete.scheduled'),
            style: const TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
              color: NeonPalette.textPrimary,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            t('data.delete.irreversible', args: {'date': deadline}),
            style: const TextStyle(
              fontSize: 13,
              color: NeonPalette.textSecondary,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            t('data.delete.restoreHint', args: {'date': deadline}),
            style: const TextStyle(
              fontSize: 12.5,
              color: NeonPalette.textMuted,
            ),
          ),
          const SizedBox(height: 16),
          NeonButton(
            label: t('data.delete.restore'),
            icon: Icons.undo_rounded,
            accent: NeonPalette.lime,
            expand: true,
            busy: busy,
            onPressed: onRestore,
          ),
        ],
      ),
    );
  }
}

class _DeleteForm extends ConsumerWidget {
  const _DeleteForm({
    required this.controller,
    required this.busy,
    required this.passwordErrorKey,
    required this.graceDays,
    required this.deadline,
    required this.onConfirm,
    required this.onExport,
  });

  final TextEditingController controller;
  final bool busy;
  final String? passwordErrorKey;
  final int graceDays;
  final String deadline;
  final VoidCallback onConfirm;
  final VoidCallback onExport;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final localeCode = ref.watch(localeProvider).code;
    final errorKey = passwordErrorKey;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          title: t('data.delete.what'),
          accent: NeonPalette.magenta,
        ),
        NeonCard(
          accent: NeonPalette.magenta,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('data.delete.whatBody'),
                style: const TextStyle(
                  fontSize: 13,
                  height: 1.6,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const SizedBox(height: 14),
              Text(
                t(
                  'data.delete.grace',
                  args: {'days': DateFormatter.number(graceDays, localeCode)},
                ),
                style: const TextStyle(
                  fontSize: 13,
                  height: 1.6,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                t('data.delete.irreversible', args: {'date': deadline}),
                style: const TextStyle(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  height: 1.6,
                  color: NeonPalette.amber,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        NeonButton(
          label: t('data.delete.exportFirst'),
          icon: Icons.archive_outlined,
          accent: NeonPalette.cyan,
          variant: NeonButtonVariant.outline,
          expand: true,
          onPressed: onExport,
        ),
        const SizedBox(height: 24),
        Text(
          t('data.delete.passwordHint'),
          style: const TextStyle(fontSize: 12.5, color: NeonPalette.textMuted),
        ),
        const SizedBox(height: 10),
        TextField(
          controller: controller,
          obscureText: true,
          decoration: InputDecoration(
            labelText: t('auth.password'),
            errorText: errorKey == null ? null : t(errorKey),
          ),
        ),
        const SizedBox(height: 18),
        NeonButton(
          label: t('data.delete.confirm'),
          icon: Icons.delete_forever_rounded,
          accent: NeonPalette.magenta,
          expand: true,
          busy: busy,
          onPressed: onConfirm,
        ),
      ],
    );
  }
}
