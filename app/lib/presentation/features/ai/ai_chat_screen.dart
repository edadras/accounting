import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ulid/ulid.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/ledger_repository.dart';
import '../../../domain/ai/ai_models.dart';
import '../../../domain/entities.dart' as domain;
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../../widgets/transaction_row.dart';
import 'ai_format.dart';
import 'ai_providers.dart';

/// Questions about the user's own money, answered from the user's own ledger.
///
/// Every assistant answer carries the rows it was computed from and a way to
/// open them. An unsourced number in a finance app is a rumour.
class AiChatScreen extends ConsumerStatefulWidget {
  const AiChatScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const AiChatScreen());

  @override
  ConsumerState<AiChatScreen> createState() => _AiChatScreenState();
}

class _AiChatScreenState extends ConsumerState<AiChatScreen> {
  final _input = TextEditingController();
  bool _thinking = false;

  @override
  void dispose() {
    _input.dispose();
    super.dispose();
  }

  Future<void> _send(String question) async {
    final text = question.trim();
    if (text.isEmpty || _thinking) return;

    _input.clear();
    ref.read(chatLogProvider.notifier).state = [
      ...ref.read(chatLogProvider),
      ChatMessage(id: Ulid().toString(), role: ChatRole.user, text: text),
    ];
    setState(() => _thinking = true);

    final answer = await ref.read(aiRepositoryProvider).ask(text);
    if (!mounted) return;

    ref.read(chatLogProvider.notifier).state = [
      ...ref.read(chatLogProvider),
      answer,
    ];
    setState(() => _thinking = false);
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final log = ref.watch(chatLogProvider);

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('ai.chat.title'))),
        body: Column(
          children: [
            Expanded(
              child: log.isEmpty
                  ? _Suggestions(onPick: _send)
                  : ListView.builder(
                      padding:
                          const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 16),
                      itemCount: log.length,
                      itemBuilder: (context, index) => _Bubble(
                        message: log[index],
                        locale: locale,
                      ),
                    ),
            ),
            if (_thinking)
              Padding(
                padding: const EdgeInsetsDirectional.only(bottom: 8),
                child: Text(
                  t('ai.chat.thinking'),
                  style: const TextStyle(
                    fontSize: 12,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 12),
                child: Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _input,
                        decoration: InputDecoration(hintText: t('ai.chat.hint')),
                        textInputAction: TextInputAction.send,
                        onSubmitted: _send,
                      ),
                    ),
                    const SizedBox(width: 10),
                    // The send action is the primary act on this screen, so it
                    // is the one element allowed to glow.
                    Semantics(
                      button: true,
                      label: t('ai.chat.send'),
                      child: GestureDetector(
                        onTap: () => _send(_input.text),
                        child: Container(
                          width: 48,
                          height: 48,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            gradient: NeonEffects.hero(
                              NeonPalette.violet,
                              NeonPalette.cyan,
                            ),
                            boxShadow: Theme.of(context).brightness ==
                                    Brightness.dark
                                ? NeonEffects.glowTight(NeonPalette.violet)
                                : NeonEffects.lift,
                          ),
                          child: const Icon(
                            Icons.arrow_upward_rounded,
                            color: Colors.white,
                            size: 20,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Suggestions extends ConsumerWidget {
  const _Suggestions({required this.onPick});

  final ValueChanged<String> onPick;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 24, 16, 16),
      children: [
        Text(
          t('ai.chat.empty'),
          style: const TextStyle(fontSize: 13, color: NeonPalette.textSecondary),
        ),
        const SizedBox(height: 14),
        for (final key in ['ai.chat.ask1', 'ai.chat.ask2', 'ai.chat.ask3'])
          Padding(
            padding: const EdgeInsetsDirectional.only(bottom: 10),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: NeonChip(
                label: t(key),
                accent: NeonPalette.violet,
                icon: Icons.help_outline_rounded,
                onTap: () => onPick(t(key)),
              ),
            ),
          ),
      ],
    );
  }
}

class _Bubble extends ConsumerWidget {
  const _Bubble({required this.message, required this.locale});

  final ChatMessage message;
  final String locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final fromUser = message.role == ChatRole.user;

    final body = fromUser
        ? (message.text ?? '')
        : t(
            message.bodyKey ?? 'common.error',
            args: {
              ...message.args,
              if (message.amount != null)
                'amount': MoneyFormatter.format(message.amount!, locale: locale),
            },
          );

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 12),
      child: Align(
        alignment: fromUser
            ? AlignmentDirectional.centerEnd
            : AlignmentDirectional.centerStart,
        child: FractionallySizedBox(
          widthFactor: 0.92,
          alignment: fromUser
              ? AlignmentDirectional.centerEnd
              : AlignmentDirectional.centerStart,
          child: NeonCard(
            accent: fromUser ? NeonPalette.cyan : NeonPalette.violet,
            padding: const EdgeInsetsDirectional.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  body,
                  style: const TextStyle(
                    fontSize: 14,
                    height: 1.6,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                if (message.source != null) ...[
                  const SizedBox(height: 10),
                  _SourceLine(source: message.source!, locale: locale),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// «بر اساس ۴۷ تراکنش بین ۱ تا ۳۱ تیر» — and it opens them.
class _SourceLine extends ConsumerWidget {
  const _SourceLine({required this.source, required this.locale});

  final AnswerSource source;
  final String locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return InkWell(
      onTap: () => _showSource(context, ref),
      child: Padding(
        padding: const EdgeInsetsDirectional.symmetric(vertical: 4),
        child: Row(
          children: [
            const Icon(Icons.link_rounded, size: 13, color: NeonPalette.violet),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                t('ai.chat.source', args: {
                  'count': AiFormat.digits(
                    source.transactionCount.toString(),
                    locale,
                  ),
                  'from': AiFormat.day(source.from, locale),
                  'to': AiFormat.day(source.to, locale),
                },),
                style: const TextStyle(
                  fontSize: 11,
                  height: 1.5,
                  color: NeonPalette.violet,
                  decoration: TextDecoration.underline,
                  decorationColor: NeonPalette.violet,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _showSource(BuildContext context, WidgetRef ref) {
    final t = ref.read(translatorProvider);
    final all = ref.read(transactionsProvider).valueOrNull ??
        const <domain.Transaction>[];
    final ids = source.transactionIds.toSet();
    final rows = all.where((tx) => ids.contains(tx.id)).toList();

    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => Container(
        decoration: const BoxDecoration(
          color: NeonPalette.surface,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          border: Border(top: BorderSide(color: NeonPalette.hairline)),
        ),
        padding: const EdgeInsetsDirectional.fromSTEB(20, 18, 20, 28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              t('ai.chat.viewSource'),
              style: const TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w800,
                color: NeonPalette.textPrimary,
              ),
            ),
            const SizedBox(height: 12),
            Flexible(
              child: ListView(
                shrinkWrap: true,
                children: [
                  for (final transaction in rows)
                    TransactionRow(transaction: transaction, locale: locale),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
