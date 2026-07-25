import '../../core/money/money.dart';
import '../entities.dart';

/// Below this score a value is not trustworthy enough to show as plain fact:
/// docs/08-ai-layer.md requires the field to be flagged and the user asked.
const double kConfidenceFloor = 0.7;

/// A value the AI produced, together with how sure it is of it.
///
/// Confidence travels *with* the value rather than in a parallel map, because
/// the one thing that must never happen is a number rendered without its
/// uncertainty.
final class Confident<T> {
  const Confident(this.value, this.confidence);

  final T value;
  final double confidence;

  bool get isUncertain => confidence < kConfidenceFloor;

  Confident<T> withValue(T next) => Confident<T>(next, confidence);

  @override
  String toString() => '$value (${(confidence * 100).round()}%)';
}

/// A proposed transaction. **Never** a recorded one — the product rule is that
/// AI proposes and the user decides, so nothing here reaches the ledger until
/// the user confirms it.
final class TransactionDraft {
  const TransactionDraft({
    required this.sourceText,
    required this.type,
    required this.amount,
    required this.occurredAt,
    required this.currencyConfidence,
    this.categoryId,
    this.description,
    this.accountId,
  });

  /// What the user actually said or typed, kept so the confirmation screen can
  /// show it next to the interpretation.
  final String sourceText;

  final Confident<TransactionType> type;
  final Confident<Money> amount;
  final Confident<DateTime> occurredAt;

  /// The currency is carried inside [amount]; this is how sure the parser is
  /// that it picked the right one rather than falling back to the base
  /// currency.
  final double currencyConfidence;

  final Confident<String>? categoryId;
  final String? description;
  final String? accountId;

  /// Weighted because the amount is the field a wrong guess hurts most.
  double get confidence =>
      0.4 * amount.confidence +
      0.2 * currencyConfidence +
      0.2 * occurredAt.confidence +
      0.2 * type.confidence;

  bool get needsReview => confidence < kConfidenceFloor;

  TransactionDraft copyWith({
    Confident<TransactionType>? type,
    Confident<Money>? amount,
    Confident<DateTime>? occurredAt,
    double? currencyConfidence,
    Confident<String>? categoryId,
    bool clearCategory = false,
    String? description,
    String? accountId,
  }) {
    return TransactionDraft(
      sourceText: sourceText,
      type: type ?? this.type,
      amount: amount ?? this.amount,
      occurredAt: occurredAt ?? this.occurredAt,
      currencyConfidence: currencyConfidence ?? this.currencyConfidence,
      categoryId: clearCategory ? null : (categoryId ?? this.categoryId),
      description: description ?? this.description,
      accountId: accountId ?? this.accountId,
    );
  }
}

final class ReceiptLine {
  const ReceiptLine({
    required this.label,
    required this.quantity,
    required this.unitPrice,
  });

  final String label;
  final int quantity;
  final Money unitPrice;

  Money get total => Money(unitPrice.minorUnits * quantity, unitPrice.currency);
}

/// The structured result of reading a receipt photo.
final class ReceiptDraft {
  const ReceiptDraft({
    required this.merchant,
    required this.occurredAt,
    required this.tax,
    required this.total,
    required this.lines,
  });

  final Confident<String> merchant;
  final Confident<DateTime> occurredAt;
  final Confident<Money> tax;
  final Confident<Money> total;
  final List<ReceiptLine> lines;

  Money get lineTotal => lines.fold(
        Money.zero(total.value.currency),
        (sum, line) => sum + line.total,
      );

  Money get expectedTotal => lineTotal + tax.value;

  /// Printed total minus what the lines add up to. Surfaced, never hidden — an
  /// OCR result that does not balance is exactly the one a human must check.
  Money get discrepancy => total.value - expectedTotal;

  bool get balances => discrepancy.isZero;

  bool get needsReview =>
      !balances ||
      merchant.isUncertain ||
      occurredAt.isUncertain ||
      tax.isUncertain ||
      total.isUncertain;
}

enum InsightSeverity { positive, info, warning, critical }

/// One statistical finding about the ledger.
///
/// The text is a translation key plus arguments rather than a sentence, so an
/// insight computed on the server or on the device reads in whichever language
/// the user has chosen. [amount] is left as [Money] and formatted by the UI,
/// which is the only layer that knows the locale.
final class AiInsight {
  const AiInsight({
    required this.id,
    required this.severity,
    required this.messageKey,
    this.args = const {},
    this.amount,
  });

  final String id;
  final InsightSeverity severity;
  final String messageKey;
  final Map<String, String> args;
  final Money? amount;
}

enum ChatRole { user, assistant }

/// Where an answer came from. Every assistant answer carries one, so the user
/// can always get from a claim to the rows behind it.
final class AnswerSource {
  const AnswerSource({
    required this.transactionCount,
    required this.from,
    required this.to,
    this.transactionIds = const [],
  });

  final int transactionCount;
  final DateTime from;
  final DateTime to;
  final List<String> transactionIds;
}

final class ChatMessage {
  const ChatMessage({
    required this.id,
    required this.role,
    this.text,
    this.bodyKey,
    this.args = const {},
    this.amount,
    this.source,
  });

  final String id;
  final ChatRole role;

  /// What the user typed. Kept verbatim — never translated.
  final String? text;

  /// Assistant answers are keyed instead, so they render in the user's
  /// language rather than whichever one the model happened to reply in.
  final String? bodyKey;
  final Map<String, String> args;
  final Money? amount;
  final AnswerSource? source;
}
