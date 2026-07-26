import '../core/money/currency.dart';
import '../core/money/money.dart';
import 'remote/api_client.dart';
import 'remote/api_exception.dart';
import 'remote/coded_failure.dart';
import 'remote/money_codec.dart';

/// Where an employee stands with the payroll, straight off `Employee::STATUS_*`.
enum EmploymentStatus {
  active,
  onLeave,
  ended;

  /// The wire spelling — `on_leave`, not `onLeave`.
  String get wire => switch (this) {
        EmploymentStatus.active => 'active',
        EmploymentStatus.onLeave => 'on_leave',
        EmploymentStatus.ended => 'ended',
      };

  /// An unknown status from a newer server reads as ended rather than active:
  /// guessing "employable" would put someone into a run the server will refuse.
  static EmploymentStatus parse(String? raw) => EmploymentStatus.values
      .firstWhere((s) => s.wire == raw, orElse: () => EmploymentStatus.ended);
}

/// What a compensation amount is *per*. The run prorates from this.
enum PayPeriodKind {
  monthly,
  annual,
  weekly,
  daily;

  static PayPeriodKind parse(String? raw) => PayPeriodKind.values
      .firstWhere((p) => p.name == raw, orElse: () => PayPeriodKind.monthly);
}

/// draft → approved → paid, one way only.
///
/// Approving posts to the ledger; a draft has posted nothing; a paid run is
/// history. The order of the values is the order of the machine, and the UI
/// reads it that way rather than hard-coding the sequence a second time.
enum PayrollRunStatus {
  draft,
  approved,
  paid;

  static PayrollRunStatus parse(String? raw) => PayrollRunStatus.values
      .firstWhere((s) => s.name == raw, orElse: () => PayrollRunStatus.draft);

  bool get isDraft => this == PayrollRunStatus.draft;
  bool get isApproved => this == PayrollRunStatus.approved;
  bool get isPaid => this == PayrollRunStatus.paid;
}

/// The three things a payslip line can be.
///
/// The distinction between [deduction] and [contribution] is the whole reason
/// this enum exists: a deduction comes out of the employee's pay, a
/// contribution is the employer's own charge and is *not* subtracted from net.
enum PayslipLineKind {
  earning,
  deduction,
  contribution;

  static PayslipLineKind parse(String? raw) => PayslipLineKind.values
      .firstWhere((k) => k.name == raw, orElse: () => PayslipLineKind.earning);

  /// True only for the kind that actually reduces what the employee receives.
  bool get reducesNet => this == PayslipLineKind.deduction;
}

/// One rate, effective from a date.
///
/// A rate change appends one of these; nothing ever edits the previous row.
/// That is what keeps a run that happened in March explicable in July.
final class CompensationRecord {
  const CompensationRecord({
    required this.id,
    required this.amount,
    required this.period,
    this.effectiveFrom,
    this.effectiveTo,
  });

  final String id;
  final Money amount;
  final PayPeriodKind period;
  final DateTime? effectiveFrom;

  /// Set by the server when a later rate superseded this one. Absent from the
  /// documented schema but present in `EmployeeResource`, so it is read
  /// defensively and simply not shown when missing.
  final DateTime? effectiveTo;

  bool get isCurrent => effectiveTo == null;

  static CompensationRecord fromJson(Map<String, Object?> json) =>
      CompensationRecord(
        id: json['id'] as String? ?? '',
        amount: MoneyCodec.tryDecode(json['amount']) ??
            const Money(0, Currency.irr),
        period: PayPeriodKind.parse(json['period'] as String?),
        effectiveFrom: _dateOf(json['effective_from']),
        effectiveTo: _dateOf(json['effective_to']),
      );
}

final class Employee {
  const Employee({
    required this.id,
    required this.name,
    required this.status,
    this.employeeNumber,
    this.jobTitle,
    this.email,
    this.phone,
    this.country,
    this.hasEnded = false,
    this.hasNationalId = false,
    this.startedOn,
    this.endedOn,
    this.compensation,
    this.compensations = const [],
    this.notes,
  });

  final String id;
  final String name;
  final EmploymentStatus status;
  final String? employeeNumber;
  final String? jobTitle;
  final String? email;
  final String? phone;
  final String? country;
  final bool hasEnded;

