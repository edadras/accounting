import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/core/money/money_formatter.dart';
import 'package:flutter_test/flutter_test.dart';

/// The client's Money must agree with the server's, or the number the user sees
/// will not be the number that was stored. These mirror backend/tests/Unit/MoneyTest.php.
void main() {
  group('Money', () {
    test('refuses to add two different currencies', () {
      expect(
        () => const Money(35000, Currency.try_) + const Money(1000, Currency.usd),
        throwsArgumentError,
      );
    });

    test('parses latin, persian and arabic digits alike', () {
      expect(Money.tryParse('12.34', Currency.usd)?.minorUnits, 1234);
      expect(Money.tryParse('۱۲٫۳۴', Currency.usd)?.minorUnits, 1234);
      expect(Money.tryParse('١٢٫٣٤', Currency.usd)?.minorUnits, 1234);
      expect(Money.tryParse('1,234', Currency.usd)?.minorUnits, 123400);
      expect(Money.tryParse('۵۰۰٬۰۰۰', Currency.irr)?.minorUnits, 500000);
    });

    test('rejects more precision than the currency supports', () {
      expect(Money.tryParse('12.345', Currency.usd), isNull);
      expect(Money.tryParse('500.5', Currency.irr), isNull);
    });

    test('rejects text that is not a number', () {
      expect(Money.tryParse('abc', Currency.usd), isNull);
      expect(Money.tryParse('', Currency.usd), isNull);
      expect(Money.tryParse('1.2.3', Currency.usd), isNull);
    });

    test('converts across differing precision', () {
      const rials = Money(500000, Currency.irr);
      expect(rials.convertTo(Currency.usd, 0.0000167).minorUnits, 835);
    });

    test('rounds half up in both directions', () {
      expect(const Money(5, Currency.usd).convertTo(Currency.eur, 0.5).minorUnits, 3);
      expect(const Money(-5, Currency.usd).convertTo(Currency.eur, 0.5).minorUnits, -3);
    });
  });

  group('MoneyFormatter', () {
    test('uses persian digits and separators for fa', () {
      final text = MoneyFormatter.format(
        const Money(500000, Currency.irr),
        locale: 'fa',
        showSymbol: false,
        isolate: false,
      );
      expect(text, '۵۰۰٬۰۰۰');
    });

    test('uses latin digits and a leading symbol for en', () {
      final text = MoneyFormatter.format(
        const Money(123456, Currency.usd),
        locale: 'en',
        isolate: false,
      );
      expect(text, r'$1,234.56');
    });

    test('uses turkish separators for tr', () {
      final text = MoneyFormatter.format(
        const Money(123456, Currency.try_),
        locale: 'tr',
        isolate: false,
      );
      expect(text, '₺1.234,56');
    });

    test('isolates the amount so bidi cannot reorder it', () {
      final text = MoneyFormatter.format(const Money(1000, Currency.usd), locale: 'fa');
      expect(text.startsWith('\u{2068}'), isTrue);
      expect(text.endsWith('\u{2069}'), isTrue);
    });

    test('signs amounts for transaction rows', () {
      final out = MoneyFormatter.formatSigned(
        const Money(-35000, Currency.try_),
        locale: 'en',
      );
      expect(out.contains('−'), isTrue);
      expect(out.contains('350.00'), isTrue);
    });

    test('compacts large amounts', () {
      final text = MoneyFormatter.format(
        const Money(320000000, Currency.try_),
        locale: 'en',
        compact: true,
        isolate: false,
      );
      expect(text, '₺3.2M');
    });
  });
}
