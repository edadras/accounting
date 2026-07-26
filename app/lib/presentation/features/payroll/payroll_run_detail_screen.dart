import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_effects.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/payroll_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'payroll_fields.dart';
import 'payroll_format.dart';
import 'payroll_providers.dart';
import 'payslip_screen.dart';

/// One payroll run: what it costs, where it is in its life, and what may still
/// be done to it.
///
/// The actions are derived from the state rather than attempted against it. A
/// paid run therefore carries no button at all — a button that exists only to
/// be refused is a lie about what the screen can do.
class PayrollRunDetailScreen extends ConsumerWidget {
  const PayrollRunDetailScreen({super.key, required this.runId});

  final String runId;

  static Route<void> route(String runId) => MaterialPageRoute<void>(
        builder: (_) => PayrollRunDetailScreen(runId: runId),
      );

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final run = ref.watch(payrollRunProvider(runId));

    return ModulePage(
      title: run.valueOrNull?.reference ?? t('payroll.run'),
      subtitle: t('payroll.runSubtitle'),
      child: run.when(
        loading: () => const ModuleLoading(),
        error: (error, _) => payrollFailurePanel(error, t),
        data: (data) => _RunBody(run: data),
      ),
    );
  }
}

class _RunBody extends ConsumerStatefulWidget {
  const _RunBody({required this.run});

  final PayrollRun run;

  @override
  ConsumerState<_RunBody> createState() => _RunBodyState();
}

class _RunBodyState extends ConsumerState<_RunBody> {
  bool _busy = false;
  String? _failureText;

  PayrollRun get _run => widget.run;

  Future<void> _act(Future<void> Function(PayrollRepository repo) action) async {
    setState(() {
      _busy = true;
      _failureText = null;
    });

    try {
      await action(ref.read(payrollRepositoryProvider));
      ref.invalidate(payrollRunsProvider);
      ref.invalidate(payrollRunProvider(_run.id));
      if (!mounted) return;
      setState(() => _busy = false);
    } on Object catch (error) {
      if (!mounted) return;
      setState(() {
        _failureText = payrollFailureText(ref.read(translatorProvider), error);
        _busy = false;
      });
    }
  }

  Future<void> _approve() async {
    final t = ref.read(translatorProvider);
    final confirmed = await _confirm(
      title: t('payroll.approveTitle'),
      body: t('payroll.approveBody'),
      confirmLabel: t('payroll.approve'),
    );
    if (!confirmed) return;

    await _act((repo) => repo.approveRun(_run.id));
  }

  Future<void> _pay() async {
    final t = ref.read(translatorProvider);
    final confirmed = await _confirm(
      title: t('payroll.payTitle'),
      body: t('payroll.payBody'),
      confirmLabel: t('payroll.markPaid'),
    );
    if (!confirmed) return;

    await _act((repo) => repo.payRun(_run.id));
  }

  Future<void> _delete() async {
    final t = ref.read(translatorProvider);
    final confirmed = await _confirm(
      title: t('payroll.discardTitle'),
      body: t('payroll.discardBody'),
      confirmLabel: t('payroll.discard'),
    );
    if (!confirmed) return;

    await _act((repo) => repo.deleteRun(_run.id));

    if (!mounted || _failureText != null) return;
    Navigator.of(context).pop();
  }

