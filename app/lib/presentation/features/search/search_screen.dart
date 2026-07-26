import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/search_repository.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'search_providers.dart';
import 'search_result_row.dart';

/// Search over the whole workspace, in either of the two ways the server can
/// answer.
///
/// The mode is a choice the user makes and can see, never a silent substitution
/// — keyword search returning nothing means the word is not there, and quietly
/// answering with semantic matches instead would hide exactly that fact.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key});

  static Route<void> route() =>
      MaterialPageRoute<void>(builder: (_) => const SearchScreen());

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _controller = TextEditingController();
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  /// Typing does not fetch; stopping typing does.
  ///
  /// Clearing the field is applied at once — there is no request to save, and
  /// leaving the old results under an empty box for a third of a second reads
  /// as a bug.
  void _onChanged(String value) {
    setState(() {});
    _debounce?.cancel();

    if (value.trim().isEmpty) {
      ref.read(searchQueryProvider.notifier).state = '';
      return;
    }

    _debounce = Timer(searchDebounce, () {
      if (!mounted) return;
      ref.read(searchQueryProvider.notifier).state = value;
    });
  }

  void _submit(String value) {
    _debounce?.cancel();
    ref.read(searchQueryProvider.notifier).state = value;
  }

  void _clear() {
    _debounce?.cancel();
    _controller.clear();
    setState(() {});
    ref.read(searchQueryProvider.notifier).state = '';
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final mode = ref.watch(searchModeProvider);

    return ModulePage(
      title: t('search.title'),
      subtitle: t('search.subtitle'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _QueryField(
                  controller: _controller,
                  hint: t('search.hint'),
                  onChanged: _onChanged,
                  onSubmitted: _submit,
                  onClear: _controller.text.isEmpty ? null : _clear,
                ),
                const SizedBox(height: 14),
                const _ModePicker(),
                const SizedBox(height: 10),
                _ModeHint(text: t(mode.hintKey)),
                const SizedBox(height: 12),
                const _TypePicker(),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: switch (mode) {
              SearchMode.keyword => const _KeywordResults(),
              SearchMode.semantic => const _SemanticResults(),
            },
          ),
        ],
      ),
    );
  }
}

class _QueryField extends StatelessWidget {
  const _QueryField({
    required this.controller,
    required this.hint,
    required this.onChanged,
    required this.onSubmitted,
    required this.onClear,
  });

  final TextEditingController controller;
  final String hint;
  final ValueChanged<String> onChanged;
  final ValueChanged<String> onSubmitted;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return TextField(
      key: const ValueKey('search-field'),
      controller: controller,
      autocorrect: false,
      textInputAction: TextInputAction.search,
      // One field, both scripts: the text decides which way it is laid out, so
      // «قهوه» and `Netflix` are each typed the right way round without the
      // user switching anything.
      textDirection: directionOf(controller.text, Directionality.of(context)),
      onChanged: onChanged,
      onSubmitted: onSubmitted,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: const Icon(Icons.search_rounded, size: 18),
        suffixIcon: onClear == null
            ? null
            : IconButton(
                key: const ValueKey('search-clear'),
                icon: const Icon(Icons.close_rounded, size: 18),
                onPressed: onClear,
              ),
      ),
    );
  }
}

class _ModePicker extends ConsumerWidget {
  const _ModePicker();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final mode = ref.watch(searchModeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final option in SearchMode.values)
          NeonChip(
            key: ValueKey('search-mode-${option.name}'),
            label: t(option.labelKey),
            icon: option == SearchMode.semantic
                ? Icons.auto_awesome_rounded
                : Icons.abc_rounded,
            accent: option == SearchMode.semantic
                ? (isDark ? NeonPalette.violet : NeonPalette.lightViolet)
                : (isDark ? NeonPalette.cyan : NeonPalette.lightCyan),
            selected: option == mode,
            onTap: () => ref.read(searchModeProvider.notifier).state = option,
          ),
      ],
    );
  }
}

class _ModeHint extends StatelessWidget {
  const _ModeHint({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 11.5,
        height: 1.5,
        color: isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
      ),
    );
  }
}

class _TypePicker extends ConsumerWidget {
  const _TypePicker();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final selected = ref.watch(searchTypesProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    void toggle(SearchEntityType type) {
      final next = {...selected};
      if (!next.remove(type)) next.add(type);
      ref.read(searchTypesProvider.notifier).state = next;
    }

    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        NeonChip(
          key: const ValueKey('search-type-all'),
          label: t('search.type.all'),
          selected: selected.isEmpty,
          accent: isDark ? NeonPalette.textSecondary : NeonPalette.lightCyan,
          onTap: () =>
              ref.read(searchTypesProvider.notifier).state = const {},
        ),
        for (final type in SearchEntityType.ordered)
          NeonChip(
            key: ValueKey('search-type-${type.name}'),
            label: t(type.labelKey),
            icon: iconFor(type),
            accent: accentFor(type, isDark: isDark),
            selected: selected.contains(type),
            onTap: () => toggle(type),
          ),
      ],
    );
  }
}

class _KeywordResults extends ConsumerWidget {
  const _KeywordResults();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final results = ref.watch(keywordSearchProvider);

    return results.when(
      loading: () => const ModuleLoading(),
      error: (error, _) => _failure(error, t),
      data: (data) {
        if (data == null) return _Idle(t: t);
        if (data.isEmpty) {
          return _NoResults(t: t, query: data.query, mode: SearchMode.keyword);
        }

        return ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 40),
          children: [
            _NormalizedNote(results: data, t: t),
            for (final group in data.populated) ...[
              SectionHeader(
                title: '${t(group.key.labelKey)} · '
                    '${DateFormatter.number(group.value.length, locale.code)}',
                accent: accentFor(
                  group.key,
                  isDark: Theme.of(context).brightness == Brightness.dark,
                ),
              ),
              for (final record in group.value) ...[
                SearchResultRow(record: record, locale: locale, t: t),
                const SizedBox(height: 10),
              ],
              const SizedBox(height: 8),
            ],
          ],
        );
      },
    );
  }
}

