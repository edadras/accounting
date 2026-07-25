import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/data/ledger_repository.dart';
import 'package:finora/domain/ai/ai_models.dart';
import 'package:finora/domain/ai/ai_repository.dart';
import 'package:finora/domain/ai/nl_parser.dart';
import 'package:finora/domain/entities.dart';
import 'package:flutter_test/flutter_test.dart';

/// The parser is the part of the AI layer that has to work with no server, so
/// it is tested as a pure function against the sentences from
/// docs/08-ai-layer.md.
void main() {
  const parser = NaturalLanguageParser();
  final now = DateTime(2026, 7, 25, 14, 30);
  final yesterday = DateTime(2026, 7, 24);

  const categories = [
    Category(id: 'cat-food', name: 'خوراک', path: '/home/food', depth: 1),
    Category(
      id: 'cat-restaurant',
      name: 'رستوران',
      path: '/home/food/restaurant',
      depth: 2,
      parentId: 'cat-food',
    ),
    Category(id: 'cat-transport', name: 'حمل و نقل', path: '/transport', depth: 0),
  ];

  group('natural language capture', () {
    test('«دیروز ۳۵۰ لیر برای شام پرداخت کردم»', () {
      final draft = parser.parse(
        'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        now: now,
        categories: categories,
      )!;

      expect(draft.amount.value, const Money(35000, Currency.try_));
      expect(draft.amount.value.currency, Currency.try_);
      expect(draft.occurredAt.value, yesterday);
      expect(draft.type.value, TransactionType.expense);
      expect(draft.categoryId?.value, 'cat-restaurant');
      expect(draft.description, 'شام');
      expect(draft.confidence, greaterThan(0.85));
      expect(draft.needsReview, isFalse);
    });

    test('«۵۰ دلار امروز»', () {
      final draft = parser.parse('۵۰ دلار امروز', now: now)!;

      expect(draft.amount.value, const Money(5000, Currency.usd));
      expect(draft.occurredAt.value, DateTime(2026, 7, 25));
      expect(draft.type.value, TransactionType.expense);
    });

    test('Arabic digits parse the same as Persian and Latin ones', () {
      final arabic = parser.parse('دفعت ٣٥٠ ليرة أمس', now: now)!;
      final latin = parser.parse('paid 350 lira yesterday', now: now)!;

      expect(arabic.amount.value, const Money(35000, Currency.try_));
      expect(arabic.occurredAt.value, yesterday);
      expect(latin.amount.value, arabic.amount.value);
      expect(latin.occurredAt.value, arabic.occurredAt.value);
    });

    test('reads income, toman and a counted relative date', () {
      final draft = parser.parse(
        'حقوق ۵ روز پیش ۱۲ میلیون تومان گرفتم',
        now: now,
      )!;

      expect(draft.type.value, TransactionType.income);
      expect(draft.amount.value, const Money(12000000, Currency.toman));
      expect(draft.occurredAt.value, DateTime(2026, 7, 20));
    });

    test('falls back to the base currency and today, and says it is unsure', () {
      final draft = parser.parse('۹۰ برای تاکسی', now: now)!;

      expect(draft.amount.value.currency, Currency.try_);
      expect(draft.occurredAt.value, DateTime(2026, 7, 25));
      expect(draft.needsReview, isTrue,
          reason: 'no currency and no date stated — the user must confirm',);
    });

    test('a sentence with no amount produces no draft at all', () {
      expect(parser.parse('دیروز رفتم رستوران', now: now), isNull);
    });

    test('picks the number next to the currency word', () {
      final draft = parser.parse('۳۵۰ لیر برای ۲ نفر', now: now)!;
      expect(draft.amount.value, const Money(35000, Currency.try_));
    });
  });

  group('fake repository', () {
    late FakeAiRepository ai;

    setUp(() {
      ai = FakeAiRepository(
        ledger: InMemoryLedgerRepository(baseCurrency: Currency.try_),
        baseCurrency: Currency.try_,
        clock: () => now,
      );
    });

    test('parses against the workspace categories', () async {
      final draft = await ai.parseText('دیروز ۳۵۰ لیر برای شام پرداخت کردم');
      expect(draft!.categoryId?.value, 'cat-restaurant');
    });

    test('receipt fixture can disagree with itself', () async {
      final balanced = await ai.extractReceipt('sample');
      final skewed = await ai.extractReceipt('sample-mismatch');

      expect(balanced.balances, isTrue);
      expect(balanced.expectedTotal, balanced.total.value);
      expect(skewed.balances, isFalse);
      expect(skewed.discrepancy, const Money(2600, Currency.try_));
      expect(skewed.needsReview, isTrue);
    });

    test('every answer carries a source', () async {
      final answer = await ai.ask('این ماه پولم کجا رفت؟');

      expect(answer.role, ChatRole.assistant);
      expect(answer.bodyKey, 'ai.answer.month');
      expect(answer.source, isNotNull);
      expect(answer.source!.transactionCount, greaterThan(0));
    });

    test('insights are computed from the ledger, not invented', () async {
      final insights = await ai.insights();

      expect(insights, isNotEmpty);
      expect(
        insights.map((i) => i.messageKey),
        contains('ai.insight.topCategory'),
      );
      for (final insight in insights) {
        expect(insight.messageKey.startsWith('ai.insight.'), isTrue);
      }
    });
  });
}
