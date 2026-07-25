import 'package:finora/data/data_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import 'data_fake_server.dart';

/// The transport half of the data & privacy work: what goes on the wire, and
/// what comes back off it as Dart.
void main() {
  late FakeDataOpsServer server;
  late DataRepository repository;

  setUp(() {
    server = FakeDataOpsServer();
    repository = DataRepository(
      client: dataOpsClient(server),
      pollInterval: Duration.zero,
      maxPollAttempts: 10,
    );
  });

  group('workspace export', () {
    test('a request comes back queued, and polling ends at a link', () async {
      final requested = await repository.requestExport();

      expect(requested.id, FakeDataOpsServer.exportId);
      expect(requested.status, JobStatus.pending);
      expect(requested.downloadUrl, isNull);

      final seen = <JobStatus>[];
      final stream = repository.watchExport(requested.id);

      // The server moves the job on while the client is watching it.
      var reads = 0;
      final done = stream.listen((export) {
        seen.add(export.status);
        reads++;
        if (reads == 1) server.exportStatus = 'running';
        if (reads == 2) {
          server.exportStatus = 'ready';
          server.exportSize = 4 * 1024 * 1024;
        }
      }).asFuture<void>();

      await done;

      expect(seen, [JobStatus.pending, JobStatus.running, JobStatus.ready]);

      final ready = await repository.exportById(requested.id);
      expect(ready.downloadUrl, FakeDataOpsServer.downloadUrl);
      expect(ready.sizeBytes, 4 * 1024 * 1024);
      expect(ready.expiresAt, isNotNull);
    });

    test('a failed export is terminal, so polling stops', () async {
      await repository.requestExport();
      server.exportStatus = 'failed';

      final seen = await repository.watchExport(FakeDataOpsServer.exportId).toList();

      expect(seen, hasLength(1));
      expect(seen.single.status, JobStatus.failed);
      expect(seen.single.downloadUrl, isNull);
    });

    test('a job that never settles gives up instead of polling forever',
        () async {
      await repository.requestExport();

      expect(
        repository.watchExport(FakeDataOpsServer.exportId).toList(),
        throwsA(
          isA<DataOpsException>().having(
            (error) => error.code,
            'code',
            DataOpsException.codePollTimeout,
          ),
        ),
      );
    });

    test('past exports are read from the collection', () async {
      server.history = [FakeDataOpsServer.pastExport()];

      final exports = await repository.exports();

      expect(exports, hasLength(1));
      expect(exports.single.status, JobStatus.ready);
      expect(exports.single.sizeBytes, 2 * 1024 * 1024);
    });
  });

  group('account deletion', () {
    test('scheduling sends the password and answers with the deadline',
        () async {
      final scheduled = await repository.scheduleDeletion(password: 'hunter2');

      expect(scheduled.graceDays, 30);
      expect(
        scheduled.purgeAfter.toUtc(),
        DateTime.parse(FakeDataOpsServer.purgeAfter),
      );
      expect(scheduled.canStillCancel, isTrue);

      final sent = server.requests.last;
      expect(sent.method, 'DELETE');
      expect((sent.data as Map)['password'], 'hunter2');
    });

    test('a wrong password raises the code, not the server sentence', () async {
      server.passwordAccepted = false;

      await expectLater(
        repository.scheduleDeletion(password: 'wrong'),
        throwsA(
          isA<DataOpsException>()
              .having((error) => error.code, 'code', 'incorrect_password')
              .having(
                (error) => error.translationKey,
                'translationKey',
                'error.incorrect_password',
              ),
        ),
      );
    });

    test('cancelling calls the restore endpoint', () async {
      await repository.cancelDeletion();

      expect(server.restoreCalls, 1);
      expect(server.requests.last.path, 'me/restore');
    });

    test('a closed window carries the deadline it names', () async {
      server.deletionScheduled = false;

      await expectLater(
        repository.cancelDeletion(),
        throwsA(
          isA<DataOpsException>().having(
            (error) => error.code,
            'code',
            DataOpsException.codeDeletionNotScheduled,
          ),
        ),
      );
    });
  });

  group('report export', () {
    test('a request is queued, and polling ends at a link', () async {
      final requested = await repository.requestReportExport(
        report: 'cash-flow',
        format: ReportFormat.xlsx,
        locale: 'en',
      );

      expect(requested.status, JobStatus.pending);
      expect(requested.format, ReportFormat.xlsx);
      expect(requested.locale, 'en');

      final body = server.requests.last.data! as Map;
      expect(body['format'], 'xlsx');
      expect(body['locale'], 'en');
      // Queued, because an inline export answers with the file itself.
      expect(body['queued'], isTrue);

      server.reportStatus = 'ready';
      server.reportRows = 128;
      server.reportSize = 32 * 1024;

      final settled =
          await repository.watchReportExport(requested.id).toList();

      expect(settled.last.status, JobStatus.ready);
      expect(settled.last.downloadUrl, FakeDataOpsServer.reportDownloadUrl);
      expect(settled.last.rowCount, 128);
    });

    test('every format the server accepts is offered', () {
      expect(
        ReportFormat.values.map((format) => format.wire),
        ['csv', 'xlsx', 'pdf'],
      );
    });

    test('the middle state is the same whichever word the server uses', () {
      expect(JobStatus.parse('running'), JobStatus.running);
      expect(JobStatus.parse('processing'), JobStatus.running);
      expect(JobStatus.parse('who-knows'), JobStatus.pending);
      expect(JobStatus.ready.isTerminal, isTrue);
      expect(JobStatus.running.isTerminal, isFalse);
    });
  });
}
