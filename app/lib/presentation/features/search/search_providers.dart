import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../data/finora_backend.dart';
import '../../../data/search_repository.dart';

/// The two questions the backend answers, kept apart on purpose.
///
/// `GET /search` finds the word that was typed; `GET /search/semantic` finds
/// records that are *about* what was asked, which is a different answer to a
/// different question. Quietly serving one when the user asked for the other
/// would make an empty keyword result look like an empty ledger — so the mode
/// is a visible choice and the results are shaped differently.
enum SearchMode {
  keyword,
  semantic;

  String get labelKey => 'search.mode.$name';

  String get hintKey => 'search.modeHint.$name';
}

/// How long the field waits before asking the server.
///
/// Long enough that typing a Persian word does not spend six requests on it,
/// short enough that the answer still feels attached to the keystroke.
const searchDebounce = Duration(milliseconds: 350);

final searchRepositoryProvider = Provider<SearchRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const SearchFailure(SearchFailure.noBackend);
  }
  return SearchRepository(client: backend.client);
});

/// The debounced query — what the screen has decided is worth a round trip,
/// not what is currently in the field.
final searchQueryProvider = StateProvider<String>((ref) => '');

final searchModeProvider = StateProvider<SearchMode>((ref) => SearchMode.keyword);

/// Empty means every type, matching the server's own reading of `types=`.
final searchTypesProvider =
    StateProvider<Set<SearchEntityType>>((ref) => const {});

/// Null before anything has been asked. An empty result set is a
/// [KeywordSearchResults] with no groups, which is a different thing and reads
/// differently on screen.
final keywordSearchProvider =
    FutureProvider.autoDispose<KeywordSearchResults?>((ref) async {
  // Read before the empty-query shortcut, so a build with no server behind it
  // says so on arrival rather than after someone has typed a whole word.
  final repository = ref.watch(searchRepositoryProvider);
  final query = ref.watch(searchQueryProvider).trim();
  if (query.isEmpty) return null;

  return repository.search(query, types: ref.watch(searchTypesProvider));
});

final semanticSearchProvider =
    FutureProvider.autoDispose<SemanticSearchResults?>((ref) async {
  final repository = ref.watch(searchRepositoryProvider);
  final query = ref.watch(searchQueryProvider).trim();
  if (query.isEmpty) return null;

  return repository.semanticSearch(
    query,
    types: ref.watch(searchTypesProvider),
  );
});
