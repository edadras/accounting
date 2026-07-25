import 'currency.dart';

/// An exact monetary amount.
///
/// [minorUnits] is the amount in the currency's smallest unit — 1234 for
/// USD 12.34, 500000 for IRR 500,000, 12345 for BTC 0.00012345.
///
/// There is no `double` anywhere in this class, and there never will be.
/// Two amounts in different currencies cannot be added; the caller must
/// convert first and say at what rate.
final class Money implements Comparable<Money> {
  const Money(this.minorUnits, this.currency);

  final int minorUnits;
  final Currency currency;

  static Money zero(Currency currency) => Money(0, currency);

  /// Parses user input such as `12.34`, `۱۲٫۳۴`, `1,234`, `1٬234`.
  /// Returns null when the text is not a valid amount for [currency].
  static Money? tryParse(String input, Currency currency) {
    final normalized = _normalizeDigits(input)
        .replaceAll(RegExp(r'[\s,٬،]'), '')
        .replaceAll('٫', '.')
        .trim();
    if (normalized.isEmpty) return null;
    if (!RegExp(r'^-?\d*\.?\d*$').hasMatch(normalized)) return null;

    final negative = normalized.startsWith('-');
    final body = negative ? normalized.substring(1) : normalized;
    final parts = body.split('.');
    if (parts.length > 2) return null;

    final whole = parts[0].isEmpty ? '0' : parts[0];
    var fraction = parts.length == 2 ? parts[1] : '';
    if (fraction.length > currency.minorUnit) {
      // More precision than the currency has: reject rather than silently
      // truncating someone's money.
      return null;
    }
    fraction = fraction.padRight(currency.minorUnit, '0');

    final combined = int.tryParse('$whole$fraction');
    if (combined == null) return null;
    return Money(negative ? -combined : combined, currency);
  }

  static String _normalizeDigits(String input) {
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    const arabic = '٠١٢٣٤٥٦٧٨٩';
    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final char = String.fromCharCode(rune);
      final pi = persian.indexOf(char);
      final ai = arabic.indexOf(char);
      buffer.write(pi >= 0
          ? '$pi'
          : ai >= 0
              ? '$ai'
              : char,);
    }
    return buffer.toString();
  }

  bool get isZero => minorUnits == 0;
  bool get isNegative => minorUnits < 0;
  bool get isPositive => minorUnits > 0;

  Money get absolute => Money(minorUnits.abs(), currency);
  Money get negated => Money(-minorUnits, currency);

  Money operator +(Money other) {
    _assertSameCurrency(other, '+');
    return Money(minorUnits + other.minorUnits, currency);
  }

  Money operator -(Money other) {
    _assertSameCurrency(other, '-');
    return Money(minorUnits - other.minorUnits, currency);
  }

  /// Converts to [target] at [rate], rounding half-up on the final minor unit.
  ///
  /// [rate] is expressed as "1 unit of this currency = rate units of target",
  /// in major units, matching how humans and rate providers quote it.
  Money convertTo(Currency target, double rate) {
    if (currency == target) return this;
    final scale = _pow10(target.minorUnit) / _pow10(currency.minorUnit);
    final raw = minorUnits * rate * scale;
    return Money(_roundHalfUp(raw), target);
  }

  static int _roundHalfUp(double value) =>
      value < 0 ? -((-value) + 0.5).floor() : (value + 0.5).floor();

  static double _pow10(int exponent) {
    var result = 1.0;
    for (var i = 0; i < exponent; i++) {
      result *= 10;
    }
    return result;
  }

  void _assertSameCurrency(Money other, String op) {
    if (other.currency != currency) {
      throw ArgumentError(
        'Cannot apply "$op" to ${currency.code} and ${other.currency.code}. '
        'Convert explicitly with convertTo() and record the rate.',
      );
    }
  }

  @override
  int compareTo(Money other) {
    _assertSameCurrency(other, 'compare');
    return minorUnits.compareTo(other.minorUnits);
  }

  @override
  bool operator ==(Object other) =>
      other is Money &&
      other.minorUnits == minorUnits &&
      other.currency == currency;

  @override
  int get hashCode => Object.hash(minorUnits, currency);

  @override
  String toString() => '${currency.code} $minorUnits(minor)';
}
