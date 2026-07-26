import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/search_repository.dart';
import '../../widgets/neon_widgets.dart';

/// The direction a piece of user text should be laid out in.
///
/// Someone searching a Persian ledger still types `Netflix` and `IBAN` into the
/// same box, and a field pinned to the page direction puts the caret and the
/// punctuation on the wrong side for one of them. The first strong character
/// decides, which is the same rule the Unicode bidi algorithm uses for a
/// paragraph — and an empty or digits-only query keeps the page's direction
/// rather than jumping about while it is being typed.
TextDirection directionOf(String text, TextDirection fallback) {
  for (final rune in text.runes) {
    // Hebrew, Arabic, Persian and the Arabic presentation forms.
    if ((rune >= 0x0590 && rune <= 0x08FF) ||
        (rune >= 0xFB1D && rune <= 0xFDFF) ||
        (rune >= 0xFE70 && rune <= 0xFEFF)) {
      return TextDirection.rtl;
    }
    if ((rune >= 0x0041 && rune <= 0x005A) ||
        (rune >= 0x0061 && rune <= 0x007A) ||
        (rune >= 0x00C0 && rune <= 0x024F)) {
      return TextDirection.ltr;
    }
  }
  return fallback;
}

/// Wraps user text in a bidi isolate so dropping it into a translated sentence
/// cannot drag the surrounding punctuation to the other end of the line.
String isolated(String text) => '\u{2068}$text\u{2069}';

/// The accent each type carries, so a mixed list is scannable by colour before
/// it is read.
Color accentFor(SearchEntityType type, {required bool isDark}) =>
    switch (type) {
      SearchEntityType.transactions =>
        isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
      SearchEntityType.documents => NeonPalette.amber,
      SearchEntityType.categories =>
        isDark ? NeonPalette.violet : NeonPalette.lightViolet,
    };

IconData iconFor(SearchEntityType type) => switch (type) {
      SearchEntityType.transactions => Icons.receipt_long_rounded,
      SearchEntityType.documents => Icons.description_rounded,
      SearchEntityType.categories => Icons.sell_rounded,
    };

/// One matched record, whichever of the three it is.
class SearchResultRow extends StatelessWidget {
  const SearchResultRow({
    super.key,
    required this.record,
    required this.locale,
    required this.t,
    this.trailing,
  });

  final SearchRecord record;
  final AppLocale locale;
  final Translator t;

  /// Used by the semantic list for its rank and score; the keyword list has no
  /// score to show and passes nothing.
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = accentFor(record.entityType, isDark: isDark);

    return NeonCardShell(
      key: ValueKey('search-hit-${record.id}'),
      accent: accent,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(iconFor(record.entityType), size: 17, color: accent),
          const SizedBox(width: 12),
          Expanded(
            child: switch (record) {
              final TransactionHit hit => _Transaction(hit: hit, locale: locale, t: t),
              final DocumentHit hit => _Document(hit: hit, t: t),
              final CategoryHit hit => _Category(hit: hit, t: t),
            },
          ),
          if (trailing != null) ...[
            const SizedBox(width: 10),
            trailing!,
          ],
        ],
      ),
    );
  }
}

class _Transaction extends StatelessWidget {
  const _Transaction({required this.hit, required this.locale, required this.t});

  final TransactionHit hit;
  final AppLocale locale;
  final Translator t;

  Color _accent({required bool isDark}) => switch (hit.kind) {
        'income' => isDark ? NeonPalette.income : NeonPalette.lightLime,
        'transfer' => isDark ? NeonPalette.transfer : NeonPalette.lightCyan,
        _ => isDark ? NeonPalette.expense : NeonPalette.lightMagenta,
      };

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final subtitle = [
      if (hit.occurredAt != null) DateFormatter.short(hit.occurredAt!, locale),
      if (hit.categoryName != null && hit.categoryName!.isNotEmpty)
        hit.categoryName!,
      if (hit.payee != null &&
          hit.payee!.isNotEmpty &&
          hit.payee != hit.title)
        hit.payee!,
    ].join(' · ');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: _Title(
                text: hit.title.isEmpty ? t('search.untitled') : hit.title,
              ),
            ),
            const SizedBox(width: 8),
            Text(
              MoneyFormatter.format(hit.amount, locale: locale.code),
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: _accent(isDark: isDark),
              ),
            ),
          ],
        ),
        if (subtitle.isNotEmpty) _Subtitle(text: subtitle),
        if (hit.notes != null && hit.notes!.trim().isNotEmpty)
          _Subtitle(text: hit.notes!),
      ],
    );
  }
}

