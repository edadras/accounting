import 'package:flutter/material.dart';

enum CalendarSystem { jalali, gregorian, hijri }

/// A language the app ships with.
final class AppLocale {
  const AppLocale({
    required this.code,
    required this.nativeName,
    required this.textDirection,
    required this.defaultCalendar,
  });

  final String code;
  final String nativeName;
  final TextDirection textDirection;
  final CalendarSystem defaultCalendar;

  bool get isRtl => textDirection == TextDirection.rtl;

  Locale get locale => Locale(code);

  static const fa = AppLocale(
    code: 'fa',
    nativeName: 'فارسی',
    textDirection: TextDirection.rtl,
    defaultCalendar: CalendarSystem.jalali,
  );
  static const en = AppLocale(
    code: 'en',
    nativeName: 'English',
    textDirection: TextDirection.ltr,
    defaultCalendar: CalendarSystem.gregorian,
  );
  static const tr = AppLocale(
    code: 'tr',
    nativeName: 'Türkçe',
    textDirection: TextDirection.ltr,
    defaultCalendar: CalendarSystem.gregorian,
  );
  static const ar = AppLocale(
    code: 'ar',
    nativeName: 'العربية',
    textDirection: TextDirection.rtl,
    defaultCalendar: CalendarSystem.gregorian,
  );

  static const supported = <AppLocale>[fa, en, tr, ar];

  static AppLocale byCode(String code) => supported.firstWhere(
        (locale) => locale.code == code,
        orElse: () => en,
      );
}
