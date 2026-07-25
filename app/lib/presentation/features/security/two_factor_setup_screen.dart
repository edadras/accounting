import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/security_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'security_providers.dart';
import 'security_widgets.dart';

enum _Stage { loading, enrolling, recoveryCodes, done }

/// Enrolment: key → code → recovery codes.
///
/// Pops `true` once the second factor is live, so the screen that pushed it can
/// refresh without asking the server twice.
class TwoFactorSetupScreen extends ConsumerStatefulWidget {
  const TwoFactorSetupScreen({super.key});

  static Route<bool> route() => MaterialPageRoute<bool>(
        builder: (_) => const TwoFactorSetupScreen(),
      );

  @override
  ConsumerState<TwoFactorSetupScreen> createState() =>
      _TwoFactorSetupScreenState();
}

class _TwoFactorSetupScreenState extends ConsumerState<TwoFactorSetupScreen> {
  final _codeController = TextEditingController();

  _Stage _stage = _Stage.loading;
  TwoFactorEnrollment? _enrollment;
  List<String> _recoveryCodes = const [];
  Object? _error;
  String? _localErrorKey;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    unawaited(_enable());
  }

  @override
  void dispose() {
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _enable() async {
    setState(() {
      _stage = _Stage.loading;
      _error = null;
    });

    try {
      final enrollment =
          await ref.read(securityRepositoryProvider).enableTwoFactor();
      if (!mounted) return;
      setState(() {
        _enrollment = enrollment;
        _stage = _Stage.enrolling;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _stage = _Stage.enrolling;
      });
    }
  }

  Future<void> _confirm() async {
    final code = _codeController.text.trim();
    if (code.length < 6) {
      setState(() => _localErrorKey = 'security.codeRequired');
      return;
    }

    setState(() {
      _busy = true;
      _error = null;
      _localErrorKey = null;
    });

    try {
      final confirmation =
          await ref.read(securityRepositoryProvider).confirmTwoFactor(code);
      if (!mounted) return;
      setState(() {
        _recoveryCodes = confirmation.recoveryCodes;
        _stage = _Stage.recoveryCodes;
        _busy = false;
      });
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error;
        _busy = false;
      });
    }
  }

  /// The acknowledgement is what drops the codes. Nothing keeps a copy — the
  /// server cannot show them again, so neither can this screen.
  void _acknowledge() {
    setState(() {
      _recoveryCodes = const [];
      _stage = _Stage.done;
    });
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);

    return SecurityScaffold(
      title: t('security.setupTitle'),
      canPop: _stage != _Stage.recoveryCodes,
      children: switch (_stage) {
        _Stage.loading => const [
            Padding(
              padding: EdgeInsetsDirectional.only(top: 40),
              child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
            ),
          ],
        _Stage.enrolling => _enrollmentBody(t),
        _Stage.recoveryCodes => _recoveryBody(t),
        _Stage.done => _doneBody(t),
      },
    );
  }

  List<Widget> _enrollmentBody(Translator t) {
    final enrollment = _enrollment;

    if (enrollment == null) {
      return [
        SecurityNotice.failure(message: securityErrorText(t, _error)),
        const SizedBox(height: 16),
        NeonButton(
          label: t('common.retry'),
          icon: Icons.refresh_rounded,
          expand: true,
          onPressed: _enable,
        ),
      ];
    }

    return [
      SectionHeader(title: t('security.stepApp')),
      NeonCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CopyableValue(
              label: t('security.secretLabel'),
              value: enrollment.groupedSecret,
              hint: t('security.secretHint'),
              fontSize: 18,
            ),
            const SizedBox(height: 20),
            CopyableValue(
              label: t('security.uriLabel'),
              value: enrollment.otpauthUri,
              hint: t('security.uriHint'),
              accent: NeonPalette.violet,
              fontSize: 12,
            ),
          ],
        ),
      ),
      const SizedBox(height: 22),
      SectionHeader(title: t('security.stepCode')),
      NeonCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CodeField(
              controller: _codeController,
              label: t('security.codeLabel'),
              onSubmitted: _confirm,
            ),
            if (_localErrorKey != null || _error != null) ...[
              const SizedBox(height: 14),
              SecurityNotice.failure(
                message: _localErrorKey != null
                    ? t(_localErrorKey!)
                    : securityErrorText(t, _error),
              ),
            ],
            const SizedBox(height: 16),
            NeonButton(
              label: t('security.confirm'),
              icon: Icons.shield_outlined,
              expand: true,
              busy: _busy,
              onPressed: _confirm,
            ),
          ],
        ),
      ),
    ];
  }

  List<Widget> _recoveryBody(Translator t) {
    return [
      SectionHeader(
        title: t('security.recoveryTitle'),
        accent: NeonPalette.amber,
      ),
      // Lit amber: this is the one moment the codes exist outside the server,
      // and the warning is the information on the screen.
      SecurityNotice(
        message: t('security.recoveryOnce'),
        detail: t('security.recoveryEachOnce'),
        accent: NeonPalette.amber,
        icon: Icons.warning_amber_rounded,
        glow: true,
      ),
      const SizedBox(height: 16),
      NeonCard(
        accent: NeonPalette.amber,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Directionality(
              textDirection: TextDirection.ltr,
              child: Wrap(
                spacing: 18,
                runSpacing: 12,
                children: [
                  for (final code in _recoveryCodes)
                    SelectableText(
                      code,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 1.4,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            _CopyAllButton(codes: _recoveryCodes),
          ],
        ),
      ),
      const SizedBox(height: 20),
      NeonButton(
        label: t('security.recoverySaved'),
        icon: Icons.check_rounded,
        accent: NeonPalette.lime,
        expand: true,
        onPressed: _acknowledge,
      ),
    ];
  }

  List<Widget> _doneBody(Translator t) {
    return [
      SecurityNotice.success(message: t('security.twoFactorEnabled')),
      const SizedBox(height: 20),
      NeonButton(
        label: t('security.done'),
        icon: Icons.arrow_back_rounded,
        expand: true,
        onPressed: () => Navigator.of(context).maybePop(true),
      ),
    ];
  }
}

class _CopyAllButton extends ConsumerStatefulWidget {
  const _CopyAllButton({required this.codes});

  final List<String> codes;

  @override
  ConsumerState<_CopyAllButton> createState() => _CopyAllButtonState();
}

class _CopyAllButtonState extends ConsumerState<_CopyAllButton> {
  bool _copied = false;

  Future<void> _copy() async {
    await Clipboard.setData(ClipboardData(text: widget.codes.join('\n')));
    if (mounted) setState(() => _copied = true);
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);

    return NeonButton(
      label: _copied ? t('security.copied') : t('security.copy'),
      icon: _copied ? Icons.check_rounded : Icons.copy_rounded,
      accent: NeonPalette.amber,
      variant: NeonButtonVariant.outline,
      expand: true,
      onPressed: _copy,
    );
  }
}
