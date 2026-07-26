import '../core/money/currency.dart';
import '../core/money/money.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

/// The three things `SearchEngine::TYPES` indexes.
///
/// An unknown type from a newer server is dropped rather than guessed at: a
/// record we cannot name is a record we cannot render, and inventing a group
/// header for it would be worse than leaving it out.
enum SearchEntityType {
  transactions,
  documents,
  categories;

  static SearchEntityType? parse(String? raw) {
    for (final type in SearchEntityType.values) {
      if (type.name == raw) return type;
    }
    return null;
  }

  /// The order the groups are shown in — transactions first, because that is
  /// what almost every search is looking for.
  static const ordered = [transactions, documents, categories];

  String get labelKey => 'search.type.$name';
}

/// One row the index matched, already resolved to the record it points at.
sealed class SearchRecord {
  const SearchRecord({required this.id});

  final String id;

  SearchEntityType get entityType;

  static SearchRecord parse(SearchEntityType type, Map<String, Object?> json) =>
      switch (type) {
        SearchEntityType.transactions => TransactionHit.fromJson(json),
        SearchEntityType.documents => DocumentHit.fromJson(json),
        SearchEntityType.categories => CategoryHit.fromJson(json),
      };
}

final class TransactionHit extends SearchRecord {
  const TransactionHit({
    required super.id,
    required this.kind,
    required this.amount,
    this.description,
    this.payee,
    this.notes,
    this.occurredAt,
    this.categoryName,
    this.categoryPath,
  });

  /// `expense`, `income` or `transfer`. Kept as the server's string because the
  /// ledger owns that vocabulary; search only echoes it.
  final String kind;
  final Money amount;
  final String? description;
  final String? payee;
  final String? notes;
  final DateTime? occurredAt;
  final String? categoryName;
  final String? categoryPath;

  @override
  SearchEntityType get entityType => SearchEntityType.transactions;

  /// What to put on the first line. A transaction with neither description nor
  /// payee is legal, so the caller still has to handle an empty title.
  String get title => switch ((description, payee)) {
        (final String d, _) when d.trim().isNotEmpty => d,
        (_, final String p) when p.trim().isNotEmpty => p,
        _ => '',
      };

  static TransactionHit fromJson(Map<String, Object?> json) {
    final category = (json['category'] as Map?)?.cast<String, Object?>();

    return TransactionHit(
      id: json['id'] as String? ?? '',
      kind: json['type'] as String? ?? '',
      amount: MoneyCodec.tryDecode(json['amount']) ?? _zero,
      description: json['description'] as String?,
      payee: json['payee'] as String?,
      notes: json['notes'] as String?,
      occurredAt: _dateOf(json['occurred_at']),
      categoryName: category?['name'] as String?,
      categoryPath: category?['path'] as String?,
    );
  }
}

final class DocumentHit extends SearchRecord {
  const DocumentHit({
    required super.id,
    required this.originalName,
    this.kind,
    this.mime,
    this.size,
    this.ocrStatus,
  });

  final String originalName;
  final String? kind;
  final String? mime;
  final int? size;

  /// `pending`, `done`, `failed` … shown because a receipt whose OCR failed is
  /// a receipt whose text was never indexed, which explains a thin result set.
  final String? ocrStatus;

  @override
  SearchEntityType get entityType => SearchEntityType.documents;

  static DocumentHit fromJson(Map<String, Object?> json) => DocumentHit(
        id: json['id'] as String? ?? '',
        originalName: json['original_name'] as String? ?? '',
        kind: json['kind'] as String?,
        mime: json['mime'] as String?,
        size: json['size'] as int?,
        ocrStatus: json['ocr_status'] as String?,
      );
}

final class CategoryHit extends SearchRecord {
  const CategoryHit({
    required super.id,
    required this.name,
    required this.path,
    this.kind,
    this.depth,
  });

  final String name;
  final String path;
  final String? kind;
  final int? depth;

  @override
  SearchEntityType get entityType => SearchEntityType.categories;

  static CategoryHit fromJson(Map<String, Object?> json) => CategoryHit(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? '',
        path: json['path'] as String? ?? '',
        kind: json['type'] as String?,
        depth: json['depth'] as int?,
      );
}

