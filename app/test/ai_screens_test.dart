import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/money/currency.dart';
import 'package:finora/core/money/money.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/ledger_repository.dart';
import 'package:finora/domain/ai/ai_models.dart';
import 'package:finora/domain/analytics.dart';
import 'package:finora/domain/entities.dart';
import 'package:finora/main.dart';
import 'package:finora/presentation/features/ai/ai_capture_screen.dart';
import 'package:finora/presentation/features/ai/ai_chat_screen.dart';
import 'package:finora/presentation/features/ai/ai_hub_screen.dart';
import 'package:finora/presentation/features/ai/insights_screen.dart';
import 'package:finora/presentation/features/ai/receipt_review_screen.dart';
import 'package:finora/presentation/features/ai/voice_capture_screen.dart';
import 'package:finora/presentation/features/ai/widgets/draft_editor.dart';
import 'package:finora/presentation/features/ai/widgets/review_banner.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Counts writes so a test can assert the thing that matters most about this
/// feature: that nothing reaches the ledger without a confirmation.
final class _SpyLedger implements LedgerRepository {
  _SpyLedger() : _inner = InMemoryLedgerRepository(baseCurrency: Currency.try_);

  final InMemoryLedgerRepository _inner;
  final List<Transaction> recorded = [];

  @override
  Future<List<Account>> accounts() => _inner.accounts();

  @override
  Future<List<Category>> categories() => _inner.categories();

  @override
  Future<List<Transaction>> transactions({int limit = 100}) =>
      _inner.transactions(limit: limit);

  @override
  Future<DashboardSummary> summary() => _inner.summary();

  @override
  Future<List<Budget>> budgets() => _inner.budgets();

  @override
  Future<CashFlowReport> cashFlow({int months = 6}) =>
      _inner.cashFlow(months: months);

  @override
  Future<Transaction> record(Transaction draft) {
    recorded.add(draft);
    return _inner.record(draft);
  }
}