class _Document extends StatelessWidget {
  const _Document({required this.hit, required this.t});

  final DocumentHit hit;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    final ocr = hit.ocrStatus;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        _Title(text: hit.originalName),
        _Subtitle(
          text: [
            if (hit.kind != null && hit.kind!.isNotEmpty)
              t('search.documentKind.${hit.kind}'),
            if (hit.size != null) _size(hit.size!, t),
          ].join(' · '),
        ),
        // A receipt whose OCR never finished has no indexed text, which is the
        // most useful thing this row can say about why a search missed it.
        if (ocr != null && ocr != 'done') ...[
          const SizedBox(height: 8),
          NeonChip(
            label: t('search.ocr.$ocr'),
            accent: ocr == 'failed' ? NeonPalette.magenta : NeonPalette.amber,
          ),
        ],
      ],
    );
  }

  static String _size(int bytes, Translator t) {
    if (bytes >= 1024 * 1024) {
      return t('search.sizeMb', args: {'value': '${bytes ~/ (1024 * 1024)}'});
    }
    return t('search.sizeKb', args: {'value': '${(bytes / 1024).ceil()}'});
  }
}

class _Category extends StatelessWidget {
  const _Category({required this.hit, required this.t});

  final CategoryHit hit;
  final Translator t;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        _Title(text: hit.name),
        _Subtitle(text: hit.path),
        if (hit.kind != null && hit.kind!.isNotEmpty) ...[
          const SizedBox(height: 8),
          NeonChip(
            label: t('search.categoryKind.${hit.kind}'),
            accent: NeonPalette.violet,
          ),
        ],
      ],
    );
  }
}

class _Title extends StatelessWidget {
  const _Title({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      textDirection: directionOf(text, Directionality.of(context)),
      style: TextStyle(
        fontSize: 13.5,
        fontWeight: FontWeight.w700,
        color: isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
      ),
    );
  }
}

class _Subtitle extends StatelessWidget {
  const _Subtitle({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (text.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsetsDirectional.only(top: 4),
      child: Text(
        text,
        maxLines: 2,
        overflow: TextOverflow.ellipsis,
        textDirection: directionOf(text, Directionality.of(context)),
        style: TextStyle(
          fontSize: 11.5,
          color:
              isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
        ),
      ),
    );
  }
}

/// The rank badge and relevance meter beside a semantic hit.
///
/// The bar is here because the score *is* information on this screen: a list
/// ordered by relevance is much easier to read when how far the relevance falls
/// off is visible, and a run where everything scores 0.13 should look different
/// from one where the top hit scores 0.9.
class RelevanceBadge extends StatelessWidget {
  const RelevanceBadge({
    super.key,
    required this.rank,
    required this.score,
    required this.locale,
  });

  final int rank;
  final double score;
  final AppLocale locale;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = isDark ? NeonPalette.cyan : NeonPalette.lightCyan;

    return SizedBox(
      width: 52,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            DateFormatter.number(rank, locale.code),
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w800,
              color: accent,
              shadows: isDark
                  ? NeonEffects.textGlow(accent, intensity: 0.5)
                  : null,
            ),
          ),
          const SizedBox(height: 6),
          NeonMeterBar(fraction: score.clamp(0.0, 1.0), accent: accent),
        ],
      ),
    );
  }
}

/// A thin fixed-width meter. `NeonMeter` fills its parent, which is wrong
/// inside a row that has already reserved its width.
class NeonMeterBar extends StatelessWidget {
  const NeonMeterBar({super.key, required this.fraction, required this.accent});

  final double fraction;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      width: 46,
      height: 5,
      alignment: AlignmentDirectional.centerStart,
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Container(
        width: 46 * fraction.clamp(0.02, 1.0),
        height: 5,
        decoration: BoxDecoration(
          color: accent,
          borderRadius: BorderRadius.circular(999),
          boxShadow:
              isDark ? NeonEffects.glowTight(accent, intensity: 0.5) : null,
        ),
      ),
    );
  }
}
