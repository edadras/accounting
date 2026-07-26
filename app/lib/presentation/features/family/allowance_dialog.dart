import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/family_repository.dart';
import '../../widgets/neon_button.dart';
import '../../widgets/neon_card.dart';
import '../../widgets/neon_widgets.dart';
import 'family_labels.dart';

/// Who is paying, and for which month.
final class AllowanceRequest {
  const AllowanceRequest({required this.payerId, required this.period});

  final String payerId;
  final String period;
}

/// Asks for the two facts the server needs that the app cannot infer.
///
/// The amount is deliberately not asked for: `PayAllowance` (PHP) pays the
/// member's configured allowance when none is sent, and offering a free-text
/// amount here would invite a figure that disagrees with the one on the
/// member's own record.
Future<AllowanceRequest?> pickAllowancePayment(
  BuildContext context, {
  required HouseholdMember member,
  required List<HouseholdMember> payers,
  required String period,
}) =>
    showDialog<AllowanceRequest>(
      context: context,
      builder: (context) => _AllowanceDialog(
        member: member,
        payers: payers,
        period: period,
      ),
    );

class _AllowanceDialog extends ConsumerStatefulWidget {
  const _AllowanceDialog({
    required this.member,
    required this.payers,
    required this.period,
  });

  final HouseholdMember member;
  final List<HouseholdMember> payers;
  final String period;

  @override
  ConsumerState<_AllowanceDialog> createState() => _AllowanceDialogState();
}

class _AllowanceDialogState extends ConsumerState<_AllowanceDialog> {
  late String _period = widget.period;
  late String? _payerId =
      widget.payers.length == 1 ? widget.payers.first.id : null;

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final allowance = widget.member.monthlyAllowance;

    return Dialog(
      backgroundColor: Colors.transparent,
      elevation: 0,
      insetPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 40),
      child: SingleChildScrollView(
        child: NeonCard(
          accent: NeonPalette.lime,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                t('family.payTitle'),
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: isDark
                      ? NeonPalette.textPrimary
                      : NeonPalette.lightTextPrimary,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                allowance == null
                    ? t('family.payNoAllowance')
                    : t('family.payBody', args: {
                        'name': widget.member.displayName,
                        'amount': MoneyFormatter.format(
                          allowance,
                          locale: locale.code,
                        ),
                      },),
                style: TextStyle(
                  fontSize: 13,
                  height: 1.6,
                  color: isDark
                      ? NeonPalette.textSecondary
                      : NeonPalette.lightTextSecondary,
                ),
              ),
              const SizedBox(height: 18),
              _Label(text: t('family.payPeriod')),
              const SizedBox(height: 8),
              _MonthStepper(
                period: _period,
                onChanged: (value) => setState(() => _period = value),
              ),
              const SizedBox(height: 18),
              _Label(text: t('family.payer')),
              const SizedBox(height: 8),
              if (widget.payers.isEmpty)
                Text(
                  t('family.noPayer'),
                  style: const TextStyle(
                    fontSize: 12,
                    color: NeonPalette.amber,
                  ),
                )
              else
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final payer in widget.payers)
                      NeonChip(
                        key: ValueKey('allowance-payer-${payer.id}'),
                        label: payer.displayName,
                        accent: familyRoleAccent(payer.role, isDark: isDark),
                        selected: payer.id == _payerId,
                        onTap: () => setState(() => _payerId = payer.id),
                      ),
                  ],
                ),
              const SizedBox(height: 22),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  NeonButton(
                    label: t('common.cancel'),
                    variant: NeonButtonVariant.ghost,
                    accent: NeonPalette.textSecondary,
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                  const SizedBox(width: 10),
                  Flexible(
                    child: NeonButton(
                      key: const ValueKey('allowance-confirm'),
                      label: t('family.payConfirm'),
                      accent: NeonPalette.lime,
                      onPressed: _payerId == null
                          ? null
                          : () => Navigator.of(context).pop(
                                AllowanceRequest(
                                  payerId: _payerId!,
                                  period: _period,
                                ),
                              ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A month picked by stepping, not typed.
///
/// The period is a `YYYY-MM` key the server parses strictly, and a free-text
/// field is the only way to get that wrong.
class _MonthStepper extends ConsumerWidget {
  const _MonthStepper({required this.period, required this.onChanged});

  final String period;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);
    final isDark = Theme.of(context).brightness == Brightness.dark;

    // "Earlier" is towards the start of the line, whichever side that is; the
    // arrow has to follow the text direction or it points at the wrong month.
    final rtl = Directionality.of(context) == TextDirection.rtl;

    return Row(
      children: [
        _StepButton(
          buttonKey: const ValueKey('allowance-period-previous'),
          icon: rtl ? Icons.chevron_right_rounded : Icons.chevron_left_rounded,
          onPressed: () => onChanged(shiftMonth(period, -1)),
        ),
        Expanded(
          child: Text(
            familyPeriodLabel(period, locale.code),
            key: const ValueKey('allowance-period'),
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 15,
              fontWeight: FontWeight.w800,
              color: isDark
                  ? NeonPalette.textPrimary
                  : NeonPalette.lightTextPrimary,
            ),
          ),
        ),
        _StepButton(
          buttonKey: const ValueKey('allowance-period-next'),
          icon: rtl ? Icons.chevron_left_rounded : Icons.chevron_right_rounded,
          onPressed: () => onChanged(shiftMonth(period, 1)),
        ),
      ],
    );
  }
}

class _StepButton extends StatelessWidget {
  const _StepButton({
    required this.buttonKey,
    required this.icon,
    required this.onPressed,
  });

  final Key buttonKey;
  final IconData icon;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return IconButton(
      key: buttonKey,
      onPressed: onPressed,
      icon: Icon(
        icon,
        color: isDark ? NeonPalette.textPrimary : NeonPalette.lightTextPrimary,
      ),
    );
  }
}

class _Label extends StatelessWidget {
  const _Label({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Text(
      text,
      style: TextStyle(
        fontSize: 11.5,
        fontWeight: FontWeight.w700,
        color: isDark
            ? NeonPalette.textSecondary
            : NeonPalette.lightTextSecondary,
      ),
    );
  }
}
