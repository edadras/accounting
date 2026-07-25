import 'package:shamsi_date/shamsi_date.dart';

import '../i18n/app_locale.dart';

/// Dates for the module screens.
///
/// A Persian user reads ۱۴۰۵/۰۴/۰۳, not 2026-06-24, so the calendar follows the
/// locale rather than the device. Only the two calendars the app can actually
/// convert are handled here; Hijri falls back to Gregorian instead of printing
/// a wrong date confidently.
abstract final class DateFormatter {
  static const _fsi = '\u{2068}';
  static const _pdi = '\u{2069}';

  static String short(DateTime date, AppLocale locale) {
    final (year, month, day) = locale.defaultCalendar == CalendarSystem.jalali
        ? _toJalali(date)
        : (date.year, date.month, date.day);

    final text = '$year/${_pad(month)}/${_pad(day)}';
    return '$_fsi${_localizeDigits(text, locale.code)}$_pdi';
  }

  /// "in 12 days" / "9 days overdue" is the only thing a due date is really
  /// asked, so the count is computed once here in whole days.
  static int daysUntil(DateTime due, DateTime now) {
    final from = DateTime(now.year, now.month, now.day);
    final to = DateTime(due.year, due.month, due.day);
    return to.difference(from).inDays;
  }

  static String number(int value, String localeCode) =>
      '$_fsi${_localizeDigits(value.toString(), localeCode)}$_pdi';

  static (int, int, int) _toJalali(DateTime date) {
    final jalali = Jalali.fromDateTime(date);
    return (jalali.year, jalali.month, jalali.day);
  }

  static String _pad(int value) => value.toString().padLeft(2, '0');

  static String _localizeDigits(String input, String localeCode) {
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    const arabic = '٠١٢٣٤٥٦٧٨٩';
    final target = switch (localeCode) {
      'fa' => persian,
      'ar' => arabic,
      _ => null,
    };
    if (target == null) return input;

    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final code = rune - 0x30;
      buffer.write(
        code >= 0 && code <= 9 ? target[code] : String.fromCharCode(rune),
      );
    }
    return buffer.toString();
  }
}