  /// The national identifier is encrypted at rest and never leaves the server;
  /// this flag is all a client is told, and all it needs.
  final bool hasNationalId;

  final DateTime? startedOn;
  final DateTime? endedOn;

  /// The rate in force today, or on the end date for a leaver.
  final CompensationRecord? compensation;

  /// The full history, newest first. Only populated when the server loaded it.
  final List<CompensationRecord> compensations;

  final String? notes;

  /// Someone with no rate on file is skipped by a run until one exists, which
  /// is worth saying on the screen rather than discovering in a payslip.
  bool get hasNoRate => compensation == null;

  static Employee fromJson(Map<String, Object?> json) {
    final history = [
      for (final item in json['compensations'] as List? ?? const [])
        if (item is Map) CompensationRecord.fromJson(item.cast<String, Object?>()),
    ]..sort((a, b) {
        final left = a.effectiveFrom;
        final right = b.effectiveFrom;
        if (left == null || right == null) return 0;
        return right.compareTo(left);
      });

    return Employee(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      status: EmploymentStatus.parse(json['status'] as String?),
      employeeNumber: _text(json['employee_number']),
      jobTitle: _text(json['job_title']),
      email: _text(json['email']),
      phone: _text(json['phone']),
      country: _text(json['country']),
      hasEnded: json['has_ended'] == true,
      hasNationalId: json['has_national_id'] == true,
      startedOn: _dateOf(json['started_on']),
      endedOn: _dateOf(json['ended_on']),
      compensation: json['compensation'] is Map
          ? CompensationRecord.fromJson(
              (json['compensation']! as Map).cast<String, Object?>(),
            )
          : null,
      compensations: history,
      notes: _text(json['notes']),
    );
  }
}

/// The fields `POST /employees` and `PATCH /employees/{id}` share.
///
/// Pay is deliberately absent: the server refuses to edit a rate here, because
/// a rate change is an append, not an edit.
final class EmployeeDraft {
  const EmployeeDraft({
    this.name,
    this.employeeNumber,
    this.jobTitle,
    this.email,
    this.phone,
    this.country,
    this.status,
    this.startedOn,
    this.endedOn,
    this.notes,
  });

  final String? name;
  final String? employeeNumber;
  final String? jobTitle;
  final String? email;
  final String? phone;
  final String? country;
  final EmploymentStatus? status;
  final DateTime? startedOn;
  final DateTime? endedOn;
  final String? notes;

  /// Only the fields that were actually given. Sending `null` for an untouched
  /// field on a PATCH would clear it.
  Map<String, Object?> toJson() => {
        if (name != null) 'name': name,
        if (employeeNumber != null) 'employee_number': employeeNumber,
        if (jobTitle != null) 'job_title': jobTitle,
        if (email != null) 'email': email,
        if (phone != null) 'phone': phone,
        if (country != null) 'country': country!.toUpperCase(),
        if (status != null) 'status': status!.wire,
        if (startedOn != null) 'started_on': isoDate(startedOn!),
        if (endedOn != null) 'ended_on': isoDate(endedOn!),
        if (notes != null) 'notes': notes,
      };
}

/// A new rate. `amount` is an integer count of minor units, never a decimal.
final class CompensationDraft {
  const CompensationDraft({
    required this.amountMinorUnits,
    required this.currency,
    this.period = PayPeriodKind.monthly,
    this.effectiveFrom,
  });

  final int amountMinorUnits;
  final Currency currency;
  final PayPeriodKind period;
  final DateTime? effectiveFrom;

  Map<String, Object?> toJson() => {
        'amount': amountMinorUnits,
        'currency': currency.code,
        'period': period.name,
        if (effectiveFrom != null) 'effective_from': isoDate(effectiveFrom!),
      };
}

final class PayslipLine {
  const PayslipLine({
    required this.id,
    required this.kind,
    required this.amount,
    this.code,
    this.label,
    this.rate,
    this.sortOrder = 0,
  });

  final String id;
  final PayslipLineKind kind;
  final Money amount;
  final String? code;
  final String? label;

