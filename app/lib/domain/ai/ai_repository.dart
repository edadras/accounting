import 'package:collection/collection.dart';

import '../../core/money/currency.dart';
import '../../core/money/money.dart';
import '../../data/ledger_repository.dart';
import 'ai_models.dart';
import 'nl_parser.dart';

/// Everything the AI layer offers the UI.
///
/// Nothing on this interface writes to the ledger. Each method returns a
/// *proposal*; recording it is a separate, explicit act by the user. That is
/// the rule from docs/08-ai-layer.md expressed in the type signatures.
///
/// The server-backed implementation belongs in `lib/data/remote/` (owned
/// elsewhere) and implements exactly this interface — swapping it in is a
/// provider override, see `aiRepositoryProvider`.
abstract interface class AiRepository {
  /// Free text → draft. Null when the text holds no amount.
  Future<TransactionDraft?> parseText(String text, {DateTime? now});

  /// OCR result for a captured image, referenced by whatever handle the
  /// capture layer produced.
  Future<ReceiptDraft> extractReceipt(String reference);

  /// One question, one answer — always carrying its source.
  Future<ChatMessage> ask(String question);

  /// Statistical findings over the ledger.
  Future<List<AiInsight>> insights();
}

/// A working AI layer with no server behind it.
///
/// The parsing is real (see [NaturalLanguageParser]) and the insights and chat
/// answers are computed from the actual ledger, so every screen is demonstrable
/// and testable offline. Only the OCR and speech-to-text *inputs* are canned,
/// because those need hardware this build does not have.
final class FakeAiRepository implements AiRepository {
  FakeAiRepository({
    required this.ledger,
    required this.baseCurrency,
    this.latency = Duration.zero,
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final LedgerRepository ledger;
  final Currency baseCurrency;

  /// Lets the app show a realistic "thinking" state while tests run instantly.
  final Duration latency;

  final DateTime Function() _clock;

  var _sequence = 0;

  String _nextId() => 'ai-${++_sequence}';

  Future<void> _think() async {
    if (latency > Duration.zero) await Future<void>.delayed(latency);
  }

  @override
  Future<TransactionDraft?> parseText(String text, {DateTime? now}) async {
    await _think();
    final categories = await ledger.categories();
    return NaturalLanguageParser(baseCurrency: baseCurrency).parse(
      text,
      now: now ?? _clock(),
      categories: categories,
    );
  }

  @override
  Future<ReceiptDraft> extractReceipt(String reference) async {
    await _think();
    final now = _clock();
    final lines = [
      ReceiptLine(
        label: 'Köfte',
        quantity: 2,
        unitPrice: Money(14500, baseCurrency),
      ),
      ReceiptLine(
        label: 'Ayran',
        quantity: 2,
        unitPrice: Money(3200, baseCurrency),
      ),
      ReceiptLine(
        label: 'Baklava',
        quantity: 1,
        unitPrice: Money(9800, baseCurrency),
      ),
    ];
    final tax = Money(4500, baseCurrency);
    final sum = lines.fold(
      Money.zero(baseCurrency),
      (Money acc, ReceiptLine line) => acc + line.total,
    );

    // A receipt whose numbers do not add up is the interesting case, so the
    // fixture can produce one on demand rather than only the happy path.
    final skewed = reference.contains('mismatch');
    final total = skewed ? sum + tax + Money(2600, baseCurrency) : sum + tax;

    return ReceiptDraft(
      merchant: Confident(skewed ? 'Kebapçı Ali' : 'Migros', skewed ? 0.52 : 0.93),
      occurredAt: Confident(
        DateTime(now.year, now.month, now.day).subtract(const Duration(days: 1)),
        skewed ? 0.61 : 0.88,
      ),
      tax: Confident(tax, skewed ? 0.58 : 0.9),
      total: Confident(total, 0.94),
      lines: lines,
    );
  }

  @override
  Future<ChatMessage> ask(String question) async {
    await _think();

    final summary = await ledger.summary();
    final transactions = await ledger.transactions();
    final now = _clock();
    final monthStart = DateTime(now.year, now.month);
    final inMonth = transactions
        .where((tx) => !tx.occurredAt.isBefore(monthStart))
        .toList();
    final source = AnswerSource(
      transactionCount: inMonth.length,
      from: monthStart,
      to: now,
      transactionIds: [for (final tx in inMonth) tx.id],
    );

    final text = NaturalLanguageParser.normalize(question);

    if (RegExp(r'مانده|موجودی|دارایی|balance|net worth|bakiye|رصید')
        .hasMatch(text)) {
      return ChatMessage(
        id: _nextId(),
        role: ChatRole.assistant,
        bodyKey: 'ai.answer.balance',
        amount: summary.netWorth,
        source: AnswerSource(
          transactionCount: transactions.length,
          from: transactions.isEmpty ? monthStart : transactions.last.occurredAt,
          to: now,
          transactionIds: [for (final tx in transactions) tx.id],
        ),
      );
    }

    final categoryHit = summary.byCategory.where((slice) {
      return text.contains(NaturalLanguageParser.normalize(slice.label));
    }).firstOrNull;

    if (categoryHit != null) {
      final ids = [
        for (final tx in inMonth)
          if (tx.categoryId == categoryHit.categoryId) tx.id,
      ];
      return ChatMessage(
        id: _nextId(),
        role: ChatRole.assistant,
        bodyKey: 'ai.answer.category',
        args: {'category': categoryHit.label},
        amount: categoryHit.amount,
        source: AnswerSource(
          transactionCount: ids.length,
          from: monthStart,
          to: now,
          transactionIds: ids,
        ),
      );
    }

    if (summary.byCategory.isNotEmpty &&
        RegExp(r'کجا|خرج|هزینه|این ماه|spend|spent|month|where|harca|انفاق')
            .hasMatch(text)) {
      return ChatMessage(
        id: _nextId(),
        role: ChatRole.assistant,
        bodyKey: 'ai.answer.month',
        args: {
          'category': summary.byCategory.first.label,
          'percent': (summary.topCategoryShare * 100).round().toString(),
        },
        amount: summary.spentThisMonth,
        source: source,
      );
    }

    // Saying "I cannot answer that yet" beats inventing a number.
    return ChatMessage(
      id: _nextId(),
      role: ChatRole.assistant,
      bodyKey: 'ai.answer.unknown',
    );
  }

  @override
  Future<List<AiInsight>> insights() async {
    await _think();

    final summary = await ledger.summary();
    final budgets = await ledger.budgets();
    final found = <AiInsight>[];

    if (summary.byCategory.isNotEmpty) {
      final share = (summary.topCategoryShare * 100).round();
      found.add(AiInsight(
        id: 'insight-top-category',
        severity: share >= 60 ? InsightSeverity.warning : InsightSeverity.info,
        messageKey: 'ai.insight.topCategory',
        args: {
          'percent': share.toString(),
          'category': summary.byCategory.first.label,
        },
        amount: summary.byCategory.first.amount,
      ),);
    }

    for (final budget in budgets) {
      if (budget.isBreached) {
        found.add(AiInsight(
          id: 'insight-budget-${budget.id}',
          severity: InsightSeverity.critical,
          messageKey: 'ai.insight.budgetBreached',
          args: {'budget': budget.name},
          amount: budget.remaining.absolute,
        ),);
      } else if (budget.isNearLimit) {
        found.add(AiInsight(
          id: 'insight-budget-${budget.id}',
          severity: InsightSeverity.warning,
          messageKey: 'ai.insight.budgetNear',
          args: {
            'budget': budget.name,
            'percent': (budget.progress * 100).round().toString(),
          },
          amount: budget.remaining,
        ),);
      }
    }

    if (summary.earnedThisMonth.minorUnits > summary.spentThisMonth.minorUnits) {
      found.add(AiInsight(
        id: 'insight-surplus',
        severity: InsightSeverity.positive,
        messageKey: 'ai.insight.surplus',
        amount: summary.earnedThisMonth - summary.spentThisMonth,
      ),);
    } else if (!summary.earnedThisMonth.isZero) {
      found.add(AiInsight(
        id: 'insight-shortfall',
        severity: InsightSeverity.critical,
        messageKey: 'ai.insight.shortfall',
        amount: summary.spentThisMonth - summary.earnedThisMonth,
      ),);
    }

    return found;
  }
}
