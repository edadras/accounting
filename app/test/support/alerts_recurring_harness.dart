import 'dart:io';

import 'package:finora/core/date/date_formatter.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translations.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/alerts_repository.dart';
import 'package:finora/data/modules_repository.dart' show clockProvider;
import 'package:finora/data/recurring_repository.dart';
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/presentation/features/alerts/alerts_providers.dart';
import 'package:finora/presentation/features/recurring/recurring_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fake_server.dart';

/// Everything the alerts and recurring screens need to run against a fake
/// socket.
///
/// Two rules govern every test that uses this file, and breaking either one
/// hangs the suite rather than failing it:
///
/// * anything that awaits Dio directly runs inside `tester.runAsync`, because
///   `testWidgets` drives a fake clock that never completes a real future;
/// * nothing calls `pumpAndSettle` — both features open on a
///   `CircularProgressIndicator`, and a tree with a live progress indicator
///   never goes quiet.
///
/// Driving the widget tree itself needs neither: [MockAdapter] answers from a
/// function, so the response lands on a microtask that [pumpFrames] flushes.

/// Pinned so a "runs in N days" badge describes a layout rather than today.
final featureClock = DateTime.utc(2026, 7, 25, 12);

ApiClient featureClient(MockAdapter adapter) => ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1),
    );

Future<void> pumpFrames(WidgetTester tester, {int frames = 6}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// Renders [screen] with the alerts and recurring repositories pointed at
/// [adapter]. Omitting the adapter leaves both providers throwing their
/// no-backend failure, which is the demo build and a state the screens must
/// explain rather than crash on.
Future<void> pumpFeature(
  WidgetTester tester,
  Widget screen, {
  required AppLocale locale,
  MockAdapter? adapter,
  List<Override> extraOverrides = const [],
}) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final client = adapter == null ? null : featureClient(adapter);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        localeProvider.overrideWith((ref) => locale),
        clockProvider.overrideWithValue(featureClock),
        if (client != null) ...[
          alertsRepositoryProvider
              .overrideWithValue(AlertsRepository(client: client)),
          recurringRepositoryProvider
              .overrideWithValue(RecurringRepository(client: client)),
        ],
        ...extraOverrides,
      ],
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: NeonTheme.dark(fontFamily: 'Vazirmatn'),
        locale: locale.locale,
        supportedLocales: [for (final l in AppLocale.supported) l.locale],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        builder: (context, child) => Directionality(
          textDirection: locale.textDirection,
          child: child ?? const SizedBox.shrink(),
        ),
        home: screen,
      ),
    ),
  );

  await pumpFrames(tester);
}

/// The string a user would actually see, resolved the same way the widgets
/// resolve it — so a test never hard-codes wording that lives in one file.
String t(AppLocale locale, String key, {Map<String, String>? args}) =>
    Translator(locale: locale)(key, args: args);

/// A number the way the screens print it: localised digits wrapped in bidi
/// isolates, so an amount never drags a minus sign to the wrong end of an RTL
/// line. A test comparing against a bare `3` would never match.
String number(AppLocale locale, int value) =>
    DateFormatter.number(value, locale.code);

/// Scrolls [key] into view before tapping it. These forms are longer than the
/// 430x932 test window, and a tap on an off-screen button silently does
/// nothing.
Future<void> tapKey(WidgetTester tester, String key) async {
  final finder = find.byKey(ValueKey(key));

  // A `ListView` builds lazily, so a control far down the form does not exist
  // yet — scrolling towards it is what brings it into the tree at all.
  //
  // The scrollable is named through the list rather than as
  // `find.byType(Scrollable)`: a focused `TextField` owns an internal
  // `Scrollable` of its own, and dragging that one moves nothing.
  if (finder.evaluate().isEmpty) {
    await tester.scrollUntilVisible(
      finder,
      280,
      scrollable: find
          .descendant(
            of: find.byType(ListView).first,
            matching: find.byType(Scrollable),
          )
          .first,
    );
    await pumpFrames(tester, frames: 2);
  }

  await tester.ensureVisible(finder);
  await pumpFrames(tester, frames: 2);
  await tester.tap(finder);
  await pumpFrames(tester);
}

/// Guards against a key that exists in the app but not in the dictionary: the
/// translator returns the key itself, which would make a test pass on a blank
/// screen.
void expectTranslated(AppLocale locale, String key) {
  expect(
    BundledTranslations.byLocale[locale.code]?[key],
    isNotNull,
    reason: '$key is missing from ${locale.code}',
  );
}

/// Without the shipped font the test engine measures every glyph as a full em
/// square, which reports Persian overflows that do not exist on a device.
Future<void> loadFeatureFonts() async {
  await _loadFont('Vazirmatn', [
    'assets/fonts/Vazirmatn-Regular.ttf',
    'assets/fonts/Vazirmatn-Bold.ttf',
  ]);
  await _loadFont('MaterialIcons', [
    '${_flutterRoot()}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
  ]);
}