  /// Percentage, when the line came from a rate rather than a fixed amount.
  final double? rate;
  final int sortOrder;

  static PayslipLine fromJson(Map<String, Object?> json) => PayslipLine(
        id: json['id'] as String? ?? '',
        kind: PayslipLineKind.parse(json['kind'] as String?),
        amount:
            MoneyCodec.tryDecode(json['amount']) ?? const Money(0, Currency.irr),
        code: _text(json['code']),
        label: _text(json['label']),
        rate: _rate(json['rate']),
        sortOrder: json['sort_order'] as int? ?? 0,
      );
}

final class Payslip {
  const Payslip({
    required this.id,
    required this.payrollRunId,
    required this.employeeId,
    required this.gross,
    required this.deductions,
    required this.contributions,
    required this.net,
    required this.employerCost,
    this.employeeName = '',
    this.employeeJobTitle,
    this.periodDays = 0,
    this.workedDays = 0,
    this.isProrated = false,
    this.country,
    this.taxRulesName,
    this.lines = const [],
  });

  final String id;
  final String payrollRunId;
  final String employeeId;

  /// Every total below is the server's. Nothing on this side re-derives net
  /// from the lines — the server is the authority and the arithmetic is
  /// already reconciled there.
  final Money gross;
  final Money deductions;
  final Money contributions;
  final Money net;
  final Money employerCost;

  final String employeeName;
  final String? employeeJobTitle;
  final int periodDays;
  final int workedDays;
  final bool isProrated;
  final String? country;

  /// The rule set that produced this payslip, recorded here so a later change
  /// to the rules cannot silently restate a run that has already happened.
  final String? taxRulesName;

  final List<PayslipLine> lines;

  List<PayslipLine> linesOf(PayslipLineKind kind) =>
      [for (final line in lines) if (line.kind == kind) line]
        ..sort((a, b) => a.sortOrder.compareTo(b.sortOrder));

  static Payslip fromJson(Map<String, Object?> json) {
    final currency = MoneyCodec.tryDecode(json['gross'])?.currency;
    final employee =
        (json['employee'] as Map?)?.cast<String, Object?>() ?? const {};

    Money money(String key) =>
        MoneyCodec.tryDecode(json[key], fallbackCurrency: currency) ??
        Money(0, currency ?? Currency.irr);

    return Payslip(
      id: json['id'] as String? ?? '',
      payrollRunId: json['payroll_run_id'] as String? ?? '',
      employeeId: json['employee_id'] as String? ?? '',
      gross: money('gross'),
      deductions: money('deductions'),
      contributions: money('contributions'),
      net: money('net'),
      employerCost: money('employer_cost'),
      employeeName: employee['name'] as String? ?? '',
      employeeJobTitle: _text(employee['job_title']),
      periodDays: json['period_days'] as int? ?? 0,
      workedDays: json['worked_days'] as int? ?? 0,
      isProrated: json['is_prorated'] == true,
      country: _text(json['country']),
      taxRulesName: _text(json['tax_rules_name']),
      lines: [
        for (final item in json['lines'] as List? ?? const [])
          if (item is Map) PayslipLine.fromJson(item.cast<String, Object?>()),
      ],
    );
  }
}

final class PayrollRun {
  const PayrollRun({
    required this.id,
    required this.status,
    required this.gross,
    required this.deductions,
    required this.contributions,
    required this.net,
    required this.employerCost,
    this.reference,
    this.periodStart,
    this.periodEnd,
    this.payDate,
    this.accountId,
    this.categoryId,
    this.isPosted = false,
    this.netTransactionId,
    this.liabilityTransactionId,
    this.approvedAt,
    this.paidAt,
    this.payslipCount,
    this.payslips = const [],
    this.notes,
  });

  final String id;
  final PayrollRunStatus status;

  /// Server totals, in integer minor units. `net` is `gross - deductions`;
  /// contributions are the employer's and live in `employerCost`.
  final Money gross;
  final Money deductions;
  final Money contributions;
  final Money net;
  final Money employerCost;

  final String? reference;
  final DateTime? periodStart;
  final DateTime? periodEnd;
  final DateTime? payDate;
  final String? accountId;
  final String? categoryId;