/// `GET /search` — one group per type, each its own answer.
final class KeywordSearchResults {
  const KeywordSearchResults({
    required this.query,
    required this.normalizedQuery,
    required this.groups,
  });

  final String query;

  /// What the backend's `TextNormalizer` made of the query. Worth showing when
  /// it differs: it is the difference between «خريد» and «خرید», and seeing it
  /// is how someone understands why an Arabic keyboard still found their text.
  final String normalizedQuery;

  final Map<SearchEntityType, List<SearchRecord>> groups;

  int get total =>
      groups.values.fold(0, (sum, records) => sum + records.length);

  bool get isEmpty => total == 0;

  /// The groups that actually matched, in display order. A type that was asked
  /// for and returned nothing is not a heading with a void under it.
  List<MapEntry<SearchEntityType, List<SearchRecord>>> get populated => [
        for (final type in SearchEntityType.ordered)
          if ((groups[type] ?? const []).isNotEmpty)
            MapEntry(type, groups[type]!),
      ];
}

/// What `ExtractSearchFilter` read out of the sentence.
///
/// Returned so a wrong reading is visible rather than mysterious — if the model
/// decided "last year" meant 2024, the user should be able to see that.
final class SemanticFilter {
  const SemanticFilter({this.kind, this.from, this.to});

  final String? kind;
  final DateTime? from;
  final DateTime? to;

  bool get isEmpty => kind == null && from == null && to == null;

  static SemanticFilter fromJson(Map<String, Object?> json) => SemanticFilter(
        kind: (json['type'] as String?)?.trim().isEmpty ?? true
            ? null
            : json['type'] as String?,
        from: _dateOf(json['from']),
        to: _dateOf(json['to']),
      );
}

/// The «خلاصهٔ عددی» that comes back beside the rows.
final class SemanticSummary {
  const SemanticSummary({required this.transactionCount, required this.total});

  final int transactionCount;

  /// Expenses only, in the workspace's base currency — the server sums nothing
  /// else, so neither does this.
  final Money total;

  static SemanticSummary fromJson(Map<String, Object?> json) =>
      SemanticSummary(
        transactionCount: json['transaction_count'] as int? ?? 0,
        total: MoneyCodec.tryDecode({
              'value': json['total'],
              'currency': json['currency'],
            }) ??
            _zero,
      );
}

final class SemanticHit {
  const SemanticHit({
    required this.record,
    required this.score,
    required this.semanticScore,
    required this.lexicalScore,
  });

  final SearchRecord record;

  /// The blended rank the list is ordered by, in 0…1.
  final double score;

  /// The two halves the blend was made of. Kept because dropping them would
  /// make the class lossy, not because every screen should print them.
  final double semanticScore;
  final double lexicalScore;

  static SemanticHit? fromJson(Map<String, Object?> json) {
    final type = SearchEntityType.parse(json['type'] as String?);
    final raw = (json['record'] as Map?)?.cast<String, Object?>();
    if (type == null || raw == null) return null;

    return SemanticHit(
      record: SearchRecord.parse(type, raw),
      score: _doubleOf(json['score']),
      semanticScore: _doubleOf(json['semantic_score']),
      lexicalScore: _doubleOf(json['lexical_score']),
    );
  }
}

/// `GET /search/semantic` — a single ranking, not groups.
final class SemanticSearchResults {
  const SemanticSearchResults({
    required this.query,
    required this.normalizedQuery,
    required this.hits,
    required this.filter,
    required this.summary,
    required this.embeddingModel,
    required this.scanned,
  });

  final String query;
  final String normalizedQuery;
  final List<SemanticHit> hits;
  final SemanticFilter filter;
  final SemanticSummary summary;

  /// Which embedding model produced this ranking. Two runs against different
  /// models are not comparable, so the answer says which one it was.
  final String embeddingModel;

  /// How many indexed entries were considered before ranking.
  final int scanned;

  bool get isEmpty => hits.isEmpty;
}

/// Every refusal the search endpoints can return, reduced to a code.
final class SearchFailure extends CodedFailure {
  const SearchFailure(super.code, {super.statusCode});

