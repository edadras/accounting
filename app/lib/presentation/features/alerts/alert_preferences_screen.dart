import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/alerts_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_widgets.dart';
import '../members/access_notice.dart';
import '../more/module_scaffold.dart';
import 'alert_presentation.dart';
import 'alerts_providers.dart';

/// The zones this picker offers, paired with the label key that names the city.
///
/// A free-text field would be worse than a short list: the server validates
/// against the full tz database and refuses anything else, so a typo becomes a
/// round trip and a red box. The identifier is data and travels as-is; only the
/// city name is translated.
const _timezones = <(String, String)>[
  ('Asia/Tehran', 'alerts.tzTehran'),
  ('Europe/Istanbul', 'alerts.tzIstanbul'),
  ('Asia/Dubai', 'alerts.tzDubai'),
  ('Europe/London', 'alerts.tzLondon'),
  ('Europe/Berlin', 'alerts.tzBerlin'),
  ('UTC', 'alerts.tzUtc'),
];

/// How and when this workspace is allowed to reach you.
///
/// A settings screen, so nothing here glows: the rows are choices, not
/// information. The one thing it must get right is that switching a channel off
/// is honest — the database channel cannot be, and says so rather than
/// offering a switch that silently does nothing.
class AlertPreferencesScreen extends ConsumerWidget {
  const AlertPreferencesScreen({super.key});

  static Route<void> route() => MaterialPageRoute<void>(
        builder: (_) => const AlertPreferencesScreen(),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final preferences = ref.watch(alertPreferencesProvider);

    return ModulePage(
      title: t('alerts.prefsTitle'),
      subtitle: t('alerts.prefsSubtitle'),
      child: preferences.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => noticeForFailure(
          error,
          t: t,
          permissionTitle: t('alerts.noAccessTitle'),
          permissionBody: t('alerts.noAccessBody'),
        ),
        data: (loaded) => _PreferencesForm(initial: loaded),
      ),
    );
  }
}

class _PreferencesForm extends ConsumerStatefulWidget {
  const _PreferencesForm({required this.initial});

  final AlertPreferences initial;

  @override
  ConsumerState<_PreferencesForm> createState() => _PreferencesFormState();
}

class _PreferencesFormState extends ConsumerState<_PreferencesForm> {
  static const _defaultStart = '22:00';
  static const _defaultEnd = '07:00';

  late AlertPreferences _draft;

  bool _busy = false;
  String? _errorKey;
  bool _saved = false;

  @override
  void initState() {
    super.initState();
    _draft = widget.initial;
  }

  void _edit(AlertPreferences next) {
    setState(() {
      _draft = next;
      _saved = false;
      _errorKey = null;
    });
  }