  /// False for a draft: nothing has reached the ledger yet.
  final bool isPosted;
  final String? netTransactionId;
  final String? liabilityTransactionId;
  final DateTime? approvedAt;
  final DateTime? paidAt;

  /// Absent from a list response — `PayrollRunResource` only emits it when the
  /// payslips relation was loaded, which `index` does not do.
  final int? payslipCount;

  final List<Payslip> payslips;
  final String? notes;

  /// What the UI may *offer*, not merely what the server will tolerate.
  bool get canApprove => status.isDraft;
  bool get canPay => status.isApproved;
  bool get canDelete => status.isDraft;

  /// A paid run is history: no action on it exists at all.
  bool get isImmutable => status.isPaid;

  int get slipCount => payslipCount ?? payslips.length;

  static PayrollRun fromJson(Map<String, Object?> json) {
    final currency = MoneyCodec.tryDecode(json['net'])?.currency;

    Money money(String key) =>
        MoneyCodec.tryDecode(json[key], fallbackCurrency: currency) ??
        Money(0, currency ?? Currency.irr);

    return PayrollRun(
      id: json['id'] as String? ?? '',
      status: PayrollRunStatus.parse(json['status'] as String?),
      gross: money('gross'),
      deductions: money('deductions'),
      contributions: money('contributions'),
      net: money('net'),
      employerCost: money('employer_cost'),
      reference: _text(json['reference']),
      periodStart: _dateOf(json['period_start']),
      periodEnd: _dateOf(json['period_end']),
      payDate: _dateOf(json['pay_date']),
      accountId: _text(json['account_id']),
      categoryId: _text(json['category_id']),
      isPosted: json['is_posted'] == true,
      netTransactionId: _text(json['net_transaction_id']),
      liabilityTransactionId: _text(json['liability_transaction_id']),
      approvedAt: _dateOf(json['approved_at']),
      paidAt: _dateOf(json['paid_at']),
      payslipCount: json['payslip_count'] as int?,
      payslips: [
        for (final item in json['payslips'] as List? ?? const [])
          if (item is Map) Payslip.fromJson(item.cast<String, Object?>()),
      ],
      notes: _text(json['notes']),
    );
  }
}

/// What `POST /payroll/runs` needs to calculate a draft.
final class PayrollRunDraft {
  const PayrollRunDraft({
    required this.periodStart,
    required this.periodEnd,
    required this.accountId,
    this.reference,
    this.payDate,
    this.currency,
    this.categoryId,
    this.employeeIds = const [],
    this.notes,
  });

  final DateTime periodStart;
  final DateTime periodEnd;
  final String accountId;
  final String? reference;
  final DateTime? payDate;
  final Currency? currency;
  final String? categoryId;

  /// Empty means everyone employed during the period. A leaver is excluded
  /// either way — the server decides that, not this list.
  final List<String> employeeIds;
  final String? notes;

  Map<String, Object?> toJson() => {
        'period_start': isoDate(periodStart),
        'period_end': isoDate(periodEnd),
        'account_id': accountId,
        if (reference != null && reference!.isNotEmpty) 'reference': reference,
        if (payDate != null) 'pay_date': isoDate(payDate!),
        if (currency != null) 'currency': currency!.code,
        if (categoryId != null && categoryId!.isNotEmpty)
          'category_id': categoryId,
        if (employeeIds.isNotEmpty) 'employee_ids': employeeIds,
        if (notes != null && notes!.isNotEmpty) 'notes': notes,
      };
}

final class TaxBracket {
  const TaxBracket({required this.rate, this.upTo});

  /// Percentage.
  final double rate;

  /// Upper bound in integer minor units. Null is the top bracket.
  final int? upTo;

  static TaxBracket fromJson(Map<String, Object?> json) => TaxBracket(
        rate: _rate(json['rate']) ?? 0,
        upTo: json['up_to'] as int?,
      );

  Map<String, Object?> toJson() => {
        'rate': rate,
        if (upTo != null) 'up_to': upTo,
      };
}

final class ContributionRule {
  const ContributionRule({
    required this.code,
    required this.rate,
    this.label,
    this.cap,
  });

  final String code;

