import '../../core/money/currency.dart';
import '../../core/money/money.dart';
import '../entities.dart';
import 'ai_models.dart';

/// Turns a sentence into a [TransactionDraft].
///
/// This is the deterministic half of the pipeline in docs/08-ai-layer.md:
/// normalise the text, then pull out amount, currency and relative date with
/// rules. A model is only needed for the part rules cannot do — disambiguating
/// an unusual category — so the app stays useful with no server at all, and
/// every extraction here is testable without a network.
final class NaturalLanguageParser {
  const NaturalLanguageParser({this.baseCurrency = Currency.try_});

  final Currency baseCurrency;

  /// Returns null when the text contains no amount: without one there is no
  /// transaction to propose, and inventing a number would be the worst
  /// possible failure mode for a finance app.
  TransactionDraft? parse(
    String input, {
    DateTime? now,
    List<Category> categories = const [],
  }) {
    final at = now ?? DateTime.now();
    final text = normalize(input);

    final date = _date(text, at);
    // The digits of "5 days ago" must not be mistaken for the amount, so the
    // phrase that produced the date is removed before the amount is read.
    final forAmount =
        date.consumed == null ? text : text.replaceFirst(date.consumed!, ' ');

    final currency = _currency(forAmount);
    final amount = _amount(forAmount, currency?.value ?? baseCurrency, currency?.start);
    if (amount == null) return null;

    final type = _type(text);
    final category = _category(text, categories);

    return TransactionDraft(
      sourceText: input.trim(),
      type: type,
      amount: amount,
      occurredAt: date.value,
      currencyConfidence: currency?.confidence ?? 0.45,
      categoryId: category?.id,
      description: category?.keyword,
    );
  }

  // --------------------------------------------------------------- normalise

  /// Persian/Arabic digits to ASCII, Arabic letter shapes to Persian ones, and
  /// zero-width joiners to spaces so «هفتهٔ پیش» and «هفته‌ی پیش» both match the
  /// same pattern.
  static String normalize(String input) {
    const persianDigits = '۰۱۲۳۴۵۶۷۸۹';
    const arabicDigits = '٠١٢٣٤٥٦٧٨٩';
    const letterFolds = {
      'ي': 'ی',
      'ك': 'ک',
      'ة': 'ه',
      'أ': 'ا',
      'إ': 'ا',
      'آ': 'ا',
    };

    final buffer = StringBuffer();
    for (final rune in input.runes) {
      final char = String.fromCharCode(rune);
      if (char == '‌' || char == '‏' || char == 'ٔ') {
        buffer.write(' ');
        continue;
      }
      final persian = persianDigits.indexOf(char);
      if (persian >= 0) {
        buffer.write('$persian');
        continue;
      }
      final arabic = arabicDigits.indexOf(char);
      if (arabic >= 0) {
        buffer.write('$arabic');
        continue;
      }
      buffer.write(letterFolds[char] ?? char);
    }
    return buffer.toString().toLowerCase();
  }

  // -------------------------------------------------------------------- date

  static final _relativeDays = <(RegExp, int)>[
    (RegExp(r'پریروز|اول\s+امس|day before yesterday|evvelsi gün'), -2),
    // «امس» is Arabic for yesterday but is also the start of «امسال» (this
    // year), so it only counts when nothing follows it.
    (RegExp(r'دیروز|امس(?!ال)|yesterday|dün'), -1),
    (RegExp(r'امروز|اليوم|today|bugün'), 0),
    (RegExp(r'فردا|غدا|tomorrow|yarın'), 1),
    (RegExp(r'هفته\s*ی?\s*(پیش|قبل|گذشته)|last week|geçen hafta|الاسبوع الماضی'), -7),
    (RegExp(r'ماه\s*(پیش|قبل|گذشته)|last month|geçen ay|الشهر الماضی'), -30),
  ];

  static final _daysAgo = RegExp(
    r'(\d+)\s*(روز|يوم|day|days|gün)\s*(پیش|قبل|ago|önce)',
  );

  ({Confident<DateTime> value, String? consumed}) _date(
    String text,
    DateTime now,
  ) {
    final today = DateTime(now.year, now.month, now.day);

    final counted = _daysAgo.firstMatch(text);
    if (counted != null) {
      final days = int.tryParse(counted.group(1)!) ?? 0;
      return (
        value: Confident(today.subtract(Duration(days: days)), 0.9),
        consumed: counted.group(0),
      );
    }

    if (RegExp(r'اول\s*ماه|start of the month').hasMatch(text)) {
      return (
        value: Confident(DateTime(now.year, now.month), 0.8),
        consumed: null,
      );
    }

    for (final (pattern, offset) in _relativeDays) {
      final match = pattern.firstMatch(text);
      if (match != null) {
        return (
          value: Confident(today.add(Duration(days: offset)), 0.9),
          consumed: null,
        );
      }
    }

    // Nothing said when, so today is the best guess — and it is marked as a
    // guess rather than presented as read from the sentence.
    return (value: Confident(today, 0.5), consumed: null);
  }