  Future<bool> _confirm({
    required String title,
    required String body,
    required String confirmLabel,
  }) async {
    final t = ref.read(translatorProvider);

    final answer = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            key: const ValueKey('run-confirm-cancel'),
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(t('payroll.cancel')),
          ),
          TextButton(
            key: const ValueKey('run-confirm-ok'),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(confirmLabel),
          ),
        ],
      ),
    );

    return answer ?? false;
  }

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final run = _run;
    final accent = PayrollFormat.runAccent(run.status, isDark: isDark);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 8, 16, 40),
      children: [
        if (_failureText != null) PayrollNotice(message: _failureText!),
        RunStateMachine(status: run.status),
        const SizedBox(height: 18),
        NeonCard(
          accent: accent,
          // The unapproved cost is the number on this screen that has not
          // happened yet; once approved or paid the glow would only be noise.
          glow: PayrollFormat.shouldGlow(run),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                t('payroll.periodBetween', args: {
                  'from': PayrollFormat.date(run.periodStart, locale),
                  'to': PayrollFormat.date(run.periodEnd, locale),
                },),
                style: TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w600,
                  color: isDark
                      ? NeonPalette.textSecondary
                      : NeonPalette.lightTextSecondary,
                ),
              ),
              const SizedBox(height: 14),
              DetailRow(
                key: const ValueKey('run-gross'),
                label: t('payroll.gross'),
                value: PayrollFormat.money(run.gross, locale),
              ),
              DetailRow(
                key: const ValueKey('run-deductions'),
                label: t('payroll.deductions'),
                value: PayrollFormat.money(run.deductions, locale),
                accent: isDark ? NeonPalette.magenta : NeonPalette.lightMagenta,
              ),
              const Divider(height: 22),
              DetailRow(
                key: const ValueKey('run-net'),
                label: t('payroll.net'),
                value: PayrollFormat.money(run.net, locale),
                strong: true,
              ),
              Text(
                t('payroll.netFormula'),
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.5,
                  color: isDark
                      ? NeonPalette.textMuted
                      : NeonPalette.lightTextSecondary,
                ),
              ),
              const Divider(height: 22),
              DetailRow(
                key: const ValueKey('run-contributions'),
                label: t('payroll.employerContributions'),
                value: PayrollFormat.money(run.contributions, locale),
                accent: isDark ? NeonPalette.violet : NeonPalette.lightViolet,
              ),
              Text(
                t('payroll.contributionsNotInNet'),
                key: const ValueKey('run-contributions-note'),
                style: TextStyle(
                  fontSize: 11.5,
                  height: 1.5,
                  color: isDark
                      ? NeonPalette.textMuted
                      : NeonPalette.lightTextSecondary,
                ),
              ),
              const SizedBox(height: 10),
              DetailRow(
                key: const ValueKey('run-employer-cost'),
                label: t('payroll.employerCost'),
                value: PayrollFormat.money(run.employerCost, locale),
                accent: accent,
                strong: true,
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
        _RunFacts(run: run, locale: locale),
        const SizedBox(height: 18),
        if (run.isImmutable)
          PayrollNotice(
            key: const ValueKey('run-immutable-note'),
            message: t('payroll.paidIsImmutable'),
            accent: isDark ? NeonPalette.lime : NeonPalette.lightLime,
            icon: Icons.lock_rounded,
          )
        else ...[
          if (run.canApprove)
            NeonButton(
              key: const ValueKey('run-approve'),
              label: t('payroll.approve'),
              icon: Icons.verified_rounded,
              expand: true,
              busy: _busy,
              onPressed: _busy ? null : _approve,
            ),
          if (run.canPay)
            NeonButton(
              key: const ValueKey('run-pay'),
              label: t('payroll.markPaid'),
              icon: Icons.payments_rounded,
              accent: NeonPalette.amber,
              expand: true,
              busy: _busy,
              onPressed: _busy ? null : _pay,
            ),
          if (run.canDelete) ...[
            const SizedBox(height: 10),
            NeonButton(
              key: const ValueKey('run-delete'),
              label: t('payroll.discard'),
              icon: Icons.delete_outline_rounded,
              accent: NeonPalette.magenta,
              variant: NeonButtonVariant.outline,
              expand: true,
              onPressed: _busy ? null : _delete,
            ),
          ],
          const SizedBox(height: 10),
          Text(
            run.canApprove
                ? t('payroll.draftPostsNothing')
                : t('payroll.approvedIsPosted'),
            key: const ValueKey('run-state-note'),
            style: TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: isDark
                  ? NeonPalette.textMuted
                  : NeonPalette.lightTextSecondary,
            ),
          ),
        ],
        const SizedBox(height: 22),
        SectionHeader(
          title: t('payroll.payslips'),
          actionLabel: PayrollFormat.count(run.slipCount, locale),
        ),
        if (run.payslips.isEmpty)
          Text(
            t('payroll.noPayslips'),
            style: const TextStyle(fontSize: 12.5, color: NeonPalette.textMuted),
          )
        else
          for (final payslip in run.payslips) ...[
            _PayslipRow(payslip: payslip, locale: locale),
            const SizedBox(height: 10),
          ],
      ],
    );
  }
}

/// draft → approved → paid, drawn as the one-way machine it is.
///
/// The step the run is on is lit; the ones behind it are done; the one ahead is
/// dim. Each step carries what it means, because "approved" only makes sense
/// once you know that approving is what reaches the ledger.
class RunStateMachine extends ConsumerWidget {
  const RunStateMachine({super.key, required this.status});

