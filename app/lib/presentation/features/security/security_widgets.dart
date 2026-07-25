import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../widgets/neon_widgets.dart';

/// The frame every security screen sits in.
class SecurityScaffold extends StatelessWidget {
  const SecurityScaffold({
    super.key,
    required this.title,
    required this.children,
    this.canPop = true,
  });

  final String title;
  final List<Widget> children;

  /// False while the screen is holding something the user must acknowledge
  /// before it disappears — the recovery codes, in practice.
  final bool canPop;

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: canPop,
      child: NeonBackdrop(
        child: Scaffold(
          backgroundColor: Colors.transparent,
          appBar: AppBar(
            title: Text(title),
            automaticallyImplyLeading: canPop,
          ),
          body: SafeArea(
            top: false,
            child: ListView(
              padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 48),
              children: children,
            ),
          ),
        ),
      ),
    );
  }
}

/// A one-line statement that carries meaning: an error, a warning, a result.
///
/// [glow] is the whole reason this is a widget rather than a Text — a glowing
/// panel means "this matters", so success notices stay flat and only failures
/// and the unprotected state light up.
class SecurityNotice extends StatelessWidget {
  const SecurityNotice({
    super.key,
    required this.message,
    required this.accent,
    this.icon,
    this.glow = false,
    this.detail,
  });

  const SecurityNotice.failure({
    super.key,
    required this.message,
    this.detail,
  })  : accent = NeonPalette.magenta,
        icon = Icons.error_outline_rounded,
        glow = true;

  const SecurityNotice.success({
    super.key,
    required this.message,
    this.detail,
  })  : accent = NeonPalette.lime,
        icon = Icons.check_circle_outline_rounded,
        glow = false;

  final String message;
  final String? detail;
  final Color accent;
  final IconData? icon;
  final bool glow;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCardShell(
      accent: accent,
      glow: glow,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 18, color: accent),
            const SizedBox(width: 10),
          ],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  message,
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    height: 1.5,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
                if (detail != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    detail!,
                    style: const TextStyle(
                      fontSize: 12,
                      height: 1.6,
                      color: NeonPalette.textSecondary,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A value the user has to get out of the app and into something else — the
/// setup key, the provisioning URI, a recovery code.
///
/// Always laid out left-to-right: these are ASCII secrets, and mirroring them
/// on a Persian screen would make them unreadable and untypeable.
class CopyableValue extends ConsumerStatefulWidget {
  const CopyableValue({
    super.key,
    required this.label,
    required this.value,
    this.hint,
    this.accent = NeonPalette.cyan,
    this.fontSize = 15,
  });

  final String label;
  final String value;
  final String? hint;
  final Color accent;
  final double fontSize;

  @override
  ConsumerState<CopyableValue> createState() => _CopyableValueState();
}

class _CopyableValueState extends ConsumerState<CopyableValue> {
  bool _copied = false;

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: widget.value));
    if (mounted) setState(() => _copied = true);
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          widget.label,
          style: const TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: NeonPalette.textSecondary,
          ),
        ),
        const SizedBox(height: 8),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Directionality(
                textDirection: TextDirection.ltr,
                child: SelectableText(
                  widget.value,
                  style: TextStyle(
                    fontSize: widget.fontSize,
                    fontWeight: FontWeight.w700,
                    height: 1.5,
                    letterSpacing: 1.2,
                    color: widget.accent,
                  ),
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton(
              onPressed: _copy,
              tooltip: t('security.copy'),
              icon: Icon(
                _copied ? Icons.check_rounded : Icons.copy_rounded,
                size: 18,
                color: widget.accent,
              ),
            ),
          ],
        ),
        if (_copied)
          Text(
            t('security.copied'),
            style: const TextStyle(fontSize: 11, color: NeonPalette.lime),
          ),
        if (widget.hint != null) ...[
          const SizedBox(height: 6),
          Text(
            widget.hint!,
            style: const TextStyle(
              fontSize: 12,
              height: 1.6,
              color: NeonPalette.textMuted,
            ),
          ),
        ],
      ],
    );
  }
}

/// The field a one-time code is typed into.
///
/// Digits stay left-to-right and centred in every language: a code is a
/// sequence, not a sentence, and reversing it is the fastest way to make a
/// correct code look wrong.
class CodeField extends StatelessWidget {
  const CodeField({
    super.key,
    required this.controller,
    required this.label,
    this.digitsOnly = true,
    this.maxLength = 6,
    this.autofocus = false,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final String label;
  final bool digitsOnly;
  final int maxLength;
  final bool autofocus;
  final VoidCallback? onSubmitted;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      autofocus: autofocus,
      keyboardType:
          digitsOnly ? TextInputType.number : TextInputType.visiblePassword,
      textDirection: TextDirection.ltr,
      textAlign: TextAlign.center,
      maxLength: maxLength,
      inputFormatters: [
        FilteringTextInputFormatter.allow(
          digitsOnly ? RegExp(r'[0-9]') : RegExp(r'[A-Za-z0-9\-]'),
        ),
        if (!digitsOnly) UpperCaseFormatter(),
      ],
      style: const TextStyle(
        fontSize: 22,
        fontWeight: FontWeight.w800,
        letterSpacing: 6,
        color: NeonPalette.textPrimary,
      ),
      decoration: InputDecoration(labelText: label, counterText: ''),
      onSubmitted: onSubmitted == null ? null : (_) => onSubmitted!(),
    );
  }
}

/// Recovery codes are issued upper-case; accepting them in any case and fixing
/// it here is kinder than rejecting a code the user typed correctly.
final class UpperCaseFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) =>
      newValue.copyWith(text: newValue.text.toUpperCase());
}

/// A labelled plain text field (email, password, reset code).
class SecurityField extends StatelessWidget {
  const SecurityField({
    super.key,
    required this.controller,
    required this.label,
    this.obscure = false,
    this.email = false,
    this.ltr = false,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final String label;
  final bool obscure;
  final bool email;
  final bool ltr;
  final VoidCallback? onSubmitted;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      obscureText: obscure,
      keyboardType:
          email ? TextInputType.emailAddress : TextInputType.visiblePassword,
      autocorrect: false,
      enableSuggestions: false,
      textDirection: ltr || email ? TextDirection.ltr : null,
      style: const TextStyle(fontSize: 15, color: NeonPalette.textPrimary),
      decoration: InputDecoration(labelText: label),
      onSubmitted: onSubmitted == null ? null : (_) => onSubmitted!(),
    );
  }
}
