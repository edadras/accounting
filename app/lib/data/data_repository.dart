import 'remote/api_client.dart';
import 'remote/api_exception.dart';

/// A refusal from the data-operations endpoints, carrying the server's stable
/// machine-readable code.
///
/// The server also sends an English sentence. It is deliberately dropped here:
/// the app renders [translationKey] instead, so a Persian user never sees
/// "The password does not match this account." and the wording stays testable.
final class DataOpsException implements Exception {
  const DataOpsException({
    required this.code,
    this.statusCode,
    this.details = const {},
  });

  final String code;
  final int? statusCode;

  /// Field or key → the server's values, e.g. `{'purge_after': ['2026-08-…']}`.
  final Map<String, List<String>> details;

  /// Polling gave up before the job reached a terminal state. Client-side, so
  /// it has no server counterpart.
  static const codePollTimeout = 'export_timeout';

  static const codeIncorrectPassword = 'incorrect_password';
  static const codeDeletionAlreadyScheduled = 'deletion_already_scheduled';
  static const codeDeletionNotScheduled = 'deletion_not_scheduled';
  static const codeDeletionWindowClosed = 'deletion_window_closed';

  String get translationKey => 'error.$code';

  /// The deadline the deletion codes name, when they name one.
  DateTime? get purgeAfter {
    final raw = details['purge_after'];
    if (raw == null || raw.isEmpty) return null;
    return DateTime.tryParse(raw.first)?.toLocal();
  }

  factory DataOpsException.from(Object error) => switch (error) {
        DataOpsException failure => failure,
        ApiException failure => DataOpsException(
            code: failure.code,
            statusCode: failure.statusCode,
            details: failure.details,
          ),
        _ => const DataOpsException(code: ApiException.codeUnexpected),
      };

  @override
  String toString() => 'DataOpsException($code, status: $statusCode)';
}

/// Where a queued job has got to.
///
/// The two backends spell the middle state differently — a workspace export is
/// `running`, a report export is `processing` — and the difference means
/// nothing to a person watching a progress bar, so both land here.
enum JobStatus {
  pending,
  running,
  ready,
  failed;

  static JobStatus parse(Object? raw) => switch (raw) {
        'ready' => ready,
        'failed' => failed,
        'running' || 'processing' => running,
        _ => pending,
      };

  bool get isTerminal => this == ready || this == failed;
}

/// The file formats a report may be asked for, mirroring the server's closed
/// enum in `Modules\Reports\Support\ExportFormat`.
enum ReportFormat {
  csv,
  xlsx,
  pdf;

  String get wire => name;

  static ReportFormat parse(Object? raw) => switch (raw) {
        'xlsx' => xlsx,
        'pdf' => pdf,
        _ => csv,
      };
}

/// One request for a complete copy of a workspace.
final class WorkspaceExport {
  const WorkspaceExport({
    required this.id,
    required this.status,
    this.sizeBytes,
    this.downloadUrl,
    this.expiresAt,
    this.createdAt,
  });

  final String id;
  final JobStatus status;

  /// Bytes, as the server counts them. Never a double: a size is a count.
  final int? sizeBytes;
  final String? downloadUrl;
  final DateTime? expiresAt;
  final DateTime? createdAt;

  bool get isExpired =>
      expiresAt != null && expiresAt!.isBefore(DateTime.now().toUtc());

  factory WorkspaceExport.fromJson(Map<String, Object?> json) {
    return WorkspaceExport(
      id: '${json['id']}',
      status: JobStatus.parse(json['status']),
      sizeBytes: (json['size'] as num?)?.toInt(),
      downloadUrl: json['download_url'] as String?,
      expiresAt: _parseDate(json['expires_at']),
      createdAt: _parseDate(json['created_at']),
    );
  }
}

/// One rendered report waiting to be collected.
final class ReportExport {
  const ReportExport({
    required this.id,
    required this.status,
    required this.report,
    required this.format,
    required this.locale,
    this.filename,
    this.sizeBytes,
    this.rowCount,
    this.downloadUrl,
    this.createdAt,
  });

  final String id;
  final JobStatus status;
  final String report;
  final ReportFormat format;
  final String locale;
  final String? filename;
  final int? sizeBytes;
  final int? rowCount;
  final String? downloadUrl;
  final DateTime? createdAt;

  factory ReportExport.fromJson(Map<String, Object?> json) {
    return ReportExport(
      id: '${json['id']}',
      status: JobStatus.parse(json['status']),
      report: '${json['report']}',
      format: ReportFormat.parse(json['format']),
      locale: json['locale'] as String? ?? 'fa',
      filename: json['filename'] as String?,
      sizeBytes: (json['size'] as num?)?.toInt(),
      rowCount: (json['row_count'] as num?)?.toInt(),
      downloadUrl: json['download_url'] as String?,
      createdAt: _parseDate(json['created_at']),
    );
  }
}

/// An account marked for erasure, and the moment that becomes irreversible.
final class ScheduledDeletion {
  const ScheduledDeletion({
    required this.purgeAfter,
    required this.graceDays,
    this.requestedAt,
  });

  final DateTime purgeAfter;
  final int graceDays;
  final DateTime? requestedAt;

  bool get canStillCancel => purgeAfter.isAfter(DateTime.now());
}