  // ---------------------------------------------------------------- currency

  static final _currencyWords = <(RegExp, Currency)>[
    (RegExp(r'لیر|liras?|lira|try\b|\btl\b'), Currency.try_),
    (RegExp(r'دلار|dollars?|usd\b|\$'), Currency.usd),
    (RegExp(r'تومان|تومن|toman'), Currency.toman),
    (RegExp(r'ریال|rial|irr\b'), Currency.irr),
    (RegExp(r'یورو|euros?|eur\b|€'), Currency.eur),
    (RegExp(r'درهم|dirhams?|aed\b'), Currency.aed),
  ];

  ({Currency value, double confidence, int start})? _currency(String text) {
    for (final (pattern, currency) in _currencyWords) {
      final match = pattern.firstMatch(text);
      if (match != null) {
        return (value: currency, confidence: 0.95, start: match.start);
      }
    }
    return null;
  }

  // ------------------------------------------------------------------ amount

  static final _number = RegExp(r'\d+(?:[.,٬،٫]\d+)*');
  static final _multiplier = RegExp(r'^\s*(هزار|thousand|\bk\b|میلیون|million|\bm\b)');

  Confident<Money>? _amount(String text, Currency currency, int? currencyAt) {
    final matches = _number.allMatches(text).toList();
    if (matches.isEmpty) return null;

    // When a currency word is present the number next to it is the amount —
    // "350 lira for 2 people" must not read as 2.
    final match = currencyAt == null
        ? matches.first
        : matches.reduce((a, b) =>
            _distance(a, currencyAt) <= _distance(b, currencyAt) ? a : b,);

    final money = Money.tryParse(match.group(0)!, currency);
    if (money == null || !money.isPositive) return null;

    final tail = text.substring(match.end);
    final scale = _multiplier.firstMatch(tail);
    final scaled = switch (scale?.group(1)) {
      'هزار' || 'thousand' || 'k' => Money(money.minorUnits * 1000, currency),
      'میلیون' || 'million' || 'm' => Money(money.minorUnits * 1000000, currency),
      _ => money,
    };

    return Confident(scaled, currencyAt == null ? 0.8 : 0.95);
  }

  static int _distance(RegExpMatch match, int index) =>
      match.start <= index ? index - match.end : match.start - index;

  // -------------------------------------------------------------------- type

  static final _incoming = RegExp(
    r'حقوق|دستمزد|درآمد|گرفتم|دریافت|فروختم|salary|wage|income|received|earned|maaş|gelir|راتب',
  );
  static final _outgoing = RegExp(
    r'پرداخت|خریدم|دادم|هزینه|خرج|paid|spent|bought|ödedim|harcadım|دفعت|اشتریت',
  );

  Confident<TransactionType> _type(String text) {
    if (_incoming.hasMatch(text)) {
      return const Confident(TransactionType.income, 0.9);
    }
    if (_outgoing.hasMatch(text)) {
      return const Confident(TransactionType.expense, 0.9);
    }
    // Expense is right far more often than not, but that is a prior, not
    // something the sentence said.
    return const Confident(TransactionType.expense, 0.7);
  }

  // ---------------------------------------------------------------- category

  /// Keyword → the category path segments to look for, most specific first.
  static final _categoryHints = <(RegExp, List<String>)>[
    (
      RegExp(r'شام|ناهار|صبحانه|رستوران|کافه|dinner|lunch|breakfast|restaurant|cafe|yemek|عشاء'),
      ['restaurant', 'food'],
    ),
    (
      RegExp(r'سوپرمارکت|خواربار|میوه|نان|خوراک|grocery|groceries|supermarket|market|food'),
      ['food'],
    ),
    (
      RegExp(r'تاکسی|بنزین|اتوبوس|مترو|بلیط|taxi|fuel|petrol|gas|bus|metro|ticket|uber'),
      ['transport'],
    ),
    (
      RegExp(r'قبض|برق|گاز|اینترنت|اجاره|bill|electricity|water|internet|rent|fatura'),
      ['bills'],
    ),
    (
      RegExp(r'سینما|تفریح|کنسرت|بازی|cinema|movie|concert|game|leisure'),
      ['leisure'],
    ),
    (
      RegExp(r'حقوق|دستمزد|salary|wage|maaş'),
      ['salary'],
    ),
  ];

  ({Confident<String> id, String keyword})? _category(
    String text,
    List<Category> categories,
  ) {
    if (categories.isEmpty) return null;

    for (final (pattern, slugs) in _categoryHints) {
      final hit = pattern.firstMatch(text);
      if (hit == null) continue;

      for (var i = 0; i < slugs.length; i++) {
        for (final category in categories) {
          if (!category.path.endsWith('/${slugs[i]}')) continue;
          // A fallback to the parent category is a weaker claim than a direct
          // hit, and says so.
          return (
            id: Confident(category.id, i == 0 ? 0.86 : 0.62),
            keyword: hit.group(0)!,
          );
        }
      }
    }
    return null;
  }
}
