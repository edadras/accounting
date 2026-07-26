import 'package:dio/dio.dart';
import 'package:finora/core/i18n/app_locale.dart';
import 'package:finora/core/i18n/translator.dart';
import 'package:finora/core/theme/neon_theme.dart';
import 'package:finora/data/billing_repository.dart';
import 'package:finora/data/modules_repository.dart' show clockProvider;
import 'package:finora/data/remote/api_client.dart';
import 'package:finora/data/search_repository.dart';
import 'package:finora/presentation/features/billing/billing_providers.dart';
import 'package:finora/presentation/features/search/search_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'fake_server.dart';

/// The search and billing screens, wired to a fake socket.
///
/// Same two rules as `membership_harness.dart`, and breaking either hangs the
/// suite instead of failing it:
///
/// * anything awaiting Dio outside a widget test runs in plain `test`, or in
///   `tester.runAsync` — `testWidgets` drives a fake clock that never completes
///   a real future;
/// * nothing calls `pumpAndSettle`. The search field owns a debounce timer and
///   both screens open on a progress indicator, so a settle would wait out its
///   whole budget. Frames are pumped explicitly, and the debounce is crossed by
///   pumping past [searchDebounce] on purpose.
ApiClient fakeClient(MockAdapter adapter) => ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: adapter,
      policy: const RetryPolicy(maxAttempts: 1),
    );

/// A fixed clock, so "until 2026-08-24" is the same sentence every run.
final billingClock = DateTime.utc(2026, 7, 25, 12);