String _flutterRoot() {
  final fromEnv = Platform.environment['FLUTTER_ROOT'];
  if (fromEnv != null && fromEnv.isNotEmpty) return fromEnv;

  var dir = File(Platform.resolvedExecutable).parent;
  while (dir.path != dir.parent.path) {
    if (Directory('${dir.path}/bin/cache/artifacts/material_fonts')
        .existsSync()) {
      return dir.path;
    }
    dir = dir.parent;
  }
  return '';
}

Future<void> _loadFont(String family, List<String> paths) async {
  final loader = FontLoader(family);

  for (final path in paths) {
    final file = File(path);
    if (!file.existsSync()) continue;
    loader.addFont(
      file.readAsBytes().then((bytes) => ByteData.view(bytes.buffer)),
    );
  }

  await loader.load();
}

// ------------------------------------------------------------------ fixtures

/// The shape `App\Core\Money\Money::jsonSerialize()` writes inside an alert
/// payload — note `amount`, not the `value` the REST envelope uses.
Map<String, Object?> payloadMoney(
  int minorUnits, {
  String currency = 'TRY',
  int minorUnit = 2,
}) =>
    {
      'amount': minorUnits,
      'currency': currency,
      'minor_unit': minorUnit,
      'decimal': '0',
    };

Map<String, Object?> alertJson({
  required String id,
  required String type,
  required Map<String, Object?> payload,
  Map<String, String> channels = const {'database': 'sent'},
  String status = 'sent',
  String scheduledAt = '2026-07-25T09:00:00Z',
  String? sentAt = '2026-07-25T09:00:01Z',
  String? readAt,
}) =>
    {
      'id': id,
      'type': type,
      'payload': payload,
      'channels': channels,
      'status': status,
      'scheduled_at': scheduledAt,
      'sent_at': sentAt,
      'read_at': readAt,
    };

Map<String, Object?> checkDuePayload({
  String number = '1042',
  String party = 'Ali Rezaei',
  String direction = 'issued',
  int amount = 125000,
  String dueDate = '2026-07-28',
  num daysAhead = 3,
}) =>
    {
      'check_id': 'chk-1',
      'check_number': number,
      'direction': direction,
      'party_name': party,
      'amount': payloadMoney(amount),
      'due_date': dueDate,
      'days_ahead': daysAhead,
    };

Map<String, Object?> lowBalancePayload({
  String account = 'Bank Mellat',
  int balance = 12000,
  int threshold = 50000,
}) =>
    {
      'account_id': 'acc-1',
      'account_name': account,
      'balance': payloadMoney(balance),
      'threshold': payloadMoney(threshold),
    };

Map<String, Object?> alertRuleJson({
  required String id,
  required String type,
  Map<String, Object?> config = const {},
  List<String> channels = const ['database'],
  int leadDays = 3,
  bool isActive = true,
}) =>
    {
      'id': id,
      'type': type,
      'config': config,
      'channels': channels,
      'lead_days': leadDays,
      'is_active': isActive,
    };

Map<String, Object?> preferencesJson({
  Map<String, bool> channels = const {},
  String? quietStart,
  String? quietEnd,
  String? timezone,
}) =>
    {
      'channels': {
        'database': true,
        'push': true,
        'email': true,
        'sms': true,
        'telegram': true,
        'whatsapp': true,
        ...channels,
      },
      'quiet_hours_start': quietStart,
      'quiet_hours_end': quietEnd,
      'timezone': timezone,
    };

Map<String, Object?> recurringJson({
  required String id,
  String? name = 'Rent',
  String type = 'expense',
  int amount = 250000,
  String currency = 'TRY',
  String frequency = 'monthly',
  int interval = 1,
  int? dayOfMonth = 5,
  String startsAt = '2026-01-05T00:00:00Z',
  String? endsAt,
  String? nextRunAt = '2026-08-05T00:00:00Z',
  String? lastRunAt = '2026-07-05T00:00:00Z',
  bool autoPost = true,
  bool isPaused = false,
}) =>
    {
      'id': id,
      'name': name,
      'template': {
        'type': type,
        'account_id': 'acc-bank',
        'counter_account_id': null,
        'category_id': null,
        'amount': amount,
        'currency': currency,
        'description': 'Monthly rent',
        'payee': 'Landlord',
      },
      'frequency': frequency,
      'interval': interval,
      'day_of_month': dayOfMonth,
      'day_of_week': null,
      'starts_at': startsAt,
      'ends_at': endsAt,
      'next_run_at': nextRunAt,
      'last_run_at': lastRunAt,
      'auto_post': autoPost,
      'is_paused': isPaused,
    };