  /// Both ends move together. A window with equal ends is zero minutes long,
  /// which the server reads as no quiet hours at all — so turning the feature on
  /// seeds a real window rather than an empty one.
  void _toggleQuietHours({required bool enabled}) {
    _edit(
      enabled
          ? _draft.withQuietHours(
              _draft.quietHoursStart ?? _defaultStart,
              _draft.quietHoursEnd ?? _defaultEnd,
            )
          : _draft.withQuietHours(null, null),
    );
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _errorKey = null;
      _saved = false;
    });

    try {
      final saved =
          await ref.read(alertsRepositoryProvider).savePreferences(_draft);

      if (!mounted) return;
      setState(() {
        _draft = saved;
        _busy = false;
        _saved = true;
      });
    } on AlertsException catch (error) {
      if (!mounted) return;
      setState(() {
        _errorKey = error.translationKey;
        _busy = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final accent = alertAccentFor(NeonPalette.cyan, isDark: isDark);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        SectionHeader(title: t('alerts.channelsTitle')),
        _Note(text: t('alerts.channelsNote')),
        const SizedBox(height: 8),
        for (final channel in AlertChannel.values)
          _ChannelRow(
            rowKey: 'pref-channel-${channel.wire}',
            label: t(alertChannelKey(channel)),
            locked: channel.isMandatory,
            lockedNote: t('alerts.channelLocked'),
            enabled: _draft.enables(channel),
            onChanged: channel.isMandatory
                ? null
                : (value) =>
                    _edit(_draft.withChannel(channel, enabled: value)),
          ),
        const SizedBox(height: 20),
        SectionHeader(title: t('alerts.quietHours')),
        _Note(text: t('alerts.quietHoursNote')),
        const SizedBox(height: 8),
        _ChannelRow(
          rowKey: 'pref-quiet-enabled',
          label: t('alerts.quietHoursEnabled'),
          enabled: _draft.hasQuietHours,
          onChanged: (value) => _toggleQuietHours(enabled: value),
        ),
        if (_draft.hasQuietHours) ...[
          const SizedBox(height: 12),
          _TimeField(
            fieldKey: 'pref-quiet-start',
            label: t('alerts.quietFrom'),
            value: _draft.quietHoursStart ?? _defaultStart,
            localeCode: locale.code,
            accent: accent,
            onChanged: (value) =>
                _edit(_draft.withQuietHours(value, _draft.quietHoursEnd)),
          ),
          const SizedBox(height: 10),
          _TimeField(
            fieldKey: 'pref-quiet-end',
            label: t('alerts.quietUntil'),
            value: _draft.quietHoursEnd ?? _defaultEnd,
            localeCode: locale.code,
            accent: accent,
            onChanged: (value) =>
                _edit(_draft.withQuietHours(_draft.quietHoursStart, value)),
          ),
        ],
        const SizedBox(height: 20),
        SectionHeader(title: t('alerts.timezone')),
        _Note(text: t('alerts.timezoneNote')),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            NeonChip(
              key: const ValueKey('pref-tz-default'),
              label: t('alerts.timezoneDefault'),
              accent: accent,
              selected: _draft.timezone == null,
              onTap: () => _edit(_draft.withTimezone(null)),
            ),
            for (final (zone, labelKey) in _timezones)
              NeonChip(
                key: ValueKey('pref-tz-$zone'),
                label: t(labelKey),
                accent: accent,
                selected: _draft.timezone == zone,
                onTap: () => _edit(_draft.withTimezone(zone)),
              ),
          ],
        ),
        const SizedBox(height: 26),
        NeonButton(
          key: const ValueKey('pref-save'),
          label: t('alerts.savePrefs'),
          icon: Icons.save_rounded,
          expand: true,
          busy: _busy,
          onPressed: _busy ? null : _save,
        ),
        if (_saved)
          Padding(
            padding: const EdgeInsetsDirectional.only(top: 12),
            child: Text(
              t('alerts.prefsSaved'),
              key: const ValueKey('pref-saved'),
              style: TextStyle(
                fontSize: 12.5,
                color: alertAccentFor(NeonPalette.lime, isDark: isDark),
              ),
            ),
          ),
        if (_errorKey != null)
          Padding(
            padding: const EdgeInsetsDirectional.only(top: 12),
            child: Text(
              t(_errorKey!),
              key: const ValueKey('pref-error'),
              style: TextStyle(
                fontSize: 12.5,
                height: 1.5,
                color: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
              ),
            ),
          ),
      ],
    );
  }
}

class _ChannelRow extends StatelessWidget {
  const _ChannelRow({
    required this.rowKey,
    required this.label,
    required this.enabled,
    required this.onChanged,
    this.locked = false,
    this.lockedNote,
  });

