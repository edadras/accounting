import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translations.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/presentation/features/audit/audit_screen.dart';
import 'package:finora/presentation/features/audit/security_log_screen.dart';
import 'package:finora/presentation/features/data/account_deletion_screen.dart';
import 'package:finora/presentation/features/data/data_export_screen.dart';
import 'package:finora/presentation/features/members/accept_invitation_screen.dart';
import 'package:finora/presentation/features/members/members_screen.dart';
import 'package:finora/presentation/features/security/two_factor_screen.dart';
import 'package:finora/presentation/features/settings/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// The screens exist and are tested; this asks the separate question of
/// whether a user can actually get to them.
///
/// Every one of these was built by a different agent against a `static route()`
/// that nothing called. A tile that looks wired and opens nothing is the exact
/// failure this integration invites, and no feature test can see it.
void main() {
  Widget harness({AppLocale locale = AppLocale.en}) {
    return ProviderScope(
      overrides: [localeProvider.overrideWith((ref) => locale)],
      child: MaterialApp(
        locale: Locale(locale.code),
        home: Directionality(
          textDirection: locale.isRtl ? TextDirection.rtl : TextDirection.ltr,
          child: const Scaffold(body: SettingsScreen()),
        ),
      ),
    );
  }

  for (final (id, type) in <(String, Type)>[
    ('two-factor', TwoFactorScreen),
    ('members', MembersScreen),
    ('accept-invitation', AcceptInvitationScreen),
    ('audit', AuditTrailScreen),
    ('security-log', SecurityLogScreen),
    ('export', DataExportScreen),
    ('delete-account', AccountDeletionScreen),
  ]) {
    testWidgets('the "$id" tile opens $type', (tester) async {
      // Tall enough for the whole section. `ListView(children:)` still builds
      // lazily, so on the default 800×600 surface the lower tiles are not in
      // the tree at all and both `ensureVisible` and `tap` fail on a widget
      // that exists only in the source.
      tester.view.physicalSize = const Size(430, 1900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await tester.pumpWidget(harness());

      final tile = find.byKey(ValueKey('settings-$id'));
      await tester.ensureVisible(tile);
      await tester.pumpAndSettle();
      await tester.tap(tile);

      // Not pumpAndSettle: several of these screens start a request as soon as
      // they build, and a live spinner means settle never returns.
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 350));

      expect(find.byType(type), findsOneWidget);
    });
  }

  testWidgets('every tile is labelled in every locale', (tester) async {
    // A tile whose title key is missing renders as the raw key, which reads as
    // "data.export.title" on someone's screen.
    const keys = [
      'settings.account',
      'settings.securityHint',
      'settings.exportHint',
      'settings.deleteHint',
      'security.title',
      'members.title',
      'members.subtitle',
      'members.acceptTitle',
      'members.acceptSubtitle',
      'audit.title',
      'audit.subtitle',
      'audit.securityTitle',
      'audit.securitySubtitle',
      'data.export.title',
      'data.delete.title',
    ];

    for (final locale in AppLocale.supported) {
      final table = BundledTranslations.byLocale[locale.code]!;
      for (final key in keys) {
        expect(
          table[key],
          isNotNull,
          reason: '"$key" is missing from ${locale.code}',
        );
      }
    }
  });

  testWidgets('the account section survives an RTL layout', (tester) async {
    await tester.pumpWidget(harness(locale: AppLocale.fa));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byKey(const ValueKey('settings-two-factor')), findsOneWidget);
  });
}