Future<void> pumpSearchBillingApp(
  WidgetTester tester,
  Widget screen, {
  required AppLocale locale,
  MockAdapter? adapter,
  List<Override> extraOverrides = const [],
}) async {
  tester.view.physicalSize = const Size(430, 932);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final client = adapter == null ? null : fakeClient(adapter);

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        localeProvider.overrideWith((ref) => locale),
        clockProvider.overrideWithValue(billingClock),
        if (client != null) ...[
          searchRepositoryProvider
              .overrideWithValue(SearchRepository(client: client)),
          billingRepositoryProvider
              .overrideWithValue(BillingRepository(client: client)),
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

/// Explicit frames, never `pumpAndSettle`.
Future<void> pumpFrames(WidgetTester tester, {int frames = 6}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// Crosses the debounce and then lets the answer arrive.
Future<void> pumpPastDebounce(WidgetTester tester) async {
  await tester.pump(searchDebounce + const Duration(milliseconds: 20));
  await pumpFrames(tester);
}

// ------------------------------------------------------------- search server

/// An in-memory stand-in for `SearchController` and `SemanticSearchController`.
///
/// The engine behind the real endpoints is Meilisearch or a LIKE over an index
/// table, and neither is available here; what the screens depend on is the
/// *shape* of the answer, which this reproduces exactly.
final class FakeSearchServer {
  FakeSearchServer();

  final List<RequestOptions> requests = [];

  /// Keyword answers, per type. An absent type is a group the server did not
  /// return at all; an empty list is a group that matched nothing.
  Map<String, List<Map<String, Object?>>> groups = {
    'transactions': [],
    'documents': [],
    'categories': [],
  };

  List<Map<String, Object?>> semanticHits = [];

  Map<String, Object?> filter = {
    'type': null,
    'from': null,
    'to': null,
    'provider': 'deterministic',
  };

  Map<String, Object?> summary = {
    'transaction_count': 0,
    'total': 0,
    'currency': 'TRY',
  };

  String embeddingModel = 'local-hashed-v1';
  int scanned = 0;

  /// What the backend's `TextNormalizer` would have made of the query. Set by
  /// a test that wants the "searched as …" line.
  String? normalized;

  int get keywordCalls =>
      requests.where((r) => r.path == '/search').length;

  int get semanticCalls =>
      requests.where((r) => r.path == '/search/semantic').length;

  String get lastQuery =>
      '${requests.last.queryParameters['q'] ?? ''}';

  ResponseBody handle(RequestOptions options) {
    requests.add(options);

    final query = '${options.queryParameters['q'] ?? ''}';

    if (options.path == '/search/semantic') {
      return MockAdapter.json({
        'data': semanticHits,
        'meta': {
          'query': query,
          'normalized_query': normalized ?? query,
          'filter': filter,
          'summary': summary,
          'embedding_model': embeddingModel,
          'limit': 20,
          'scanned': scanned,
          'total': semanticHits.length,
        },
      });
    }

    return MockAdapter.json({
      'data': groups,
      'meta': {
        'query': query,
        'normalized_query': normalized ?? query,
        'types': groups.keys.toList(),
        'limit': 20,
        'total': groups.values.fold(0, (sum, rows) => sum + rows.length),
      },
    });
  }
}

Map<String, Object?> transactionJson({
  required String id,
  String type = 'expense',
  String? description = 'خرید گوشت',
  String? payee,
  String? notes,
  int amount = 35000,
  String currency = 'TRY',
  String occurredAt = '2026-07-20T12:00:00Z',
  String? categoryName = 'خوراک',
  String? categoryPath = '/food',
}) =>
    {
      'id': id,
      'type': type,
      'description': description,
      'payee': payee,
      'notes': notes,
      'amount': {'value': amount, 'currency': currency},
      'occurred_at': occurredAt,
      'category': categoryName == null
          ? null
          : {'id': 'c-$id', 'name': categoryName, 'path': categoryPath},
    };

Map<String, Object?> documentJson({
  required String id,
  String originalName = 'receipt.pdf',
  String kind = 'receipt',
  String mime = 'application/pdf',
  int size = 240 * 1024,
  String ocrStatus = 'done',
}) =>
    {
      'id': id,
      'original_name': originalName,
      'kind': kind,
      'mime': mime,
      'size': size,
      'ocr_status': ocrStatus,
    };

Map<String, Object?> categoryJson({
  required String id,
  String name = 'خوراک',
  String path = '/food',
  String type = 'expense',
  int depth = 0,
}) =>
    {
      'id': id,
      'name': name,
      'path': path,
      'type': type,
      'depth': depth,
    };

Map<String, Object?> semanticHitJson({
  required String type,
  required Map<String, Object?> record,
  double score = 0.7,
  double semantic = 0.8,
  double lexical = 0.4,
}) =>
    {
      'type': type,
      'id': record['id'],
      'score': score,
      'semantic_score': semantic,
      'lexical_score': lexical,
      'record': record,
    };

// ------------------------------------------------------------ billing server

/// An in-memory stand-in for the billing endpoints, with the same rules the
/// PHP actions apply: a trial cancels immediately, a paid period is honoured to
/// its end, and every write answers with the row the server ended up with.
final class FakeBillingServer {
  FakeBillingServer();

  final List<RequestOptions> requests = [];

  String planCode = 'premium';
  String effectivePlanCode = 'premium';
  String status = 'active';
  bool onTrial = false;
  String? trialEndsAt;
  String? renewsAt = '2026-08-24T09:00:00Z';
  String? cancelledAt;

  Map<String, bool> flags = const {
    'ai': true,
    'ocr': true,
    'voice': true,
    'investment': true,
    'shared_budget': false,
    'invoicing': false,
    'payroll': false,
    'roles': false,
    'sso': false,
    'advanced_audit': false,
    'sla': false,
  };

  Map<String, int?> limits = const {
    'workspaces': null,
    'accounts': null,
    'members': 1,
    'budgets': null,
  };

  Map<String, int> usage = const {
    'workspaces': 2,
    'accounts': 4,
    'members': 1,
    'budgets': 3,
  };

  List<Map<String, Object?>> invoices = [];

  /// Set to make the next write fail the way `BillingException` does.
  String? failWith;
  int failStatus = 422;

  int cancelCalls = 0;
  int subscribeCalls = 0;
  int trialCalls = 0;

  ResponseBody handle(RequestOptions options) {
    requests.add(options);

    final path = options.path;
    final method = options.method.toUpperCase();

    if (path == '/billing/plans') {
      return MockAdapter.json({'data': defaultPlans});
    }

    if (path == '/billing/invoices') {
      return MockAdapter.json({'data': invoices});
    }

    if (path == '/billing/subscription' && method == 'GET') {
      return MockAdapter.json({
        'data': _subscription(),
        'entitlements': {
          'plan': effectivePlanCode,
          'flags': flags,
          'limits': limits,
        },
        'usage': usage,
      });
    }

    final failure = failWith;
    if (failure != null) {
      return MockAdapter.error(
        failure,
        status: failStatus,
        // Deliberately English: the app must never put this on screen.
        message: 'The workspace is already on that plan.',
      );
    }

    if (path == '/billing/subscription/cancel') {
      cancelCalls++;
      // CancelSubscription: a trial loses its renewal date, a paid period keeps
      // it, and the plan stays in force until then.
      cancelledAt = '2026-07-25T12:00:00Z';
      if (status == 'trialing') {
        renewsAt = null;
        effectivePlanCode = 'free';
      }
      status = 'cancelled';
      onTrial = false;
      return MockAdapter.json({'data': _subscription()});
    }

    if (path == '/billing/subscription/trial') {
      trialCalls++;
      planCode = '${(options.data as Map?)?['plan_code'] ?? planCode}';
      effectivePlanCode = planCode;
      status = 'trialing';
      onTrial = true;
      trialEndsAt = '2026-08-08T12:00:00Z';
      renewsAt = null;
      return MockAdapter.json({'data': _subscription()}, status: 201);
    }

    if (path == '/billing/subscription' && method == 'POST') {
      subscribeCalls++;
      planCode = '${(options.data as Map?)?['plan_code'] ?? planCode}';
      effectivePlanCode = planCode;
      status = 'active';
      onTrial = false;
      cancelledAt = null;
      renewsAt = '2026-08-25T12:00:00Z';
      return MockAdapter.json({'data': _subscription()});
    }

    return MockAdapter.json(const <String, Object?>{}, status: 204);
  }

  Map<String, Object?> _subscription() => {
        'id': '01JSUBSCRIPTION0000000000',
        'plan_code': planCode,
        'effective_plan_code': effectivePlanCode,
        'status': status,
        'on_trial': onTrial,
        'trial_ends_at': trialEndsAt,
        'renews_at': renewsAt,
        'cancelled_at': cancelledAt,
      };

  /// The catalogue Config/plans.php seeds, trimmed to the three the screens
  /// need to show an upgrade, a downgrade and the fallback.
  static const defaultPlans = [
    {
      'code': 'free',
      'name': 'Free',
      'price': {
        'amount': 0,
        'currency': 'USD',
        'minor_unit': 2,
        'decimal': '0.00',
      },
      'interval': 'monthly',
      'features': {
        'limits': {
          'workspaces': 1,
          'accounts': 2,
          'members': 1,
          'budgets': 2,
        },
        'flags': {
          'ai': false,
          'ocr': false,
          'voice': false,
          'investment': false,
          'shared_budget': false,
          'invoicing': false,
          'payroll': false,
          'roles': false,
          'sso': false,
          'advanced_audit': false,
          'sla': false,
        },
      },
    },
    {
      'code': 'premium',
      'name': 'Premium',
      'price': {
        'amount': 999,
        'currency': 'USD',
        'minor_unit': 2,
        'decimal': '9.99',
      },
      'interval': 'monthly',
      'features': {
        'limits': {
          'workspaces': null,
          'accounts': null,
          'members': 1,
          'budgets': null,
        },
        'flags': {
          'ai': true,
          'ocr': true,
          'voice': true,
          'investment': true,
          'shared_budget': false,
          'invoicing': false,
          'payroll': false,
          'roles': false,
          'sso': false,
          'advanced_audit': false,
          'sla': false,
        },
      },
    },
    {
      'code': 'family',
      'name': 'Family',
      'price': {
        'amount': 1499,
        'currency': 'USD',
        'minor_unit': 2,
        'decimal': '14.99',
      },
      'interval': 'monthly',
      'features': {
        'limits': {
          'workspaces': null,
          'accounts': null,
          'members': 5,
          'budgets': null,
        },
        'flags': {
          'ai': true,
          'ocr': true,
          'voice': true,
          'investment': true,
          'shared_budget': true,
          'invoicing': false,
          'payroll': false,
          'roles': false,
          'sso': false,
          'advanced_audit': false,
          'sla': false,
        },
      },
    },
  ];
}

Map<String, Object?> invoiceJson({
  required String id,
  String number = 'INV-202607-ABCDEF1234',
  String planCode = 'premium',
  int amount = 999,
  String status = 'paid',
  String? periodStart = '2026-07-25T09:00:00Z',
  String? periodEnd = '2026-08-24T09:00:00Z',
  String? issuedAt = '2026-07-25T09:00:00Z',
  String? paidAt = '2026-07-25T09:00:00Z',
}) =>
    {
      'id': id,
      'number': number,
      'plan_code': planCode,
      'amount': {
        'amount': amount,
        'currency': 'USD',
        'minor_unit': 2,
        'decimal': '9.99',
      },
      'status': status,
      'period_start': periodStart,
      'period_end': periodEnd,
      'issued_at': issuedAt,
      'paid_at': paidAt,
    };
