import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/i18n/translator.dart';
import '../../../../core/theme/neon_palette.dart';
import '../../../../domain/ai/ai_models.dart';
import '../../../widgets/neon_widgets.dart';
import '../ai_format.dart';

/// How sure the AI is about one field.
///
/// A confident value is a quiet grey percentage; an uncertain one becomes an
/// amber chip that glows, because *that* is the piece of information the user
/// has to act on. This is the whole "glow is information" rule in one widget.
class ConfidenceBadge extends ConsumerWidget {
  const ConfidenceBadge({super.key, required this.confidence});

  ConfidenceBadge.of(Confident<Object?> field, {Key? key})
      : this(key: key, confidence: field.confidence);

  final double confidence;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider).code;
    final percent = AiFormat.percent(confidence, locale);

    if (confidence >= kConfidenceFloor) {
      return Text(
        t('ai.confidence', args: {'percent': percent}),
        style: const TextStyle(fontSize: 11, color: NeonPalette.textMuted),
      );
    }

    return NeonChip(
      label: '${t('ai.draft.uncertain')} · $percent',
      accent: NeonPalette.amber,
      selected: true,
      icon: Icons.error_outline_rounded,
    );
  }
}