void main() {
  late _SpyLedger ledger;

  setUp(() => ledger = _SpyLedger());

  Future<void> pumpRoute(
    WidgetTester tester,
    Route<void> Function() route, {
    AppLocale locale = AppLocale.en,
  }) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => locale),
          ledgerRepositoryProvider.overrideWith((ref) => ledger),
        ],
        child: MaterialApp(
          theme: NeonTheme.dark(),
          locale: locale.locale,
          supportedLocales: [for (final l in AppLocale.supported) l.locale],
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          builder: (context, child) => Directionality(
            textDirection: locale.textDirection,
            child: child ?? const SizedBox.shrink(),
          ),
          home: Builder(
            builder: (context) => Scaffold(
              body: Center(
                child: TextButton(
                  onPressed: () => Navigator.of(context).push(route()),
                  child: const Text('open'),
                ),
              ),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();
  }

  String tr(AppLocale locale, String key) => Translator(locale: locale)(key);

  /// The draft sits below the fold on a 800×600 test surface, exactly as it
  /// would on a phone.
  Future<void> scrollTo(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
  }

  group('every AI screen builds in both writing directions', () {
    final routes = <String, Route<void> Function()>{
      'hub': AiHubScreen.route,
      'capture': AiCaptureScreen.route,
      'voice': VoiceCaptureScreen.route,
      'receipt': ReceiptReviewScreen.route,
      'chat': AiChatScreen.route,
      'insights': AiInsightsScreen.route,
    };

    for (final locale in [AppLocale.fa, AppLocale.en]) {
      for (final entry in routes.entries) {
        testWidgets('${entry.key} — ${locale.code}', (tester) async {
          await pumpRoute(tester, entry.value, locale: locale);

          expect(tester.takeException(), isNull);
          expect(
            Directionality.of(tester.element(find.byType(Scaffold).last)),
            locale.textDirection,
          );
        });
      }
    }
  });

  testWidgets('a low-confidence draft shows its warning, a confident one does not',
      (tester) async {
    final vague = TransactionDraft(
      sourceText: '90',
      type: const Confident(TransactionType.expense, 0.6),
      amount: const Confident(Money(9000, Currency.try_), 0.55),
      occurredAt: Confident(DateTime(2026, 7, 25), 0.5),
      currencyConfidence: 0.4,
    );

    await pumpRoute(
      tester,
      () => MaterialPageRoute<void>(
        builder: (_) => Scaffold(
          body: SingleChildScrollView(child: DraftEditor(draft: vague)),
        ),
      ),
    );

    expect(vague.needsReview, isTrue);
    expect(find.byType(ReviewBanner), findsOneWidget);
    expect(find.text(tr(AppLocale.en, 'ai.draft.review')), findsOneWidget);
    // Uncertain fields are called out rather than shown as plain values.
    expect(find.text(tr(AppLocale.en, 'ai.draft.uncertain')), findsNothing);
    expect(find.textContaining(tr(AppLocale.en, 'ai.draft.uncertain')),
        findsWidgets,);
  });

  testWidgets('a confident draft renders without the warning', (tester) async {
    final sure = TransactionDraft(
      sourceText: 'paid 350 lira for dinner yesterday',
      type: const Confident(TransactionType.expense, 0.9),
      amount: const Confident(Money(35000, Currency.try_), 0.95),
      occurredAt: Confident(DateTime(2026, 7, 24), 0.9),
      currencyConfidence: 0.95,
    );

    await pumpRoute(
      tester,
      () => MaterialPageRoute<void>(
        builder: (_) => Scaffold(
          body: SingleChildScrollView(child: DraftEditor(draft: sure)),
        ),
      ),
    );

    expect(find.byType(ReviewBanner), findsNothing);
  });

  testWidgets('confirming a draft records exactly one transaction',
      (tester) async {
    await pumpRoute(tester, AiCaptureScreen.route);

    await tester.enterText(
      find.byType(TextField).first,
      'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
    );
    await tester.tap(find.text(tr(AppLocale.en, 'ai.capture.action')));
    await tester.pumpAndSettle();

    expect(find.text(tr(AppLocale.en, 'ai.draft.title')), findsOneWidget);
    expect(ledger.recorded, isEmpty, reason: 'a draft must not auto-save');

    final confirm = find.text(tr(AppLocale.en, 'ai.draft.confirm'));
    await scrollTo(tester, confirm);
    await tester.tap(confirm);
    await tester.pumpAndSettle();

    expect(ledger.recorded, hasLength(1));
    expect(ledger.recorded.single.amount, const Money(35000, Currency.try_));
    final yesterday = DateTime.now().subtract(const Duration(days: 1));
    expect(
      ledger.recorded.single.occurredAt,
      DateTime(yesterday.year, yesterday.month, yesterday.day),
    );
  });

  testWidgets('dismissing a draft records nothing at all', (tester) async {
    await pumpRoute(tester, AiCaptureScreen.route);

    await tester.enterText(
      find.byType(TextField).first,
      'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
    );
    await tester.tap(find.text(tr(AppLocale.en, 'ai.capture.action')));
    await tester.pumpAndSettle();

    final dismiss = find.text(tr(AppLocale.en, 'ai.draft.dismiss'));
    await scrollTo(tester, dismiss);
    await tester.tap(dismiss);
    await tester.pumpAndSettle();

    expect(ledger.recorded, isEmpty);
    expect(find.text(tr(AppLocale.en, 'ai.draft.dismissed')), findsOneWidget);
    expect(find.text(tr(AppLocale.en, 'ai.draft.title')), findsNothing);
  });

  testWidgets('the voice flow reaches a draft through a correctable transcript',
      (tester) async {
    await pumpRoute(tester, VoiceCaptureScreen.route);

    await tester.tap(find.byIcon(Icons.mic_rounded));
    await tester.pumpAndSettle();
    expect(find.text(tr(AppLocale.en, 'ai.voice.recording')), findsOneWidget);

    await tester.tap(find.byIcon(Icons.stop_rounded));
    await tester.pumpAndSettle();
    expect(find.text(tr(AppLocale.en, 'ai.voice.transcript')), findsOneWidget);

    await tester.tap(find.text(tr(AppLocale.en, 'ai.voice.useTranscript')));
    await tester.pumpAndSettle();

    expect(find.text(tr(AppLocale.en, 'ai.draft.title')), findsOneWidget);
    expect(ledger.recorded, isEmpty);
  });

  testWidgets('the receipt screen says so when the arithmetic does not work',
      (tester) async {
    await pumpRoute(
      tester,
      () => ReceiptReviewScreen.route(reference: 'sample-mismatch'),
    );

    expect(find.byType(ReviewBanner), findsOneWidget);
    expect(
      find.textContaining('do not match the total'),
      findsWidgets,
      reason: 'the mismatch is stated in words, not hidden',
    );
  });

  testWidgets('a receipt that adds up is not flagged', (tester) async {
    await pumpRoute(
      tester,
      () => ReceiptReviewScreen.route(reference: 'sample'),
    );

    expect(find.byType(ReviewBanner), findsNothing);
    expect(find.text(tr(AppLocale.en, 'ai.receipt.balanced')), findsOneWidget);
  });

  testWidgets('chat renders the exchange and cites its source', (tester) async {
    await pumpRoute(tester, AiChatScreen.route);

    final question = tr(AppLocale.en, 'ai.chat.ask1');
    await tester.tap(find.text(question));
    await tester.pumpAndSettle();

    expect(find.text(question), findsOneWidget);
    expect(find.textContaining('this month'), findsWidgets);
    expect(find.textContaining('Based on'), findsOneWidget);

    // The source is a link to the rows behind the answer, not decoration.
    await tester.tap(find.textContaining('Based on'));
    await tester.pumpAndSettle();
    expect(find.text(tr(AppLocale.en, 'ai.chat.viewSource')), findsOneWidget);
  });

  testWidgets('the quick-add sheet reaches the AI hub', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          localeProvider.overrideWith((ref) => AppLocale.en),
          ledgerRepositoryProvider.overrideWith((ref) => ledger),
        ],
        child: const FinoraApp(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byIcon(Icons.add_rounded));
    await tester.pumpAndSettle();

    await tester.tap(find.text(tr(AppLocale.en, 'ai.entry')));
    await tester.pumpAndSettle();

    expect(find.text(tr(AppLocale.en, 'ai.hub.text')), findsOneWidget);
    expect(find.text(tr(AppLocale.en, 'ai.hub.chat')), findsOneWidget);
  });

  testWidgets('insights are dismissible and stay dismissed', (tester) async {
    await pumpRoute(tester, AiInsightsScreen.route);

    final cards = find.byType(Dismissible);
    expect(cards, findsWidgets);
    final first = tester.widget<Dismissible>(cards.first).key!;

    await tester.drag(cards.first, const Offset(-500, 0));
    await tester.pumpAndSettle();

    expect(find.byKey(first), findsNothing);
    expect(find.text(tr(AppLocale.en, 'ai.insights.dismissed')), findsOneWidget);
  });
}
