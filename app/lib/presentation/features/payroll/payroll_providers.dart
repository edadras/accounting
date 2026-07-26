import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/i18n/translator.dart';
import '../../../data/finora_backend.dart';
import '../../../data/payroll_repository.dart';
import '../../../data/remote/coded_failure.dart';

/// Payroll always talks to the server.
///
/// There is no offline copy on purpose: a payslip read from a stale cache is a
/// statement about money that somebody may already have been paid. A build with
/// no server at all is a state the screens explain rather than crash on.
final payrollRepositoryProvider = Provider<PayrollRepository>((ref) {
  final backend = ref.watch(finoraBackendProvider);
  if (backend == null) {
    throw const PayrollException(PayrollException.noBackend);
  }
  return PayrollRepository(client: backend.client);
});

/// Null means "every status", which is the list the screen opens on.
final employeeFilterProvider = StateProvider<EmploymentStatus?>((ref) => null);

final employeesProvider = FutureProvider.autoDispose<List<Employee>>(
  (ref) => ref
      .watch(payrollRepositoryProvider)
      .employees(status: ref.watch(employeeFilterProvider)),
);

final employeeProvider = FutureProvider.autoDispose.family<Employee, String>(
  (ref, id) => ref.watch(payrollRepositoryProvider).employee(id),
);

final runFilterProvider = StateProvider<PayrollRunStatus?>((ref) => null);

final payrollRunsProvider = FutureProvider.autoDispose<List<PayrollRun>>(
  (ref) => ref
      .watch(payrollRepositoryProvider)
      .runs(status: ref.watch(runFilterProvider)),
);

final payrollRunProvider = FutureProvider.autoDispose.family<PayrollRun, String>(
  (ref, id) => ref.watch(payrollRepositoryProvider).run(id),
);

final payslipProvider = FutureProvider.autoDispose.family<Payslip, String>(
  (ref, id) => ref.watch(payrollRepositoryProvider).payslip(id),
);

final taxRulesProvider = FutureProvider.autoDispose<List<TaxRuleSet>>(
  (ref) => ref.watch(payrollRepositoryProvider).taxRules(),
);

/// The sentence a person reads when payroll refuses.
///
/// The server's own `message` is never rendered — it is English developer
/// prose. The code is resolved in the `payroll.*` namespace first, then through
/// the shared `error.*` keys that cover the transport and the generic 4xx, and
/// only then falls back to a generic line. Nothing can leak the raw key.
String payrollFailureText(Translator t, Object error) {
  if (error is! CodedFailure) return t('payroll.error.unexpected');

  for (final key in ['payroll.error.${error.code}', 'error.${error.code}']) {
    final text = t(key);
    if (text != key) return text;
  }

  return t('payroll.error.unexpected');
}