class _SemanticResults extends ConsumerWidget {
  const _SemanticResults();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final results = ref.watch(semanticSearchProvider);

    return results.when(
      loading: () => const ModuleLoading(),
      error: (error, _) => _failure(error, t),
      data: (data) {
        if (data == null) return _Idle(t: t);
        if (data.isEmpty) {
          return _NoResults(t: t, query: data.query, mode: SearchMode.semantic);
        }

        return ListView(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 40),
          children: [
            _Interpretation(results: data, locale: locale, t: t),
            const SizedBox(height: 14),
            SectionHeader(
              title: t('search.ranked'),
              accent: NeonPalette.violet,
            ),
            for (var i = 0; i < data.hits.length; i++) ...[
              SearchResultRow(
                record: data.hits[i].record,
                locale: locale,
                t: t,
                trailing: RelevanceBadge(
                  rank: i + 1,
                  score: data.hits[i].score,
                  locale: locale,
                ),
              ),
              const SizedBox(height: 10),
            ],
          ],
        );
      },
    );
  }
}

/// What the model made of the sentence, and what the matched rows add up to.
///
/// Shown above the list rather than buried under it: a filter that read "last
/// year" as the wrong twelve months explains the whole answer, and the user can
/// only notice that if it is on screen.
class _Interpretation extends StatelessWidget {
  const _Interpretation({
    required this.results,
    required this.locale,
    required this.t,
  });

  final SemanticSearchResults results;
  final AppLocale locale;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final filter = results.filter;

    return NeonCard(
      key: const ValueKey('search-interpretation'),
      accent: NeonPalette.violet,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            t('search.interpretationTitle'),
            style: TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w700,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
          const SizedBox(height: 10),
          if (filter.isEmpty)
            _Line(text: t('search.filterNone'))
          else ...[
            if (filter.kind != null)
              _Line(
                text: t(
                  'search.filterType',
                  args: {'type': t('search.transactionKind.${filter.kind}')},
                ),
              ),
            if (filter.from != null || filter.to != null)
              _Line(
                text: t(
                  'search.filterPeriod',
                  args: {
                    'from': filter.from == null
                        ? t('search.openEnded')
                        : DateFormatter.short(filter.from!, locale),
                    'to': filter.to == null
                        ? t('search.openEnded')
                        : DateFormatter.short(filter.to!, locale),
                  },
                ),
              ),
          ],
          const SizedBox(height: 10),
          _Line(
            text: t(
              'search.summaryLine',
              args: {
                'count': DateFormatter.number(
                  results.summary.transactionCount,
                  locale.code,
                ),
                'total': MoneyFormatter.format(
                  results.summary.total,
                  locale: locale.code,
                ),
              },
            ),
            strong: true,
          ),
          const SizedBox(height: 8),
          _Line(
            text: t(
              'search.semanticFootnote',
              args: {
                'scanned': DateFormatter.number(results.scanned, locale.code),
                'model': results.embeddingModel,
              },
            ),
            muted: true,
          ),
        ],
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.text, this.strong = false, this.muted = false});

  final String text;
  final bool strong;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final color = muted
        ? (isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary)
        : strong
            ? (isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary)
            : (isDark
                ? NeonPalette.textSecondary
                : NeonPalette.lightTextSecondary);

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 4),
      child: Text(
        text,
        style: TextStyle(
          fontSize: muted ? 10.5 : 12,
          height: 1.5,
          fontWeight: strong ? FontWeight.w700 : FontWeight.w500,
          color: color,
        ),
      ),
    );
  }
}

/// The backend folds Arabic letters, harakat and digits before it matches. When
/// that changed the query, saying so is the difference between "search is
/// broken" and "your keyboard writes ي where the ledger has ی".
class _NormalizedNote extends StatelessWidget {
  const _NormalizedNote({required this.results, required this.t});

  final KeywordSearchResults results;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final normalized = results.normalizedQuery;
    if (normalized.isEmpty || normalized == results.query.trim()) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: const EdgeInsetsDirectional.only(bottom: 10),
      child: _Line(
        text: t('search.normalizedAs', args: {'query': isolated(normalized)}),
        muted: true,
      ),
    );
  }
}

class _Idle extends StatelessWidget {
  const _Idle({required this.t});

  final Translator t;

  @override
  Widget build(BuildContext context) => NoticePanel(
        key: const ValueKey('search-idle'),
        icon: Icons.search_rounded,
        accent: NeonPalette.cyan,
        title: t('search.idleTitle'),
        body: t('search.idleBody'),
      );
}

/// Nothing matched — which is an answer, not a failure, and says which of the
/// two questions was asked so the other one can be tried.
class _NoResults extends StatelessWidget {
  const _NoResults({required this.t, required this.query, required this.mode});

  final Translator t;
  final String query;
  final SearchMode mode;

  @override
  Widget build(BuildContext context) => NoticePanel(
        key: const ValueKey('search-empty'),
        icon: Icons.search_off_rounded,
        accent: NeonPalette.amber,
        title: t('search.emptyTitle', args: {'query': isolated(query)}),
        body: mode == SearchMode.keyword
            ? t('search.emptyKeywordBody')
            : t('search.emptySemanticBody'),
      );
}

Widget _failure(Object error, Translator t) => noticeForFailure(
      error,
      t: t,
      permissionTitle: t('search.noAccessTitle'),
      permissionBody: t('search.noAccessBody'),
    );
