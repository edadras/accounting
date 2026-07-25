import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/ai/ai_models.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'ai_providers.dart';
import 'widgets/draft_editor.dart';

/// «دیروز ۳۵۰ لیر برای شام پرداخت کردم» in, a draft out.
class AiCaptureScreen extends ConsumerStatefulWidget {
  const AiCaptureScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const AiCaptureScreen(),
      );

  @override
  ConsumerState<AiCaptureScreen> createState() => _AiCaptureScreenState();
}

class _AiCaptureScreenState extends ConsumerState<AiCaptureScreen> {
  final _controller = TextEditingController();

  TransactionDraft? _draft;
  bool _parsing = false;
  bool _noMatch = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _parse() async {
    final text = _controller.text.trim();
    if (text.isEmpty) return;

    setState(() {
      _parsing = true;
      _noMatch = false;
    });

    final draft = await ref.read(aiRepositoryProvider).parseText(text);
    if (!mounted) return;

    setState(() {
      _parsing = false;
      _draft = draft;
      _noMatch = draft == null;
    });
  }

  void _settled(bool saved) {
    if (!mounted) return;
    if (saved) {
      Navigator.of(context).pop();
      return;
    }
    // Discarding returns the user to their own sentence, not to a blank page —
    // the words are usually right even when the parse was not.
    setState(() => _draft = null);
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final draft = _draft;

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('ai.capture.title'))),
        body: ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 32),
          children: [
            NeonCard(
              accent: NeonPalette.violet,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  TextField(
                    controller: _controller,
                    maxLines: 3,
                    minLines: 2,
                    textInputAction: TextInputAction.done,
                    decoration: InputDecoration(hintText: t('ai.capture.hint')),
                    onSubmitted: (_) => _parse(),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    t('ai.rule'),
                    style: const TextStyle(
                      fontSize: 11.5,
                      height: 1.6,
                      color: NeonPalette.textMuted,
                    ),
                  ),
                  const SizedBox(height: 14),
                  NeonButton(
                    label: t('ai.capture.action'),
                    icon: Icons.auto_awesome_rounded,
                    accent: NeonPalette.violet,
                    expand: true,
                    busy: _parsing,
                    onPressed: _parse,
                  ),
                ],
              ),
            ),
            if (_noMatch) ...[
              const SizedBox(height: 14),
              Text(
                t('ai.capture.noMatch'),
                style: const TextStyle(fontSize: 12.5, color: NeonPalette.amber),
              ),
            ],
            if (draft != null) ...[
              const SizedBox(height: 20),
              DraftEditor(
                key: ValueKey(draft.sourceText + draft.amount.value.toString()),
                draft: draft,
                onSettled: _settled,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
