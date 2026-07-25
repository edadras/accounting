import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app_locale.dart';
import 'translations.dart';

/// Resolves translation keys.
///
/// Lookup order: server overrides → bundled fallback → English → the key
/// itself. Returning the key (rather than an empty string) makes a missing
/// translation loud in QA instead of silently blank.
final class Translator {
  Translator({
    required this.locale,
    Map<String, String> overrides = const {},
  }) : _overrides = overrides;

  final AppLocale locale;
  final Map<String, String> _overrides;

  String call(String key, {Map<String, String>? args}) {
    final value = _overrides[key] ??
        BundledTranslations.byLocale[locale.code]?[key] ??
        BundledTranslations.byLocale['en']?[key] ??
        key;
    if (args == null || args.isEmpty) return value;

    var result = value;
    args.forEach((name, replacement) {
      result = result.replaceAll(':$name', replacement);
    });
    return result;
  }

  Translator withOverrides(Map<String, String> overrides) =>
      Translator(locale: locale, overrides: {..._overrides, ...overrides});
}

/// Current locale. Changing this rebuilds the whole app, flipping direction
/// and digit shape together.
final localeProvider = StateProvider<AppLocale>((ref) => AppLocale.fa);

/// Overrides fetched from `GET /api/v1/translations/{locale}`. Empty until the
/// first successful sync; the app is fully usable before then.
final translationOverridesProvider =
    StateProvider<Map<String, String>>((ref) => const {});

final translatorProvider = Provider<Translator>((ref) {
  return Translator(
    locale: ref.watch(localeProvider),
    overrides: ref.watch(translationOverridesProvider),
  );
});

extension TranslateX on WidgetRef {
  /// `ref.t('nav.dashboard')`
  String t(String key, {Map<String, String>? args}) =>
      read(translatorProvider)(key, args: args);
}

/// For widgets that only have a BuildContext (no ref) — e.g. builders passed
/// to Flutter framework APIs.
extension TranslateContextX on BuildContext {
  String tr(WidgetRef ref, String key) => ref.watch(translatorProvider)(key);
}