  /// Percentage.
  final double rate;
  final String? label;

  /// Integer minor units.
  final int? cap;

  static ContributionRule fromJson(Map<String, Object?> json) =>
      ContributionRule(
        code: json['code'] as String? ?? '',
        rate: _rate(json['rate']) ?? 0,
        label: _text(json['label']),
        cap: json['cap'] as int?,
      );

  Map<String, Object?> toJson() => {
        'code': code,
        'rate': rate,
        if (label != null && label!.isNotEmpty) 'label': label,
        if (cap != null) 'cap': cap,
      };
}

final class FixedDeductionRule {
  const FixedDeductionRule({
    required this.code,
    required this.amountMinorUnits,
    this.label,
  });

  final String code;
  final int amountMinorUnits;
  final String? label;

  static FixedDeductionRule fromJson(Map<String, Object?> json) =>
      FixedDeductionRule(
        code: json['code'] as String? ?? '',
        amountMinorUnits: json['amount'] as int? ?? 0,
        label: _text(json['label']),
      );

  Map<String, Object?> toJson() => {
        'code': code,
        'amount': amountMinorUnits,
        if (label != null && label!.isNotEmpty) 'label': label,
      };
}

/// What the brackets are applied to.
enum TaxBase {
  gross,
  grossLessEmployeeContributions;

  String get wire => switch (this) {
        TaxBase.gross => 'gross',
        TaxBase.grossLessEmployeeContributions =>
          'gross_less_employee_contributions',
      };

  static TaxBase parse(String? raw) =>
      TaxBase.values.firstWhere((b) => b.wire == raw, orElse: () => TaxBase.gross);
}

/// Brackets, contributions and fixed deductions as data, per country.
final class TaxRuleSet {
  const TaxRuleSet({
    required this.id,
    required this.country,
    this.name,
    this.currency,
    this.effectiveFrom,
    this.isActive = false,
    this.taxBase = TaxBase.gross,
    this.brackets = const [],
    this.employeeContributions = const [],
    this.employerContributions = const [],
    this.fixedDeductions = const [],
  });

  final String id;
  final String country;
  final String? name;
  final Currency? currency;
  final DateTime? effectiveFrom;
  final bool isActive;
  final TaxBase taxBase;
  final List<TaxBracket> brackets;
  final List<ContributionRule> employeeContributions;
  final List<ContributionRule> employerContributions;
  final List<FixedDeductionRule> fixedDeductions;

  static TaxRuleSet fromJson(Map<String, Object?> json) {
    final rules = (json['rules'] as Map?)?.cast<String, Object?>() ?? const {};

    List<T> listOf<T>(String key, T Function(Map<String, Object?>) parse) => [
          for (final item in rules[key] as List? ?? const [])
            if (item is Map) parse(item.cast<String, Object?>()),
        ];

    final code = _text(json['currency']);

    return TaxRuleSet(
      id: json['id'] as String? ?? '',
      country: (json['country'] as String? ?? '').toUpperCase(),
      name: _text(json['name']),
      currency: code == null ? null : Currency.byCode(code),
      effectiveFrom: _dateOf(json['effective_from']),
      isActive: json['is_active'] == true,
      taxBase: TaxBase.parse(rules['tax_base'] as String?),
      brackets: listOf('brackets', TaxBracket.fromJson),
      employeeContributions:
          listOf('employee_contributions', ContributionRule.fromJson),
      employerContributions:
          listOf('employer_contributions', ContributionRule.fromJson),
      fixedDeductions: listOf('fixed_deductions', FixedDeductionRule.fromJson),
    );
  }
}

final class TaxRuleDraft {
  const TaxRuleDraft({
    required this.country,
    this.name,
    this.currency,
    this.effectiveFrom,
    this.isActive = true,
    this.taxBase = TaxBase.gross,
    this.brackets = const [],
    this.employeeContributions = const [],
    this.employerContributions = const [],
    this.fixedDeductions = const [],
  });

  final String country;
  final String? name;
  final Currency? currency;
  final DateTime? effectiveFrom;
  final bool isActive;
  final TaxBase taxBase;
  final List<TaxBracket> brackets;
  final List<ContributionRule> employeeContributions;
  final List<ContributionRule> employerContributions;
  final List<FixedDeductionRule> fixedDeductions;

