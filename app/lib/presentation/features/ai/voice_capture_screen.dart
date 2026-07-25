import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../domain/ai/ai_models.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'ai_providers.dart';
import 'widgets/draft_editor.dart';

enum _Stage { idle, recording, transcribing, transcript, draft }

/// Record → transcript → correct → draft.
///
/// The transcript is always shown before anything is parsed: speech recognition
/// mishears numbers often enough that trusting it silently would be the fastest
/// way to put a wrong amount in someone's ledger.
class VoiceCaptureScreen extends ConsumerStatefulWidget {
  const VoiceCaptureScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const VoiceCaptureScreen(),
      );

  @override
  ConsumerState<VoiceCaptureScreen> createState() => _VoiceCaptureScreenState();
}

class _VoiceCaptureScreenState extends ConsumerState<VoiceCaptureScreen> {
  final _transcript = TextEditingController();

  _Stage _stage = _Stage.idle;
  TransactionDraft? _draft;
  bool _noMatch = false;

  @override
  void dispose() {
    _transcript.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    await ref.read(voiceRecorderProvider).start();
    if (!mounted) return;
    setState(() {
      _stage = _Stage.recording;
      _noMatch = false;
    });
  }

  Future<void> _stop() async {
    setState(() => _stage = _Stage.transcribing);
    final capture = await ref.read(voiceRecorderProvider).stopAndTranscribe();
    if (!mounted) return;
    setState(() {
      _transcript.text = capture.transcript;
      _stage = _Stage.transcript;
    });
  }

  Future<void> _parse() async {
    final text = _transcript.text.trim();
    if (text.isEmpty) return;

    final draft = await ref.read(aiRepositoryProvider).parseText(text);
    if (!mounted) return;

    setState(() {
      _draft = draft;
      _noMatch = draft == null;
      if (draft != null) _stage = _Stage.draft;
    });
  }

  void _settled(bool saved) {
    if (!mounted) return;
    if (saved) {
      Navigator.of(context).pop();
      return;
    }
    setState(() {
      _draft = null;
      _stage = _Stage.transcript;
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final draft = _draft;

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(
          title: Text(t('ai.voice.title')),
          actions: [
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 12),
              // The recorder is a stub in this build; saying so is better than
              // letting a demo imply a microphone that is not there.
              child: NeonChip(label: t('ai.simulated'), accent: NeonPalette.amber),
            ),
          ],
        ),
        body: ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 32),
          children: [
            NeonCard(
              accent: _stage == _Stage.recording
                  ? NeonPalette.magenta
                  : NeonPalette.violet,
              child: Column(
                children: [
                  _MicButton(
                    recording: _stage == _Stage.recording,
                    busy: _stage == _Stage.transcribing,
                    onTap: _stage == _Stage.recording ? _stop : _start,
                  ),
                  const SizedBox(height: 16),
                  Text(
                    switch (_stage) {
                      _Stage.recording => t('ai.voice.recording'),
                      _Stage.transcribing => t('ai.voice.transcribing'),
                      _ => t('ai.voice.idle'),
                    },
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: _stage == _Stage.recording
                          ? NeonPalette.magenta
                          : NeonPalette.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
            if (_stage == _Stage.transcript || _stage == _Stage.draft) ...[
              const SizedBox(height: 20),
              SectionHeader(
                title: t('ai.voice.transcript'),
                accent: NeonPalette.violet,
              ),
              NeonCard(
                accent: NeonPalette.violet,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    TextField(
                      controller: _transcript,
                      maxLines: 3,
                      minLines: 1,
                      decoration: InputDecoration(
                        hintText: t('ai.voice.transcriptHint'),
                      ),
                    ),
                    const SizedBox(height: 12),
                    NeonButton(
                      label: t('ai.voice.useTranscript'),
                      icon: Icons.auto_awesome_rounded,
                      accent: NeonPalette.violet,
                      expand: true,
                      onPressed: _parse,
                    ),
                  ],
                ),
              ),
            ],
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
                key: ValueKey(draft.sourceText),
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

/// The only glowing thing on the screen, and only while it is recording —
/// the glow *is* the "we are listening" signal.
class _MicButton extends StatelessWidget {
  const _MicButton({
    required this.recording,
    required this.busy,
    required this.onTap,
  });

  final bool recording;
  final bool busy;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = recording ? NeonPalette.magenta : NeonPalette.violet;

    return Semantics(
      button: true,
      label: recording ? 'stop' : 'record',
      child: GestureDetector(
        onTap: busy ? null : onTap,
        child: AnimatedContainer(
          duration: NeonEffects.medium,
          curve: NeonEffects.curve,
          width: 92,
          height: 92,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: accent.withValues(alpha: recording ? 0.22 : 0.10),
            border: NeonEffects.border(accent, alpha: recording ? 0.8 : 0.35, width: 1.6),
            boxShadow: recording && isDark ? NeonEffects.glow(accent) : null,
          ),
          child: busy
              ? Padding(
                  padding: const EdgeInsetsDirectional.all(30),
                  child: CircularProgressIndicator(strokeWidth: 2, color: accent),
                )
              : Icon(
                  recording ? Icons.stop_rounded : Icons.mic_rounded,
                  size: 36,
                  color: accent,
                ),
        ),
      ),
    );
  }
}
