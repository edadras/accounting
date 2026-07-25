import '../../core/money/currency.dart';
import '../../core/money/money.dart';

/// Translates between [Money] and the wire shape from docs/05-api-conventions.md §5:
///
/// ```json
/// {"value": 35000, "currency": "TRY", "minor_unit": 2, "decimal": "350.00"}
/// ```
///
/// `value` — an integer count of minor units — is the only field ever used for
/// arithmetic. `decimal` and `formatted` are display strings that this codec
/// writes but never reads back into a number, because parsing them would mean
/// going through a `double`.
abstract final class MoneyCodec {
  static const _valueKey = 'value';
  static const _currencyKey = 'currency';
  static const _minorUnitKey = 'minor_unit';
  static const _decimalKey = 'decimal';

  static Money decode(Object? raw, {Currency? fallbackCurrency}) {
    if (raw is! Map) {
      throw const FormatException('Money must be an object with a value.');
    }

    final currency = _currencyFrom(
      code: raw[_currencyKey] as String?,
      minorUnit: _asInt(raw[_minorUnitKey]),
      fallback: fallbackCurrency,
    );

    return Money(_asInt(raw[_valueKey]) ?? 0, currency);
  }

  static Money? tryDecode(Object? raw, {Currency? fallbackCurrency}) {
    try {
      return decode(raw, fallbackCurrency: fallbackCurrency);
    } on FormatException {
      return null;
    }
  }

  static Map<String, Object?> encode(Money money) => {
        _valueKey: money.minorUnits,
        _currencyKey: money.currency.code,
        _minorUnitKey: money.currency.minorUnit,
        _decimalKey: decimalString(money),
      };

  /// Integer-only rendering of the amount, mirroring the backend's
  /// `Money::toDecimalString()`. No `double` is involved at any point.
  static String decimalString(Money money) {
    final digits = money.minorUnits.abs().toString();
    final sign = money.isNegative ? '-' : '';
    final scale = money.currency.minorUnit;
    if (scale == 0) return '$sign$digits';

    final padded = digits.padLeft(scale + 1, '0');
    final whole = padded.substring(0, padded.length - scale);
    final fraction = padded.substring(padded.length - scale);
    return '$sign$whole.$fraction';
  }

  /// The server may hold currencies this build has never heard of, so an
  /// unknown code becomes a currency described entirely by the wire fields
  /// rather than an error the user cannot act on.
  static Currency _currencyFrom({
    required String? code,
    required int? minorUnit,
    required Currency? fallback,
  }) {
    if (code == null || code.isEmpty) {
      return fallback ?? Currency.irr;
    }

    final known = Currency.byCode(code);
    if (known != null && (minorUnit == null || known.minorUnit == minorUnit)) {
      return known;
    }

    return Currency(
      code: code.toUpperCase(),
      symbol: known?.symbol ?? code.toUpperCase(),
      minorUnit: minorUnit ?? known?.minorUnit ?? 2,
      symbolLeading: known?.symbolLeading ?? true,
    );
  }

  /// Accepts the integer JSON sends and the string a JSON encoder may produce
  /// for a big value, but never a floating-point amount.
  static int? _asInt(Object? raw) => switch (raw) {
        final int value => value,
        final String value => int.tryParse(value.trim()),
        _ => null,
      };
}