  final PayrollRunStatus status;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final muted =
        isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            for (final step in PayrollRunStatus.values) ...[
              if (step != PayrollRunStatus.draft)
                Padding(
                  padding:
                      const EdgeInsetsDirectional.symmetric(horizontal: 6),
                  child: Icon(
                    Icons.arrow_forward_rounded,
                    size: 14,
                    color: muted,
                  ),
                ),
              Expanded(
                child: _Step(
                  key: ValueKey('run-step-${step.name}'),
                  label: t(PayrollFormat.runStatusKey(step)),
                  accent: PayrollFormat.runAccent(step, isDark: isDark),
                  reached: step.index <= status.index,
                  current: step == status,
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 10),
        Text(
          t('payroll.stateMachineNote'),
          style: TextStyle(fontSize: 11.5, height: 1.5, color: muted),
        ),
      ],
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({
    super.key,
    required this.label,
    required this.accent,
    required this.reached,
    required this.current,
  });

  final String label;
  final Color accent;
  final bool reached;
  final bool current;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final color = reached
        ? accent
        : (isDark ? NeonPalette.textMuted : NeonPalette.lightTextSecondary);

    return Container(
      padding:
          const EdgeInsetsDirectional.symmetric(horizontal: 8, vertical: 10),
      decoration: BoxDecoration(
        color: current ? accent.withValues(alpha: 0.12) : Colors.transparent,
        borderRadius: BorderRadius.circular(NeonEffects.radiusSm + 2),
        border: NeonEffects.border(color, alpha: current ? 0.6 : 0.18),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            reached ? Icons.check_circle_rounded : Icons.circle_outlined,
            size: 13,
            color: color,
          ),
          const SizedBox(width: 6),
          Flexible(
            child: Text(
              label,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 12,
                fontWeight: current ? FontWeight.w800 : FontWeight.w600,
                color: color,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _RunFacts extends ConsumerWidget {
  const _RunFacts({required this.run, required this.locale});

  final PayrollRun run;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return NeonCard(
      accent: NeonPalette.violet,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          DetailRow(
            label: t('payroll.payDate'),
            value: PayrollFormat.date(run.payDate, locale),
          ),
          DetailRow(
            label: t('payroll.payslipCount'),
            value: PayrollFormat.count(run.slipCount, locale),
          ),
          DetailRow(
            label: t('payroll.posted'),
            value: run.isPosted ? t('payroll.yes') : t('payroll.no'),
          ),
          if (run.netTransactionId != null)
            DetailRow(
              label: t('payroll.netTransaction'),
              value: run.netTransactionId!,
            ),
          if (run.liabilityTransactionId != null)
            DetailRow(
              label: t('payroll.liabilityTransaction'),
              value: run.liabilityTransactionId!,
            ),
          if (run.approvedAt != null)
            DetailRow(
              label: t('payroll.approvedAt'),
              value: PayrollFormat.date(run.approvedAt, locale),
            ),
          if (run.paidAt != null)
            DetailRow(
              label: t('payroll.paidAt'),
              value: PayrollFormat.date(run.paidAt, locale),
            ),
          if (run.notes != null) ...[
            const SizedBox(height: 8),
            Text(
              run.notes!,
              style: const TextStyle(fontSize: 12, height: 1.6),
            ),
          ],
        ],
      ),
    );
  }
}

class _PayslipRow extends ConsumerWidget {
  const _PayslipRow({required this.payslip, required this.locale});

  final Payslip payslip;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return NeonCardShell(
      key: ValueKey('payslip-${payslip.id}'),
      accent: isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
      onTap: () =>
          Navigator.of(context).push(PayslipScreen.route(payslip.id)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  payslip.employeeName,
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: isDark
                        ? NeonPalette.textPrimary
                        : NeonPalette.lightTextPrimary,
                  ),
                ),
              ),
              if (payslip.isProrated)
                NeonChip(
                  label: t('payroll.prorated'),
                  accent: NeonPalette.amber,
                  selected: true,
                ),
            ],
          ),
          const SizedBox(height: 10),
          DetailRow(
            label: t('payroll.net'),
            value: PayrollFormat.money(payslip.net, locale),
            strong: true,
          ),
        ],
      ),
    );
  }
}
