import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/presentation/features/search/search_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/membership_harness.dart' show loadTestFonts;
import 'support/search_billing_harness.dart';

/// The search screen, driven against a mocked socket.
///
/// The backend's own search tests need Meilisearch and are skipped in this
/// environment, so nothing here pretends to test the engine: what is asserted
/// is the contract between the documented response shape and the screen.
///
/// No `pumpAndSettle` anywhere. The field owns a debounce timer, and a settle
/// against a pending timer waits out its whole budget instead of failing.
void main() {
  setUpAll(loadTestFonts);

  testWidgets('typing does not fetch until the pause is over', (tester) async {
    final server = FakeSearchServer();

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'قه');
    await tester.pump(const Duration(milliseconds: 100));
    await tester.enterText(find.byKey(const ValueKey('search-field')), 'قهوه');
    await tester.pump(const Duration(milliseconds: 100));

    // Two keystrokes, still inside the window: the server has not been asked.
    expect(server.keywordCalls, 0);

    await pumpPastDebounce(tester);

    // And when it is asked, it is asked once, for the whole word — not once per
    // letter.
    expect(server.keywordCalls, 1);
    expect(server.lastQuery, 'قهوه');
  });

  testWidgets('clearing the field takes effect without waiting',
      (tester) async {
    final server = FakeSearchServer()
      ..groups = {
        'transactions': [transactionJson(id: 't1')],
        'documents': const [],
        'categories': const [],
      };

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'گوشت');
    await pumpPastDebounce(tester);
    expect(find.byKey(const ValueKey('search-hit-t1')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('search-clear')));
    await pumpFrames(tester);

    // No second request, and no stale list sitting under an empty box.
    expect(server.keywordCalls, 1);
    expect(find.byKey(const ValueKey('search-idle')), findsOneWidget);
  });

  testWidgets('an empty result set is an answer, and names the query',
      (tester) async {
    final server = FakeSearchServer();

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'زعفران');
    await pumpPastDebounce(tester);

    expect(find.byKey(const ValueKey('search-empty')), findsOneWidget);

    // The query is quoted back, isolated so a Persian word cannot drag the
    // English sentence around it out of order.
    final title = tester.widget<Text>(find.textContaining('Nothing found for'));
    expect(title.data, contains('زعفران'));

    // It says the word is not in the index, and points at the other mode —
    // rather than quietly answering a different question.
    expect(
      find.textContaining('That word is not in the index'),
      findsOneWidget,
    );
    expect(server.semanticCalls, 0);
  });

  testWidgets('keyword results are grouped by type', (tester) async {
    final server = FakeSearchServer()
      ..groups = {
        'transactions': [
          transactionJson(id: 't1', description: 'خرید گوشت'),
          transactionJson(id: 't2', description: 'گوشت چرخ کرده'),
        ],
        'documents': [documentJson(id: 'd1', originalName: 'butcher.pdf')],
        'categories': [categoryJson(id: 'c1', name: 'خوراک')],
      };

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'گوشت');
    await pumpPastDebounce(tester);

    expect(find.textContaining('Transactions'), findsWidgets);
    expect(find.textContaining('Documents'), findsWidgets);
    expect(find.textContaining('Categories'), findsWidgets);

    for (final id in ['t1', 't2', 'd1', 'c1']) {
      expect(find.byKey(ValueKey('search-hit-$id')), findsOneWidget);
    }

    // A document is named by its file, and its kind is translated rather than
    // echoed back in the server's English.
    expect(find.text('butcher.pdf'), findsOneWidget);
    expect(find.textContaining('Receipt'), findsOneWidget);
  });

  testWidgets('the same field takes a Persian query and an LTR one',
      (tester) async {
    final server = FakeSearchServer();

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      // Persian: the page is RTL, and the LTR query must still be laid out the
      // other way round inside it.
      locale: AppLocale.fa,
      adapter: MockAdapter(server.handle),
    );

    TextDirection? fieldDirection() => tester
        .widget<TextField>(find.byKey(const ValueKey('search-field')))
        .textDirection;

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'قهوه');
    await pumpPastDebounce(tester);

    expect(fieldDirection(), TextDirection.rtl);
    expect(server.lastQuery, 'قهوه');

    await tester.enterText(
      find.byKey(const ValueKey('search-field')),
      'Netflix',
    );
    await pumpPastDebounce(tester);

    expect(fieldDirection(), TextDirection.ltr);
    expect(server.lastQuery, 'Netflix');
    expect(server.keywordCalls, 2);
  });

  testWidgets('semantic search is offered as its own mode, not a swap',
      (tester) async {
    final server = FakeSearchServer()
      ..semanticHits = [
        semanticHitJson(
          type: 'transactions',
          record: transactionJson(id: 't9', description: 'بنزین'),
          score: 0.82,
        ),
        semanticHitJson(
          type: 'documents',
          record: documentJson(id: 'd9', originalName: 'garage.pdf'),
          score: 0.41,
        ),
      ]
      ..filter = {
        'type': 'expense',
        'from': '2026-01-01',
        'to': '2026-06-30',
        'provider': 'deterministic',
      }
      ..summary = {
        'transaction_count': 2,
        'total': 100000,
        'currency': 'TRY',
      }
      ..scanned = 137;

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(
      find.byKey(const ValueKey('search-field')),
      'car costs',
    );
    await pumpPastDebounce(tester);

    // Keyword mode asked the keyword endpoint and nothing else.
    expect(server.keywordCalls, 1);
    expect(server.semanticCalls, 0);

    await tester.tap(find.byKey(const ValueKey('search-mode-semantic')));
    await pumpFrames(tester);

    expect(server.semanticCalls, 1);

    // The ranked answer explains itself: what the model read out of the
    // sentence, what the matches add up to, and how many rows it looked at.
    expect(find.byKey(const ValueKey('search-interpretation')), findsOneWidget);
    expect(find.textContaining('Limited to expenses'), findsOneWidget);
    expect(find.textContaining('2026/01/01'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('search-interpretation')),
        matching: find.textContaining('spent'),
      ),
      findsOneWidget,
    );
    expect(find.textContaining('1,000.00'), findsOneWidget);
    expect(find.textContaining('137'), findsOneWidget);
    expect(find.textContaining('local-hashed-v1'), findsOneWidget);

    // One list, in rank order — not three groups.
    expect(find.byKey(const ValueKey('search-hit-t9')), findsOneWidget);
    expect(find.byKey(const ValueKey('search-hit-d9')), findsOneWidget);
    expect(find.textContaining('Ranked by closeness'), findsOneWidget);
  });

  testWidgets('an empty semantic answer points back at the keyword mode',
      (tester) async {
    final server = FakeSearchServer();

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.tap(find.byKey(const ValueKey('search-mode-semantic')));
    await pumpFrames(tester);

    await tester.enterText(
      find.byKey(const ValueKey('search-field')),
      'anything',
    );
    await pumpPastDebounce(tester);

    expect(find.byKey(const ValueKey('search-empty')), findsOneWidget);
    expect(
      find.textContaining('No record came close enough'),
      findsOneWidget,
    );
  });

  testWidgets('the normalised query is shown when it differs', (tester) async {
    final server = FakeSearchServer()
      ..normalized = 'خرید'
      ..groups = {
        'transactions': [transactionJson(id: 't1', description: 'خريد هفتگي')],
        'documents': const [],
        'categories': const [],
      };

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'خريد');
    await pumpPastDebounce(tester);

    expect(find.textContaining('Searched as'), findsOneWidget);
  });

  testWidgets('a type filter is sent to the server', (tester) async {
    final server = FakeSearchServer();

    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(server.handle),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'گوشت');
    await pumpPastDebounce(tester);

    expect(server.requests.last.queryParameters.containsKey('types'), isFalse);

    await tester.tap(find.byKey(const ValueKey('search-type-documents')));
    await pumpFrames(tester);

    expect(server.requests.last.queryParameters['types'], 'documents');
  });

  testWidgets('with no backend the screen explains instead of crashing',
      (tester) async {
    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
    );

    expect(
      find.text('Search needs a connection to the server.'),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('a refusal is translated, never echoed from the server',
      (tester) async {
    await pumpSearchBillingApp(
      tester,
      const SearchScreen(),
      locale: AppLocale.en,
      adapter: MockAdapter(
        (options) => MockAdapter.error(
          'workspace_forbidden',
          status: 403,
          // Deliberately English: the app must never put this on screen.
          message: 'You do not have access to this workspace.',
        ),
      ),
    );

    await tester.enterText(find.byKey(const ValueKey('search-field')), 'گوشت');
    await pumpPastDebounce(tester);

    expect(find.text('You cannot search this workspace'), findsOneWidget);
    expect(
      find.text('You do not have access to this workspace.'),
      findsNothing,
    );
  });
}