/// The client half of the two promises in docs/07-security.md §8: the right to
/// take your data out, and the right to be deleted.
final class DataRepository {
  DataRepository({
    required ApiClient client,
    this.pollInterval = const Duration(seconds: 3),
    this.maxPollAttempts = 60,
  }) : _client = client;

  final ApiClient _client;

  /// How long to wait between two status reads, and how many to try before
  /// admitting the job is not coming back.
  final Duration pollInterval;
  final int maxPollAttempts;

  /// Mirrors `dataops.deletion.grace_days`. Used only to state the window
  /// *before* a deletion exists; once one does, the server's own number wins.
  static const defaultGraceDays = 30;

  // -------------------------------------------------------------- exports

  Future<WorkspaceExport> requestExport() async {
    final body = await _send(() => _client.post('exports'));
    return WorkspaceExport.fromJson(_object(body));
  }

  Future<List<WorkspaceExport>> exports() async {
    final body = await _send(() => _client.get('exports'));
    return [
      for (final row in _list(body)) WorkspaceExport.fromJson(row),
    ];
  }

  /// One export's current state.
  ///
  /// The API publishes no per-export route — only the collection — so this
  /// reads the list and picks the row out of it.
  Future<WorkspaceExport> exportById(String id) async {
    for (final export in await exports()) {
      if (export.id == id) return export;
    }
    throw const DataOpsException(code: 'not_found', statusCode: 404);
  }

  /// Emits the export's state on every read, and stops as soon as it is ready
  /// or failed. Throws [DataOpsException.codePollTimeout] if it never settles.
  Stream<WorkspaceExport> watchExport(String id, {Duration? interval}) {
    return _watch(() => exportById(id), interval: interval);
  }

  // -------------------------------------------------------------- deletion

  /// Schedules erasure and answers with the date it becomes irreversible.
  Future<ScheduledDeletion> scheduleDeletion({required String password}) async {
    final body = await _send(
      () => _client.delete('me', body: {'password': password}),
    );
    final data = _object(body);

    final graceDays = (data['grace_days'] as num?)?.toInt() ?? defaultGraceDays;
    final requestedAt = _parseDate(data['requested_at']);

    return ScheduledDeletion(
      graceDays: graceDays,
      requestedAt: requestedAt,
      // A schedule with no deadline would leave the screen unable to say when
      // the data goes, so the grace period stands in for a missing field.
      purgeAfter: _parseDate(data['purge_after']) ??
          (requestedAt ?? DateTime.now()).add(Duration(days: graceDays)),
    );
  }

  Future<void> cancelDeletion() async {
    await _send(() => _client.post('me/restore'));
  }

  // --------------------------------------------------------- report export

  /// Asks for a report as a file.
  ///
  /// Always queued: an inline export answers with the bytes themselves, and
  /// this client has nowhere to put them — it wants an id it can poll and a
  /// link it can hand to the browser.
  Future<ReportExport> requestReportExport({
    required String report,
    required ReportFormat format,
    required String locale,
    DateTime? from,
    DateTime? to,
  }) async {
    final body = await _send(
      () => _client.post(
        'reports/$report/export',
        body: {
          'format': format.wire,
          'locale': locale,
          'queued': true,
          if (from != null) 'from': _isoDate(from),
          if (to != null) 'to': _isoDate(to),
        },
      ),
    );
    return ReportExport.fromJson(_object(body));
  }

  Future<ReportExport> reportExportById(String id) async {
    final body = await _send(() => _client.get('reports/exports/$id'));
    return ReportExport.fromJson(_object(body));
  }

  Stream<ReportExport> watchReportExport(String id, {Duration? interval}) {
    return _watch(() => reportExportById(id), interval: interval);
  }

  // ---------------------------------------------------------------- shared

  Stream<T> _watch<T>(
    Future<T> Function() read, {
    Duration? interval,
  }) async* {
    final wait = interval ?? pollInterval;

    for (var attempt = 0; attempt < maxPollAttempts; attempt++) {
      if (attempt > 0) await Future<void>.delayed(wait);

      final snapshot = await read();
      yield snapshot;

      final status = switch (snapshot) {
        WorkspaceExport export => export.status,
        ReportExport export => export.status,
        _ => JobStatus.ready,
      };
      if (status.isTerminal) return;
    }

    throw const DataOpsException(code: DataOpsException.codePollTimeout);
  }

  Future<T> _send<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw DataOpsException.from(error);
    }
  }

  static Map<String, Object?> _object(Map<String, Object?> body) {
    final data = body['data'];
    return data is Map ? data.cast<String, Object?>() : const {};
  }

  static List<Map<String, Object?>> _list(Map<String, Object?> body) {
    final data = body['data'];
    if (data is! List) return const [];
    return [
      for (final row in data)
        if (row is Map) row.cast<String, Object?>(),
    ];
  }

  static String _isoDate(DateTime date) =>
      '${date.year.toString().padLeft(4, '0')}-'
      '${date.month.toString().padLeft(2, '0')}-'
      '${date.day.toString().padLeft(2, '0')}';
}

DateTime? _parseDate(Object? raw) =>
    raw is String ? DateTime.tryParse(raw)?.toLocal() : null;