  Map<String, Object?> toJson() => {
        'country': country.toUpperCase(),
        if (name != null && name!.isNotEmpty) 'name': name,
        if (currency != null) 'currency': currency!.code,
        if (effectiveFrom != null) 'effective_from': isoDate(effectiveFrom!),
        'is_active': isActive,
        'rules': {
          'tax_base': taxBase.wire,
          'brackets': [for (final b in brackets) b.toJson()],
          'employee_contributions': [
            for (final c in employeeContributions) c.toJson(),
          ],
          'employer_contributions': [
            for (final c in employerContributions) c.toJson(),
          ],
          'fixed_deductions': [for (final d in fixedDeductions) d.toJson()],
        },
      };
}

/// Every refusal `PayrollException` (PHP) can raise, plus the transport codes,
/// reduced to a code the UI translates.
///
/// The codes below are the ones the module actually throws. Note that the
/// documented names in `docs/openapi.yaml` (`payroll_run_immutable`,
/// `payroll_run_not_approvable`, `payroll_run_not_payable`) are not the ones on
/// the wire; both spellings are carried so a doc-shaped server and the real one
/// read the same on screen.
final class PayrollException extends CodedFailure {
  const PayrollException(super.code, {super.statusCode});

  static const runIsPaid = 'payroll_run_is_paid';
  static const runNotApproved = 'payroll_run_not_approved';
  static const runNotADraft = 'payroll_run_not_a_draft';
  static const runHasNoEmployees = 'payroll_run_has_no_employees';
  static const missingCompensation = 'missing_compensation';
  static const taxRulesNotFound = 'tax_rules_not_found';

  /// Raised before a request is attempted, when this build has no server.
  static const noBackend = 'payroll_unavailable';
}

/// Employees, their pay history, payroll runs, payslips and tax rules.
///
/// A thin mapping over `modules/Payroll/Routes/api.php`. Nothing is cached and
/// nothing is computed: every total on a payroll screen is the integer the
/// server sent, because re-deriving net on the client is how a payslip and the
/// ledger start disagreeing.
final class PayrollRepository {
  const PayrollRepository({required this.client});

  final ApiClient client;

  // --------------------------------------------------------------- employees

  Future<List<Employee>> employees({
    EmploymentStatus? status,
    String? search,
    int perPage = 100,
  }) async {
    final response = await _guard(
      () => client.get('/employees', query: {
        'per_page': perPage,
        if (status != null) 'status': status.wire,
        if (search != null && search.trim().isNotEmpty) 'q': search.trim(),
      },),
    );

    return _listOf(response, Employee.fromJson);
  }

  Future<Employee> employee(String id) async {
    final response = await _guard(() => client.get('/employees/$id'));
    return Employee.fromJson(_data(response));
  }

  /// The optional [compensation] block sets the opening rate. Without one the
  /// employee exists but is skipped by every run until a rate is recorded.
  Future<Employee> createEmployee(
    EmployeeDraft draft, {
    CompensationDraft? compensation,
  }) async {
    final response = await _guard(
      () => client.post('/employees', body: {
        ...draft.toJson(),
        if (compensation != null) 'compensation': compensation.toJson(),
      },),
    );

    return Employee.fromJson(_data(response));
  }

  Future<Employee> updateEmployee(String id, EmployeeDraft draft) async {
    final response = await _guard(
      () => client.patch('/employees/$id', body: draft.toJson()),
    );

    return Employee.fromJson(_data(response));
  }

  /// Appends a rate. The previous one is left exactly as it was, which is what
  /// keeps a run from last quarter explicable after a raise.
  Future<Employee> setCompensation(String id, CompensationDraft draft) async {
    final response = await _guard(
      () => client.post('/employees/$id/compensation', body: draft.toJson()),
    );

    return Employee.fromJson(_data(response));
  }

  // --------------------------------------------------------------- tax rules

  Future<List<TaxRuleSet>> taxRules({String? country}) async {
    final response = await _guard(
      () => client.get('/payroll/tax-rules', query: {
        if (country != null && country.isNotEmpty)
          'country': country.toUpperCase(),
      },),
    );

    return _listOf(response, TaxRuleSet.fromJson);
  }

