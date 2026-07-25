/// Small formatting helpers the AI screens need and money formatting does not
/// cover: percentages and plain dates still have to carry the locale's digit
/// shape, or a Persian screen ends up with two different numeral systems on it.
abstract final class AiFormat {
  static const _persianDigits = '۰۱۲۳۴۵۶۷۸۹';
  static const _arabicDigits = '٠١٢٣٤٥٦٧٨٩';

  static String digits(String input, String locale) {
    final target = switch (locale) {
      'fa' => _persianDigits,
      'ar' => _arabicDigits,
      _ => null,
    };
    if (target == null) return input;

    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final index = rune - 0x30;
      buffer.write(
        index >= 0 && index <= 9 ? target[index] : String.fromCharCode(rune),
      );
    }
    return buffer.toString();
  }

  /// Matches the day headers already used in the transaction list.
  static String day(DateTime date, String locale) => digits(
        '${date.year}/${date.month.toString().padLeft(2, '0')}/'
        '${date.day.toString().padLeft(2, '0')}',
        locale,
      );

  static String percent(double fraction, String locale) =>
      digits((fraction * 100).round().toString(), locale);
}
