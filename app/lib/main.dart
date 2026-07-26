import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/i18n/app_locale.dart';
import 'core/i18n/translator.dart';
import 'core/theme/neon_theme.dart';
import 'presentation/app_state.dart';
import 'presentation/features/auth/auth_gate.dart';
import 'presentation/shell.dart';

void main() {
  runApp(const ProviderScope(child: FinoraApp()));
}

class FinoraApp extends ConsumerWidget {
  const FinoraApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);
    final themeMode = ref.watch(themeModeProvider);
    final t = ref.watch(translatorProvider);

    return MaterialApp(
      title: t('app.name'),
      debugShowCheckedModeBanner: false,
      // Vazirmatn covers Persian, Arabic and Latin in one family, so the app
      // does not change typeface when the user switches language.
      theme: NeonTheme.light(fontFamily: 'Vazirmatn'),
      darkTheme: NeonTheme.dark(fontFamily: 'Vazirmatn'),
      themeMode: themeMode,
      locale: locale.locale,
      supportedLocales: [for (final l in AppLocale.supported) l.locale],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) {
        // Direction is driven by the chosen language, so switching fa → en
        // flips the whole layout without a restart.
        return Directionality(
          textDirection: locale.textDirection,
          child: child ?? const SizedBox.shrink(),
        );
      },
      // The gate keys on whether a backend is configured, never on whether a
      // token exists — so the demo build still opens straight into the shell.
      home: const AuthGate(child: AppShell()),
    );
  }
}
