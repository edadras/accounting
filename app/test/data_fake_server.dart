import 'package:dio/dio.dart';
import 'package:finora/data/remote/api_client.dart';

import 'support/fake_server.dart';

/// An in-memory stand-in for the DataOps and report-export endpoints.
///
/// The job states are fields rather than a scripted queue, so a test moves an
/// export from queued to ready when it wants to — which is the only way to
/// assert that the screen showed the queued state on the way through.
final class FakeDataOpsServer {
  FakeDataOpsServer({this.now = '2026-07-25T09:00:00Z'});

  final String now;

  static const exportId = '01JEXPORT0000000000000000';
  static const reportExportId = '01JREPORTEXPORT0000000000';
  static const purgeAfter = '2026-08-24T09:00:00Z';
  static const expiresAt = '2026-08-01T09:00:00Z';
  static const downloadUrl =
      'https://finora.test/api/v1/exports/$exportId/download';
  static const reportDownloadUrl =
      'https://finora.test/api/v1/reports/exports/$reportExportId/download';

  final List<RequestOptions> requests = [];

  /// Rows the workspace already had before this screen was opened.
  List<Map<String, Object?>> history = [];

  bool exportRequested = false;
  String exportStatus = 'pending';
  int? exportSize;

  String reportStatus = 'pending';
  int? reportSize;
  int? reportRows;

  /// Flipped by the test that types the wrong password.
  bool passwordAccepted = true;

  /// Set when the account has no deletion to cancel.
  bool deletionScheduled = true;

  int deleteCalls = 0;
  int restoreCalls = 0;

  ResponseBody handle(RequestOptions options) {
    requests.add(options);

    final path = options.path;
    final method = options.method.toUpperCase();

    if (path == 'exports') {
      if (method == 'POST') {
        exportRequested = true;
        return MockAdapter.json({'data': _export()}, status: 202);
      }
      return MockAdapter.json({
        'data': [
          if (exportRequested) _export(),
          ...history,
        ],
      });
    }

    if (path == 'me' && method == 'DELETE') {
      deleteCalls++;
      if (!passwordAccepted) {
        return MockAdapter.error(
          'incorrect_password',
          status: 403,
          // Deliberately English: the app must never put this on screen.
          message: 'The password does not match this account.',
        );
      }
      return MockAdapter.json(
        {
          'data': {
            'status': 'deletion_scheduled',
            'grace_days': 30,
            'requested_at': now,
            'purge_after': purgeAfter,
          },
        },
        status: 202,
      );
    }

    if (path == 'me/restore') {
      restoreCalls++;
      if (!deletionScheduled) {
        return MockAdapter.error('deletion_not_scheduled');
      }
      return MockAdapter.json({
        'data': {'status': 'active'},
      });
    }

    if (path.startsWith('reports/exports/')) {
      return MockAdapter.json({'data': _reportExport()});
    }

    if (path.startsWith('reports/') && path.endsWith('/export')) {
      return MockAdapter.json({'data': _reportExport()}, status: 202);
    }

    return MockAdapter.json({'data': const <String, Object?>{}});
  }

  Map<String, Object?> _export() => {
        'id': exportId,
        'status': exportStatus,
        'format': 'zip',
        'size': exportSize,
        'error': exportStatus == 'failed' ? 'zip: no space left on device' : null,
        'expires_at': expiresAt,
        'download_url': exportStatus == 'ready' ? downloadUrl : null,
        'created_at': now,
        'updated_at': now,
      };

  Map<String, Object?> _reportExport() => {
        'id': reportExportId,
        'report': 'cash-flow',
        'format': lastRequestedFormat,
        'locale': lastRequestedLocale,
        'status': reportStatus,
        'filename': 'cash-flow.$lastRequestedFormat',
        'size': reportSize,
        'row_count': reportRows,
        'error': null,
        'created_at': now,
        'completed_at': reportStatus == 'ready' ? now : null,
        'download_url': reportStatus == 'ready' ? reportDownloadUrl : null,
      };

  /// What the last export request asked for, so the answer echoes it back.
  String get lastRequestedFormat => _lastBody('format') ?? 'csv';
  String get lastRequestedLocale => _lastBody('locale') ?? 'fa';

  String? _lastBody(String key) {
    for (final request in requests.reversed) {
      final data = request.data;
      if (data is Map && data[key] is String) return data[key] as String;
    }
    return null;
  }

  /// A finished export from some earlier day, for the history list.
  static Map<String, Object?> pastExport({
    String id = '01JOLDEXPORT00000000000000',
    int size = 2 * 1024 * 1024,
  }) =>
      {
        'id': id,
        'status': 'ready',
        'format': 'zip',
        'size': size,
        'error': null,
        'expires_at': expiresAt,
        'download_url': 'https://finora.test/api/v1/exports/$id/download',
        'created_at': '2026-07-20T09:00:00Z',
        'updated_at': '2026-07-20T09:00:00Z',
      };
}

/// An [ApiClient] wired to [server], with retries off so a 4xx surfaces at once.
ApiClient dataOpsClient(FakeDataOpsServer server) => ApiClient.create(
      session: ApiSession(
        deviceId: '01JDEVICE0000000000000000',
        token: 'token',
        workspaceId: '01JWORKSPACE00000000000000',
      ),
      adapter: MockAdapter(server.handle),
      policy: const RetryPolicy(maxAttempts: 1),
    );
