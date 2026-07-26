import 'package:finora/data/search_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';
import 'support/search_billing_harness.dart';

/// The mapping layer between the two search controllers and the screen.
///
/// Plain `test`, not `testWidgets`: there is no fake clock here, so awaiting
/// Dio is safe without `runAsync`.
void main() {
  SearchRepository repositoryFor(FakeSearchServer server) =>
      SearchRepository(client: fakeClient(MockAdapter(server.handle)));

  group('keyword search', () {
    test('parses each group into the record type it names', () async {
      final server = FakeSearchServer()
        ..groups = {
          'transactions': [
            transactionJson(
              id: 't1',
              description: 'خرید گوشت',
              payee: 'قصابی',
              amount: 35000,
            ),
          ],
          'documents': [documentJson(id: 'd1')],
          'categories': [categoryJson(id: 'c1')],
        };

      final results = await repositoryFor(server).search('گوشت');

      expect(results.total, 3);
      expect(results.isEmpty, isFalse);
      expect(results.populated.map((group) => group.key), [
        SearchEntityType.transactions,
        SearchEntityType.documents,
        SearchEntityType.categories,
      ]);

      final transaction =
          results.groups[SearchEntityType.transactions]!.single as TransactionHit;

      // Integer minor units, straight off the wire — nothing goes through a
      // double on the way to the screen.
      expect(transaction.amount.minorUnits, 35000);
      expect(transaction.amount.currency.code, 'TRY');
      expect(transaction.title, 'خرید گوشت');
      expect(transaction.categoryName, 'خوراک');

      final document =
          results.groups[SearchEntityType.documents]!.single as DocumentHit;
      expect(document.originalName, 'receipt.pdf');
      expect(document.ocrStatus, 'done');

      final category =
          results.groups[SearchEntityType.categories]!.single as CategoryHit;
      expect(category.path, '/food');
    });

    test('an empty answer is empty, not missing', () async {
      final results = await repositoryFor(FakeSearchServer()).search('زعفران');

      expect(results.isEmpty, isTrue);
      expect(results.total, 0);
      expect(results.populated, isEmpty);
      // The groups the server named are still there — the screen decides what
      // to do with a type that matched nothing.
      expect(results.groups.keys, hasLength(3));
    });

    test('falls back to the payee when there is no description', () async {
      final server = FakeSearchServer()
        ..groups = {
          'transactions': [
            transactionJson(id: 't1', description: null, payee: 'Netflix'),
          ],
        };

      final results = await repositoryFor(server).search('netflix');
      final hit =
          results.groups[SearchEntityType.transactions]!.single as TransactionHit;

      expect(hit.title, 'Netflix');
    });

    test('sends the chosen types as one comma-separated parameter', () async {
      final server = FakeSearchServer();

      await repositoryFor(server).search(
        'گوشت',
        types: {SearchEntityType.categories, SearchEntityType.transactions},
        limit: 5,
      );

      final query = server.requests.single.queryParameters;
      expect(query['q'], 'گوشت');
      expect(query['limit'], 5);
      // In the server's own order, so the parameter is stable whatever order
      // the chips were tapped in.
      expect(query['types'], 'transactions,categories');
    });

    test('omits types entirely when every type is wanted', () async {
      final server = FakeSearchServer();

      await repositoryFor(server).search('گوشت');

      expect(server.requests.single.queryParameters.containsKey('types'), false);
    });
  });

  group('semantic search', () {
    test('parses the ranking, the filter and the numeric summary', () async {
      final server = FakeSearchServer()
        ..semanticHits = [
          semanticHitJson(
            type: 'transactions',
            record: transactionJson(id: 't1', description: 'بنزین'),
            score: 0.82,
            semantic: 0.9,
            lexical: 0.6,
          ),
        ]
        ..filter = {
          'type': 'expense',
          'from': '2025-01-01',
          'to': '2025-12-31',
          'provider': 'deterministic',
        }
        ..summary = {
          'transaction_count': 2,
          'total': 100000,
          'currency': 'TRY',
        }
        ..scanned = 42;

      final results = await repositoryFor(server).semanticSearch('ماشین');

      expect(results.hits, hasLength(1));
      expect(results.hits.single.score, closeTo(0.82, 0.0001));
      expect(results.hits.single.semanticScore, closeTo(0.9, 0.0001));
      expect(results.hits.single.lexicalScore, closeTo(0.6, 0.0001));
      expect(results.hits.single.record, isA<TransactionHit>());

      expect(results.filter.isEmpty, isFalse);
      expect(results.filter.kind, 'expense');
      expect(results.filter.from, DateTime.parse('2025-01-01').toLocal());
      expect(results.summary.transactionCount, 2);
      expect(results.summary.total.minorUnits, 100000);
      expect(results.summary.total.currency.code, 'TRY');
      expect(results.embeddingModel, 'local-hashed-v1');
      expect(results.scanned, 42);
    });

    test('an unread filter is empty rather than a set of nulls', () async {
      final server = FakeSearchServer();

      final results = await repositoryFor(server).semanticSearch('anything');

      expect(results.filter.isEmpty, isTrue);
      expect(results.isEmpty, isTrue);
    });

    test('a hit of a type this build does not know is dropped', () async {
      final server = FakeSearchServer()
        ..semanticHits = [
          semanticHitJson(
            type: 'spaceships',
            record: {'id': 'x1', 'name': 'nope'},
          ),
          semanticHitJson(
            type: 'transactions',
            record: transactionJson(id: 't1'),
          ),
        ];

      final results = await repositoryFor(server).semanticSearch('ماشین');

      // A record we cannot name is a record we cannot render; it is left out
      // rather than shown as a blank row.
      expect(results.hits, hasLength(1));
      expect(results.hits.single.record.id, 't1');
    });
  });

  group('failures', () {
    test('a refusal keeps its code and drops the English message', () async {
      final repository = SearchRepository(
        client: fakeClient(
          MockAdapter(
            (options) => MockAdapter.error(
              'workspace_forbidden',
              status: 403,
              message: 'You do not have access to this workspace.',
            ),
          ),
        ),
      );

      await expectLater(
        repository.search('گوشت'),
        throwsA(
          isA<SearchFailure>()
              .having((e) => e.code, 'code', 'workspace_forbidden')
              .having((e) => e.statusCode, 'status', 403)
              .having((e) => e.isPermissionDenied, 'permission denied', isTrue)
              .having((e) => e.translationKey, 'key', 'error.workspace_forbidden'),
        ),
      );
    });

    test('the no-backend code has search wording of its own', () {
      const failure = SearchFailure(SearchFailure.noBackend);

      expect(failure.translationKey, 'search.error.unavailable');
    });
  });
}