  final String rowKey;
  final String label;
  final bool enabled;
  final ValueChanged<bool>? onChanged;
  final bool locked;
  final String? lockedNote;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Padding(
      padding: const EdgeInsetsDirectional.symmetric(vertical: 2),
      child: Row(
        children: [
          if (locked) ...[
            Icon(
              Icons.lock_rounded,
              size: 14,
              color: isDark
                  ? NeonPalette.textMuted
                  : NeonPalette.lightTextSecondary,
            ),
            const SizedBox(width: 8),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w600,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                if (locked && lockedNote != null)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(top: 2),
                    child: Text(
                      lockedNote!,
                      style: TextStyle(
                        fontSize: 11,
                        height: 1.5,
                        color: isDark
                            ? NeonPalette.textMuted
                            : NeonPalette.lightTextSecondary,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Switch(
            key: ValueKey(rowKey),
            value: enabled,
            onChanged: onChanged,
          ),
        ],
      ),
    );
  }
}

/// An `HH:MM` wall-clock time, nudged in whole hours and quarter hours.
///
/// Not `showTimePicker`: the value has to leave here as the exact `H:i` string
/// the server validates, and a picker that formats through the device locale
/// would hand Persian digits to a regular expression expecting ASCII.
class _TimeField extends StatelessWidget {
  const _TimeField({
    required this.fieldKey,
    required this.label,
    required this.value,
    required this.localeCode,
    required this.accent,
    required this.onChanged,
  });

  final String fieldKey;
  final String label;
  final String value;
  final String localeCode;
  final Color accent;
  final ValueChanged<String> onChanged;

  static const _step = 15;

  (int, int) get _parts {
    final pieces = value.split(':');
    return (
      int.tryParse(pieces.first) ?? 0,
      pieces.length > 1 ? int.tryParse(pieces[1]) ?? 0 : 0,
    );
  }

  String _shift(int minutes) {
    final (hour, minute) = _parts;
    final total = (hour * 60 + minute + minutes) % (24 * 60);
    final wrapped = total < 0 ? total + 24 * 60 : total;

    return '${(wrapped ~/ 60).toString().padLeft(2, '0')}:'
        '${(wrapped % 60).toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final (hour, minute) = _parts;

    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: TextStyle(
              fontSize: 13,
              color: isDark
                  ? NeonPalette.textSecondary
                  : NeonPalette.lightTextSecondary,
            ),
          ),
        ),
        _TimeButton(
          buttonKey: '$fieldKey-minus',
          icon: Icons.remove_rounded,
          accent: accent,
          onPressed: () => onChanged(_shift(-_step)),
        ),
        Container(
          key: ValueKey('$fieldKey-value'),
          width: 78,
          alignment: Alignment.center,
          // A clock reads left to right in every locale this app ships, and
          // forcing the direction here beats sprinkling bidi control
          // characters through a string the server also has to parse.
          child: Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              _clock(hour, minute, localeCode),
              style: TextStyle(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: isDark
                    ? NeonPalette.textPrimary
                    : NeonPalette.lightTextPrimary,
              ),
            ),
          ),
        ),
        _TimeButton(
          buttonKey: '$fieldKey-plus',
          icon: Icons.add_rounded,
          accent: accent,
          onPressed: () => onChanged(_shift(_step)),
        ),
      ],
    );
  }

  /// `22:00` in the reader's digits, zero padding intact.
  ///
  /// Localised one digit at a time through [DateFormatter] so this file does
  /// not keep a second copy of the Persian and Arabic numeral tables; the
  /// isolate characters it wraps each number in are stripped, because the whole
  /// field is already forced left-to-right.
  static String _clock(int hour, int minute, String localeCode) {
    final ascii = '${hour.toString().padLeft(2, '0')}:'
        '${minute.toString().padLeft(2, '0')}';
    final buffer = StringBuffer();

    for (final character in ascii.split('')) {
      final digit = int.tryParse(character);
      buffer.write(
        digit == null
            ? character
            : DateFormatter.number(digit, localeCode)
                .replaceAll(RegExp('[\u2068\u2069]'), ''),
      );
    }

    return buffer.toString();
  }
}

class _TimeButton extends StatelessWidget {
  const _TimeButton({
    required this.buttonKey,
    required this.icon,
    required this.accent,
    required this.onPressed,
  });

  final String buttonKey;
  final IconData icon;
  final Color accent;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      child: GestureDetector(
        key: ValueKey(buttonKey),
        onTap: onPressed,
        child: Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: accent.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(NeonEffects.radiusSm),
            border: NeonEffects.border(accent, alpha: 0.30),
          ),
          child: Icon(icon, size: 17, color: accent),
        ),
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 11.5,
        height: 1.6,
        color: isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary,
      ),
    );
  }
}