  Future<TaxRuleSet> createTaxRuleSet(TaxRuleDraft draft) async {
    final response = await _guard(
      () => client.post('/payroll/tax-rules', body: draft.toJson()),
    );

    return TaxRuleSet.fromJson(_data(response));
  }

  // -------------------------------------------------------------------- runs

  Future<List<PayrollRun>> runs({
    PayrollRunStatus? status,
    DateTime? from,
    int perPage = 100,
  }) async {
    final response = await _guard(
      () => client.get('/payroll/runs', query: {
        'per_page': perPage,
        if (status != null) 'status': status.name,
        if (from != null) 'from': isoDate(from),
      },),
    );

    return _listOf(response, PayrollRun.fromJson);
  }

  /// Calculates every payslip and posts nothing.
  Future<PayrollRun> createRun(PayrollRunDraft draft) async {
    final response = await _guard(
      () => client.post('/payroll/runs', body: draft.toJson()),
    );

    return PayrollRun.fromJson(_data(response));
  }

  Future<PayrollRun> run(String id) async {
    final response = await _guard(() => client.get('/payroll/runs/$id'));
    return PayrollRun.fromJson(_data(response));
  }

  /// The step that reaches the ledger. Idempotent on the server: approving an
  /// already-approved run returns it with the same transaction ids.
  Future<PayrollRun> approveRun(String id) async {
    final response = await _guard(
      () => client.post('/payroll/runs/$id/approve'),
    );

    return PayrollRun.fromJson(_data(response));
  }

  /// approved → paid.
  ///
  /// The date goes out under both names on purpose: `docs/openapi.yaml`
  /// documents `pay_date`, while `PayrollRunController::pay` reads `paid_at`.
  /// Sending one would work against exactly one of them.
  Future<PayrollRun> payRun(String id, {DateTime? payDate}) async {
    final response = await _guard(
      () => client.post('/payroll/runs/$id/pay', body: {
        if (payDate != null) ...{
          'pay_date': isoDate(payDate),
          'paid_at': isoDate(payDate),
        },
      },),
    );

    return PayrollRun.fromJson(_data(response));
  }

  /// Only a draft. An approved run is in the ledger, and the way back from the
  /// ledger is a reversing entry, not a delete.
  Future<void> deleteRun(String id) =>
      _guard(() => client.delete('/payroll/runs/$id'));

  Future<Payslip> payslip(String id) async {
    final response = await _guard(() => client.get('/payroll/payslips/$id'));
    return Payslip.fromJson(_data(response));
  }

  static Future<T> _guard<T>(Future<T> Function() call) async {
    try {
      return await call();
    } on ApiException catch (error) {
      throw PayrollException(error.code, statusCode: error.statusCode);
    }
  }

  static Map<String, Object?> _data(Map<String, Object?> response) =>
      (response['data'] as Map?)?.cast<String, Object?>() ?? const {};

  static List<T> _listOf<T>(
    Map<String, Object?> response,
    T Function(Map<String, Object?>) parse,
  ) =>
      [
        for (final item in response['data'] as List? ?? const [])
          if (item is Map) parse(item.cast<String, Object?>()),
      ];
}

/// `2026-07-01` — the only date shape the API accepts on the way in.
String isoDate(DateTime date) => '${date.year.toString().padLeft(4, '0')}-'
    '${date.month.toString().padLeft(2, '0')}-'
    '${date.day.toString().padLeft(2, '0')}';

DateTime? _dateOf(Object? raw) =>
    raw is String && raw.isNotEmpty ? DateTime.tryParse(raw) : null;

String? _text(Object? raw) {
  if (raw is! String) return null;
  final trimmed = raw.trim();
  return trimmed.isEmpty ? null : trimmed;
}

/// A percentage arrives as a JSON number, but a driver that keeps decimals as
/// strings would send `"20.00"`. Both are read; anything else is no rate.
double? _rate(Object? raw) => switch (raw) {
      final double value => value,
      final int value => value.toDouble(),
      final String value => double.tryParse(value.trim()),
      _ => null,
    };