  /// No server behind this build: there is no index to ask.
  static const noBackend = 'search_unavailable';

  /// The only code search owns outright; everything else is a transport or
  /// validation code that already has wording in the shared `error.` namespace.
  @override
  String get translationKey =>
      code == noBackend ? 'search.error.unavailable' : 'error.$code';
}

/// The two search endpoints, which answer two different questions.
///
/// `search` is a keyword match over a normalised index: it finds the word you
/// typed, wherever it was typed from. `semanticSearch` runs the pipeline in
/// docs/08-ai-layer.md §4 and finds records that are *about* what you asked,
/// even when none of your words appear in them. Neither substitutes for the
/// other, so this class never silently swaps one in for the other.
final class SearchRepository {
  const SearchRepository({required this.client});

  final ApiClient client;

  /// Per-type limit, matching `SearchEngine::DEFAULT_LIMIT`.
  static const defaultLimit = 20;

  Future<KeywordSearchResults> search(
    String query, {
    Set<SearchEntityType> types = const {},
    int limit = defaultLimit,
  }) async {
    final response = await _guard(
      () => client.get('/search', query: _query(query, types, limit)),
    );

    final data = (response['data'] as Map?)?.cast<String, Object?>() ?? const {};
    final meta = (response['meta'] as Map?)?.cast<String, Object?>() ?? const {};

    final groups = <SearchEntityType, List<SearchRecord>>{};

    data.forEach((key, value) {
      final type = SearchEntityType.parse(key);
      if (type == null || value is! List) return;

      groups[type] = [
        for (final item in value)
          if (item is Map) SearchRecord.parse(type, item.cast<String, Object?>()),
      ];
    });

    return KeywordSearchResults(
      query: meta['query'] as String? ?? query,
      normalizedQuery: meta['normalized_query'] as String? ?? '',
      groups: groups,
    );
  }

  Future<SemanticSearchResults> semanticSearch(
    String query, {
    Set<SearchEntityType> types = const {},
    int limit = defaultLimit,
  }) async {
    final response = await _guard(
      () => client.get('/search/semantic', query: _query(query, types, limit)),
    );

    final meta = (response['meta'] as Map?)?.cast<String, Object?>() ?? const {};

    return SemanticSearchResults(
      query: meta['query'] as String? ?? query,
      normalizedQuery: meta['normalized_query'] as String? ?? '',
      hits: _hits(response['data']),
      filter: SemanticFilter.fromJson(
        (meta['filter'] as Map?)?.cast<String, Object?>() ?? const {},
      ),
      summary: SemanticSummary.fromJson(
        (meta['summary'] as Map?)?.cast<String, Object?>() ?? const {},
      ),
      embeddingModel: meta['embedding_model'] as String? ?? '',
      scanned: meta['scanned'] as int? ?? 0,
    );
  }

  /// A hit whose type this build does not know is skipped rather than shown as
  /// a blank row — see [SearchEntityType.parse].
  static List<SemanticHit> _hits(Object? raw) {
    final hits = <SemanticHit>[];

    for (final item in raw as List? ?? const []) {
      if (item is! Map) continue;
      final hit = SemanticHit.fromJson(item.cast<String, Object?>());
      if (hit != null) hits.add(hit);
    }

    return hits;
  }

  static Map<String, Object?> _query(
    String query,
    Set<SearchEntityType> types,
    int limit,
  ) =>
      {
        'q': query,
        if (types.isNotEmpty)
          'types': [
            for (final type in SearchEntityType.ordered)
              if (types.contains(type)) type.name,
          ].join(','),
        'limit': limit,
      };

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw SearchFailure(error.code, statusCode: error.statusCode);
    }
  }
}

/// Amounts always arrive with their own currency code; this only exists so a
/// malformed payload degrades to zero instead of throwing on a search result.
const _zero = Money(0, Currency.irr);

DateTime? _dateOf(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;

double _doubleOf(Object? raw) => switch (raw) {
      final double value => value,
      final int value => value.toDouble(),
      final String value => double.tryParse(value) ?? 0,
      _ => 0,
    };
