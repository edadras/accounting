import 'currency.dart';
import 'money.dart';

/// Formats [Money] for display.
///
/// Numerals are always laid out left-to-right even inside Persian or Arabic
/// text; the isolate characters below stop the bidi algorithm from moving a
/// minus sign or a currency symbol to the wrong end of the amount.
abstract final class MoneyFormatter {
  static const _fsi = '\u{2068}'; // first strong isolate
  static const _pdi = '\u{2069}'; // pop directional isolate

  /// [locale] drives digit shape and grouping separator.
  /// [compact] renders 1.2M / ۱٫۲M for dashboards where space is tight.
  static String format(
    Money money, {
    String locale = 'en',
    bool showSymbol = true,
    bool compact = false,
    bool isolate = true,
  }) {
    final currency = money.currency;
    final negative = money.isNegative;
    final units = money.minorUnits.abs();

    String body;
    if (compact) {
      body = _compact(units, currency, locale);
    } else {
      final divisor = _pow10(currency.minorUnit);
      final whole = units ~/ divisor;
      final fraction = units % divisor;
      final wholeText = _group(whole.toString(), locale);
      body = currency.minorUnit == 0
          ? wholeText
          : '$wholeText${_decimalSeparator(locale)}'
              '${fraction.toString().padLeft(currency.minorUnit, '0')}';
    }

    body = _localizeDigits(body, locale);

    if (showSymbol) {
      body = currency.symbolLeading
          ? '${currency.symbol}$body'
          : '$body ${currency.symbol}';
    }
    if (negative) body = '−$body';

    return isolate ? '$_fsi$body$_pdi' : body;
  }

  /// "+₺350.00" / "−₺350.00" — used in transaction rows where the sign is the
  /// fastest thing to read.
  static String formatSigned(
    Money money, {
    String locale = 'en',
    bool compact = false,
  }) {
    final text = format(money.absolute,
        locale: locale, compact: compact, isolate: false,);
    final sign = money.isNegative ? '−' : '+';
    return '$_fsi$sign$text$_pdi';
  }

  static String _compact(int units, Currency currency, String locale) {
    final major = units / _pow10(currency.minorUnit);
    const steps = [
      (1000000000, 'B'),
      (1000000, 'M'),
      (1000, 'K'),
    ];
    for (final (threshold, suffix) in steps) {
      if (major >= threshold) {
        final scaled = major / threshold;
        final text = scaled >= 100
            ? scaled.round().toString()
            : scaled.toStringAsFixed(1).replaceAll(RegExp(r'\.0$'), '');
        return '${text.replaceAll('.', _decimalSeparator(locale))}$suffix';
      }
    }
    return _group(major.round().toString(), locale);
  }

  static String _group(String digits, String locale) {
    final separator = _groupSeparator(locale);
    final buffer = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(separator);
      buffer.write(digits[i]);
    }
    return buffer.toString();
  }

  static String _groupSeparator(String locale) => switch (locale) {
        'fa' => '٬',
        'ar' => '٬',
        'tr' => '.',
        _ => ',',
      };

  static String _decimalSeparator(String locale) => switch (locale) {
        'fa' => '٫',
        'ar' => '٫',
        'tr' => ',',
        _ => '.',
      };

  static String _localizeDigits(String input, String locale) {
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    const arabic = '٠١٢٣٤٥٦٧٨٩';
    final target = switch (locale) {
      'fa' => persian,
      'ar' => arabic,
      _ => null,
    };
    if (target == null) return input;

    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final code = rune - 0x30;
      buffer.write(code >= 0 && code <= 9 ? target[code] : String.fromCharCode(rune));
    }
    return buffer.toString();
  }

  static int _pow10(int exponent) {
    var result = 1;
    for (var i = 0; i < exponent; i++) {
      result *= 10;
    }
    return result;
  }
}
