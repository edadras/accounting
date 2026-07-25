/// A currency the app can hold. `minorUnit` is the number of decimal places:
/// 2 for USD, 0 for IRR, 8 for BTC.
final class Currency {
  const Currency({
    required this.code,
    required this.symbol,
    required this.minorUnit,
    this.symbolLeading = true,
  });

  final String code;
  final String symbol;
  final int minorUnit;

  /// Whether the symbol goes before the number in LTR locales.
  final bool symbolLeading;

  static const irr = Currency(code: 'IRR', symbol: 'ریال', minorUnit: 0, symbolLeading: false);
  static const toman = Currency(code: 'IRT', symbol: 'تومان', minorUnit: 0, symbolLeading: false);
  static const try_ = Currency(code: 'TRY', symbol: '₺', minorUnit: 2);
  static const usd = Currency(code: 'USD', symbol: '\$', minorUnit: 2);
  static const eur = Currency(code: 'EUR', symbol: '€', minorUnit: 2);
  static const aed = Currency(code: 'AED', symbol: 'د.إ', minorUnit: 2, symbolLeading: false);
  static const btc = Currency(code: 'BTC', symbol: '₿', minorUnit: 8);
  static const eth = Currency(code: 'ETH', symbol: 'Ξ', minorUnit: 8);
  static const usdt = Currency(code: 'USDT', symbol: '₮', minorUnit: 2);

  static const all = <Currency>[irr, toman, try_, usd, eur, aed, btc, eth, usdt];

  static Currency? byCode(String code) {
    for (final currency in all) {
      if (currency.code == code.toUpperCase()) return currency;
    }
    return null;
  }

  @override
  bool operator ==(Object other) => other is Currency && other.code == code;

  @override
  int get hashCode => code.hashCode;

  @override
  String toString() => code;
}
