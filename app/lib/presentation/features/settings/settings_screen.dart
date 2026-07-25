import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../app_state.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final themeMode = ref.watch(themeModeProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 120),
      children: [
        SectionHeader(title: t('settings.language')),
        NeonCard(
          child: Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final option in AppLocale.supported)
                NeonChip(
                  // Each language is shown in its own script — someone looking
                  // for فارسی should not have to recognise the word "Persian".
                  label: option.nativeName,
                  selected: option.code == locale.code,
                  accent: NeonPalette.cyan,
                  onTap: () => ref.read(localeProvider.notifier).state = option,
                ),
            ],
          ),
        ),
        const SizedBox(height: 22),
        SectionHeader(title: t('settings.theme'), accent: NeonPalette.violet),
        NeonCard(
          accent: NeonPalette.violet,
          child: Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              for (final entry in {
                ThemeMode.dark: t('settings.themeDark'),
                ThemeMode.light: t('settings.themeLight'),
                ThemeMode.system: t('settings.themeSystem'),
              }.entries)
                NeonChip(
                  label: entry.value,
                  selected: themeMode == entry.key,
                  accent: NeonPalette.violet,
                  onTap: () => ref.read(themeModeProvider.notifier).state = entry.key,
                ),
            ],
          ),
        ),
        const SizedBox(height: 22),
        SectionHeader(title: t('settings.about'), accent: NeonPalette.lime),
        NeonCard(
          accent: NeonPalette.lime,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                t('app.name'),
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: NeonPalette.textPrimary,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                t('app.tagline'),
                style: const TextStyle(fontSize: 13, color: NeonPalette.textSecondary),
              ),
              const SizedBox(height: 12),
              const Text(
                'v0.1.0 · M0–M2',
                style: TextStyle(fontSize: 12, color: NeonPalette.textMuted),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
