/// A non-monetary quantity — grams of gold, shares, square metres.
///
/// It exists for the same reason [Money] does: a holding of 12.345 g is stored
/// as an exact scaled integer, never as a double that drifts. It deliberately
/// does *not* extend Money — a quantity has no currency and must never be
/// summed with one.
final class Quantity implements Comparable<Quantity> {
  const Quantity(this.scaledUnits, {this.decimals = 0});

  /// The value multiplied by 10^[decimals]. 12345 with 3 decimals is 12.345.
  final int scaledUnits;
  final int decimals;

  bool get isZero => scaledUnits == 0;

  @override
  int compareTo(Quantity other) => scaledUnits.compareTo(other.scaledUnits);

  @override
  bool operator ==(Object other) =>
      other is Quantity &&
      other.scaledUnits == scaledUnits &&
      other.decimals == decimals;

  @override
  int get hashCode => Object.hash(scaledUnits, decimals);

  @override
  String toString() => 'Quantity($scaledUnits e-$decimals)';
}

/// Renders a [Quantity] with the locale's digits and separators.
///
/// Kept separate from [MoneyFormatter] so neither grows a flag that means
/// "actually this one is not money".
abstract final class QuantityFormatter {
  static const _fsi = '\u{2068}';
  static const _pdi = '\u{2069}';

  static String format(Quantity quantity, {String locale = 'en'}) {
    final negative = quantity.scaledUnits < 0;
    final units = quantity.scaledUnits.abs();
    var divisor = 1;
    for (var i = 0; i < quantity.decimals; i++) {
      divisor *= 10;
    }

    final whole = _group(( units ~/ divisor).toString(), locale);
    var body = whole;
    if (quantity.decimals > 0) {
      final fraction = (units % divisor)
          .toString()
          .padLeft(quantity.decimals, '0')
          // A holding of "12.500 g" reads as false precision; drop the tail.
          .replaceAll(RegExp(r'0+$'), '');
      if (fraction.isNotEmpty) {
        body = '$whole${_decimalSeparator(locale)}$fraction';
      }
    }

    body = _localizeDigits(body, locale);
    if (negative) body = '−$body';
    return '$_fsi$body$_pdi';
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
        'fa' || 'ar' => '٬',
        'tr' => '.',
        _ => ',',
      };

  static String _decimalSeparator(String locale) => switch (locale) {
        'fa' || 'ar' => '٫',
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
      buffer.write(
        code >= 0 && code <= 9 ? target[code] : String.fromCharCode(rune),
      );
    }
    return buffer.toString();
  }

  /// Percentages are the other number these screens show constantly — ROI,
  /// collection rate, depreciation. Same digit rules, no currency.
  static String percent(double value, {String locale = 'en', bool signed = false}) {
    final rounded = value.abs().round();
    final digits = _localizeDigits(rounded.toString(), locale);
    final sign = !signed
        ? ''
        : value < 0
            ? '−'
            : '+';
    return '$_fsi$sign$digits٪$_pdi';
  }
}
