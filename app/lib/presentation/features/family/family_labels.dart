import 'package:flutter/material.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/family_repository.dart';

/// The words and the colours a household role is drawn with, in one place so
/// the list, the detail and the form cannot disagree.

String familyRoleLabel(Translator t, FamilyRole role) =>
    t(switch (role) {
      FamilyRole.parent => 'family.roleParent',
      FamilyRole.child => 'family.roleChild',
      FamilyRole.other => 'family.roleOther',
    },);

/// Colour by role, not by rank: a parent pays, a child is paid, and the third
/// role is neither. Nothing here means "warning" — that is reserved for a
/// member who is over their cap.
Color familyRoleAccent(FamilyRole role, {required bool isDark}) =>
    switch (role) {
      FamilyRole.parent => isDark ? NeonPalette.cyan : NeonPalette.lightCyan,
      FamilyRole.child => isDark ? NeonPalette.violet : NeonPalette.lightViolet,
      FamilyRole.other =>
        isDark ? NeonPalette.textSecondary : NeonPalette.lightTextSecondary,
    };

/// A `YYYY-MM` period with its digits in the reader's script.
///
/// The key itself stays Latin on the wire — it is an identifier the server
/// parses — so it is only ever reshaped on its way to the screen.
String familyPeriodLabel(String period, String localeCode) {
  final parts = period.split('-');
  if (parts.length != 2) return period;

  final year = int.tryParse(parts[0]);
  final month = int.tryParse(parts[1]);
  if (year == null || month == null) return period;

  return '${DateFormatter.number(year, localeCode)}/'
      '${DateFormatter.number(month, localeCode)}';
}

/// The month before or after [period], as another `YYYY-MM` key.
String shiftMonth(String period, int months) {
  final parts = period.split('-');
  if (parts.length != 2) return period;

  final year = int.tryParse(parts[0]);
  final month = int.tryParse(parts[1]);
  if (year == null || month == null) return period;

  // Built through DateTime so December → January carries the year with it.
  final shifted = DateTime(year, month + months);

  return '${shifted.year.toString().padLeft(4, '0')}-'
      '${shifted.month.toString().padLeft(2, '0')}';
}
