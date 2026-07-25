import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'ai_capture_screen.dart';
import 'ai_chat_screen.dart';
import 'insights_screen.dart';
import 'receipt_review_screen.dart';
import 'voice_capture_screen.dart';

/// The one place the AI features live, so nothing has to be hunted for.
class AiHubScreen extends ConsumerWidget {
  const AiHubScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const AiHubScreen());

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonBackdrop(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(title: Text(t('ai.title'))),
        body: ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 32),
          children: [
            // The product's governing rule, stated where the features are —
            // not buried in a settings page.
            NeonCard(
              accent: NeonPalette.violet,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                      gradient: NeonEffects.hero(
                        NeonPalette.violet,
                        NeonPalette.cyan,
                      ),
                      borderRadius: BorderRadius.circular(11),
                    ),
                    child: const Icon(
                      Icons.auto_awesome_rounded,
                      size: 17,
                      color: Colors.white,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Text(
                      t('ai.rule'),
                      style: const TextStyle(
                        fontSize: 12.5,
                        height: 1.7,
                        color: NeonPalette.textSecondary,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            _Entry(
              title: t('ai.hub.text'),
              hint: t('ai.hub.textHint'),
              icon: Icons.edit_note_rounded,
              accent: NeonPalette.violet,
              onTap: () => Navigator.of(context).push(AiCaptureScreen.route()),
            ),
            const SizedBox(height: 12),
            _Entry(
              title: t('ai.hub.voice'),
              hint: t('ai.hub.voiceHint'),
              icon: Icons.mic_rounded,
              accent: NeonPalette.magenta,
              onTap: () => Navigator.of(context).push(VoiceCaptureScreen.route()),
            ),
            const SizedBox(height: 12),
            _Entry(
              title: t('ai.hub.receipt'),
              hint: t('ai.hub.receiptHint'),
              icon: Icons.receipt_long_rounded,
              accent: NeonPalette.cyan,
              onTap: () => Navigator.of(context).push(
                ReceiptReviewScreen.route(reference: 'sample-mismatch'),
              ),
            ),
            const SizedBox(height: 12),
            _Entry(
              title: t('ai.hub.chat'),
              hint: t('ai.hub.chatHint'),
              icon: Icons.forum_rounded,
              accent: NeonPalette.lime,
              onTap: () => Navigator.of(context).push(AiChatScreen.route()),
            ),
            const SizedBox(height: 24),
            SectionHeader(
              title: t('ai.insights.title'),
              accent: NeonPalette.violet,
              actionLabel: t('dashboard.viewAll'),
              onAction: () => Navigator.of(context).push(AiInsightsScreen.route()),
            ),
            const AiInsightsList(shrinkWrap: true, limit: 3),
          ],
        ),
      ),
    );
  }
}

class _Entry extends StatelessWidget {
  const _Entry({
    required this.title,
    required this.hint,
    required this.icon,
    required this.accent,
    required this.onTap,
  });

  final String title;
  final String hint;
  final IconData icon;
  final Color accent;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return NeonCardShell(
      accent: accent,
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(12),
              border: NeonEffects.border(accent, alpha: 0.32),
            ),
            child: Icon(icon, size: 19, color: accent),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: NeonPalette.textPrimary,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  hint,
                  style: const TextStyle(
                    fontSize: 11.5,
                    height: 1.5,
                    color: NeonPalette.textMuted,
                  ),
                ),
              ],
            ),
          ),
          Icon(
            // Material chevrons do not mirror themselves, and an arrow pointing
            // the wrong way in Persian reads as "back".
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
