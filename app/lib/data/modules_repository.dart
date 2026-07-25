import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../core/money/quantity.dart';
import '../domain/assets.dart';
import '../domain/banking.dart';
import '../domain/buildings.dart';
import '../domain/business.dart';
import '../domain/investment.dart';
import '../domain/travel.dart';
import 'ledger_repository.dart';

/// Read models for the six vertical modules.
///
/// Kept separate from [LedgerRepository] on purpose: the ledger is the one
/// store every screen depends on, and bolting six unrelated verticals onto its
/// interface would make every consumer recompile for a change to, say, cheques.
abstract interface class ModulesRepository {
  Future<List<Cheque>> cheques();
  Future<List<Loan>> loans();
  Future<Portfolio> portfolio();
  Future<List<FixedAsset>> assets();
  Future<List<Trip>> trips();
  Future<Building> building();
  Future<BusinessLedger> business();
}

/// Seeded in-memory data so every module screen is demonstrable without a
/// server, exactly like [InMemoryLedgerRepository].
///
/// Everything is dated relative to [now] rather than to fixed calendar dates,
/// so a cheque that is meant to read as overdue still reads as overdue next
/// year.
final class InMemoryModulesRepository implements ModulesRepository {
  InMemoryModulesRepository({required this.baseCurrency, required this.now});

  final Currency baseCurrency;
  final DateTime now;

  Money _m(int minorUnits) => Money(minorUnits, baseCurrency);

  DateTime _days(int offset) =>
      DateTime(now.year, now.month, now.day).add(Duration(days: offset));

  DateTime _months(int offset) => DateTime(now.year, now.month + offset, now.day);

  String _period(int monthsAgo) {
    final month = DateTime(now.year, now.month - monthsAgo);
    return '${month.year}-${month.month.toString().padLeft(2, '0')}';
  }

  // ------------------------------------------------------------------ banking

  @override
  Future<List<Cheque>> cheques() async => [
        Cheque(
          id: 'chq-1',
          number: '845221',
          direction: ChequeDirection.issued,
          status: ChequeStatus.issued,
          amount: _m(1250000),
          dueDate: _days(-9),
          partyName: 'شرکت آریا تجارت',
          bankName: 'بانک ملت',
        ),
        Cheque(
          id: 'chq-2',
          number: '845222',
          direction: ChequeDirection.issued,
          status: ChequeStatus.inProgress,
          amount: _m(480000),
          dueDate: _days(4),
          partyName: 'پخش نوین',
          bankName: 'بانک ملت',
        ),
        Cheque(
          id: 'chq-3',
          number: '771902',
          direction: ChequeDirection.received,
          status: ChequeStatus.issued,
          amount: _m(2300000),
          dueDate: _days(21),
          partyName: 'مهندسی رایان',
          bankName: 'بانک سامان',
        ),
        Cheque(
          id: 'chq-4',
          number: '771903',
          direction: ChequeDirection.received,
          status: ChequeStatus.cleared,
          amount: _m(640000),
          dueDate: _days(-30),
          partyName: 'مهندسی رایان',
          bankName: 'بانک سامان',
        ),
        Cheque(
          id: 'chq-5',
          number: '845223',
          direction: ChequeDirection.issued,
          status: ChequeStatus.bounced,
          amount: _m(315000),
          dueDate: _days(-45),
          partyName: 'بازرگانی سپهر',
          bankName: 'بانک ملت',
        ),
        Cheque(
          id: 'chq-6',
          number: '992014',
          direction: ChequeDirection.guarantee,
          status: ChequeStatus.draft,
          amount: _m(5000000),
          dueDate: _days(74),
          partyName: 'شهرداری منطقه ۳',
          bankName: 'بانک تجارت',
        ),
      ];

  @override
  Future<List<Loan>> loans() async => [
        Loan(
          id: 'loan-1',
          title: 'وام مسکن',
          lender: 'بانک ملت',
          principal: _m(24000000),
          annualRatePercent: 18.5,
          interestType: LoanInterestType.compound,
          startDate: _months(-8),
          status: LoanStatus.active,
          schedule: amortize(
            principal: _m(24000000),
            annualRatePercent: 18.5,
            installmentsCount: 36,
            startDate: _months(-8),
            interestType: LoanInterestType.compound,
            paidCount: 8,
          ),
        ),
        Loan(
          id: 'loan-2',
          title: 'وام خودرو',
          lender: 'بانک صادرات',
          principal: _m(9000000),
          annualRatePercent: 12,
          interestType: LoanInterestType.simple,
          startDate: _months(-14),
          status: LoanStatus.active,
          schedule: amortize(
            principal: _m(9000000),
            annualRatePercent: 12,
            installmentsCount: 24,
            startDate: _months(-14),
            interestType: LoanInterestType.simple,
            paidCount: 14,
          ),
        ),
      ];

  // --------------------------------------------------------------- investment

  @override
  Future<Portfolio> portfolio() async {
    return Portfolio(
      currency: baseCurrency,
      positions: [
        InvestmentPosition(
          id: 'inv-gold',
          name: 'طلای آب‌شده',
          kind: InvestmentKind.gold,
          quantity: const Quantity(12500, decimals: 3),
          unitLabelKey: 'invest.unitGram',
          avgBuyPrice: _m(240000),
          currentPrice: _m(278000),
          costBasis: _m(3000000),
          currentValue: _m(3475000),
          realizedProfit: _m(120000),
          trades: [
            InvestmentTrade(
              id: 'trd-g1',
              action: TradeAction.buy,
              quantity: const Quantity(8000, decimals: 3),
              unitPrice: _m(228000),
              fee: _m(4000),
              realizedProfit: _m(0),
              occurredAt: _months(-11),
            ),
            InvestmentTrade(
              id: 'trd-g2',
              action: TradeAction.buy,
              quantity: const Quantity(4500, decimals: 3),
              unitPrice: _m(261000),
              fee: _m(3200),
              realizedProfit: _m(0),
              occurredAt: _months(-5),
            ),
            InvestmentTrade(
              id: 'trd-g3',
              action: TradeAction.sell,
              quantity: const Quantity(2000, decimals: 3),
              unitPrice: _m(302000),
              fee: _m(2600),
              realizedProfit: _m(120000),
              occurredAt: _months(-1),
            ),
          ],
        ),
        InvestmentPosition(
          id: 'inv-btc',
          name: 'بیت‌کوین',
          symbol: 'BTC',
          kind: InvestmentKind.crypto,
          quantity: const Quantity(3180000, decimals: 8),
          unitLabelKey: 'invest.unitCoin',
          avgBuyPrice: _m(294000000),
          currentPrice: _m(261000000),
          costBasis: _m(9349200),
          currentValue: _m(8299800),
          realizedProfit: _m(0),
          trades: [
            InvestmentTrade(
              id: 'trd-b1',
              action: TradeAction.buy,
              quantity: const Quantity(3180000, decimals: 8),
              unitPrice: _m(294000000),
              fee: _m(9300),
              realizedProfit: _m(0),
              occurredAt: _months(-4),
            ),
          ],
        ),
        InvestmentPosition(
          id: 'inv-asels',
          name: 'آسلسان',
          symbol: 'ASELS',
          kind: InvestmentKind.stock,
          quantity: const Quantity(400),
          unitLabelKey: 'invest.unitShare',
          avgBuyPrice: _m(7420),
          currentPrice: _m(9135),
          costBasis: _m(2968000),
          currentValue: _m(3654000),
          realizedProfit: _m(45000),
          trades: [
            InvestmentTrade(
              id: 'trd-a1',
              action: TradeAction.buy,
              quantity: const Quantity(400),
              unitPrice: _m(7420),
              fee: _m(5800),
              realizedProfit: _m(0),
              occurredAt: _months(-9),
            ),
            InvestmentTrade(
              id: 'trd-a2',
              action: TradeAction.dividend,
              quantity: const Quantity(400),
              unitPrice: _m(115),
              fee: _m(1000),
              realizedProfit: _m(45000),
              occurredAt: _months(-2),
            ),
          ],
        ),
        InvestmentPosition(
          id: 'inv-usd',
          name: 'دلار آمریکا',
          symbol: 'USD',
          kind: InvestmentKind.fx,
          quantity: const Quantity(120000, decimals: 2),
          unitLabelKey: 'invest.unitUnit',
          avgBuyPrice: _m(3310),
          currentPrice: _m(3485),
          costBasis: _m(3972000),
          currentValue: _m(4182000),
          realizedProfit: _m(-18000),
          trades: [
            InvestmentTrade(
              id: 'trd-u1',
              action: TradeAction.buy,
              quantity: const Quantity(120000, decimals: 2),
              unitPrice: _m(3310),
              fee: _m(2000),
              realizedProfit: _m(0),
              occurredAt: _months(-6),
            ),
            InvestmentTrade(
              id: 'trd-u2',
              action: TradeAction.fee,
              quantity: const Quantity(0),
              unitPrice: _m(0),
              fee: _m(18000),
              realizedProfit: _m(-18000),
              occurredAt: _months(-3),
            ),
          ],
        ),
      ],
    );
  }

  // ------------------------------------------------------------------- assets

  @override
  Future<List<FixedAsset>> assets() async => [
        FixedAsset(
          id: 'ast-house',
          name: 'آپارتمان ولنجک',
          kind: AssetKind.house,
          purchasePrice: _m(480000000),
          currentValue: _m(615000000),
          salvageValue: _m(0),
          purchaseDate: _months(-72),
          method: DepreciationMethod.none,
          insuranceProvider: 'بیمه پاسارگاد',
          insuranceExpiresAt: _days(203),
        ),
        FixedAsset(
          id: 'ast-car',
          name: 'پژو ۲۰۷',
          kind: AssetKind.car,
          purchasePrice: _m(8500000),
          currentValue: _m(6120000),
          salvageValue: _m(1200000),
          purchaseDate: _months(-36),
          method: DepreciationMethod.declining,
          ratePerMillion: 200000,
          usefulLifeYears: 8,
          insuranceProvider: 'بیمه ایران',
          insuranceExpiresAt: _days(18),
        ),
        FixedAsset(
          id: 'ast-laptop',
          name: 'مک‌بوک پرو',
          kind: AssetKind.other,
          purchasePrice: _m(1650000),
          currentValue: _m(990000),
          salvageValue: _m(150000),
          purchaseDate: _months(-24),
          method: DepreciationMethod.linear,
          usefulLifeYears: 5,
        ),
        FixedAsset(
          id: 'ast-watch',
          name: 'ساعت رولکس',
          kind: AssetKind.watch,
          purchasePrice: _m(22000000),
          currentValue: _m(27500000),
          salvageValue: _m(0),
          purchaseDate: _months(-48),
          method: DepreciationMethod.none,
          insuranceProvider: 'بیمه دی',
          insuranceExpiresAt: _days(-12),
        ),
      ];

  // ------------------------------------------------------------------- travel

  @override
  Future<List<Trip>> trips() async {
    const istanbul = [
      TripMember(id: 'tm-ali', name: 'علی'),
      TripMember(id: 'tm-sara', name: 'سارا'),
      TripMember(id: 'tm-reza', name: 'رضا'),
      TripMember(id: 'tm-mina', name: 'مینا'),
    ];
    final istanbulIds = [for (final m in istanbul) m.id];

    const kish = [
      TripMember(id: 'tk-ali', name: 'علی'),
      TripMember(id: 'tk-nima', name: 'نیما'),
      TripMember(id: 'tk-helia', name: 'هلیا'),
    ];
    final kishIds = [for (final m in kish) m.id];

    return [
      Trip(
        id: 'trip-istanbul',
        name: 'سفر استانبول',
        destination: 'استانبول',
        startsAt: _days(-21),
        endsAt: _days(-14),
        baseCurrency: baseCurrency,
        members: istanbul,
        expenses: [
          SplitExpense(
            id: 'sx-hotel',
            title: 'هتل',
            amount: _m(480000),
            payerId: 'tm-ali',
            participantIds: istanbulIds,
            occurredAt: _days(-21),
          ),
          SplitExpense(
            id: 'sx-flight',
            title: 'بلیت هواپیما',
            amount: _m(600000),
            payerId: 'tm-sara',
            participantIds: istanbulIds,
            occurredAt: _days(-24),
          ),
          SplitExpense(
            id: 'sx-food',
            title: 'رستوران',
            amount: _m(120000),
            payerId: 'tm-reza',
            participantIds: istanbulIds,
            occurredAt: _days(-18),
          ),
          SplitExpense(
            id: 'sx-museum',
            title: 'موزه و گشت',
            amount: _m(40000),
            payerId: 'tm-mina',
            participantIds: istanbulIds,
            occurredAt: _days(-16),
          ),
        ],
      ),
      Trip(
        id: 'trip-kish',
        name: 'سفر کیش',
        destination: 'کیش',
        startsAt: _days(-90),
        endsAt: _days(-84),
        baseCurrency: baseCurrency,
        members: kish,
        expenses: [
          SplitExpense(
            id: 'sk-stay',
            title: 'اقامتگاه',
            amount: _m(270000),
            payerId: 'tk-ali',
            participantIds: kishIds,
            occurredAt: _days(-90),
          ),
          SplitExpense(
            id: 'sk-car',
            title: 'اجاره خودرو',
            amount: _m(90000),
            payerId: 'tk-nima',
            participantIds: kishIds,
            occurredAt: _days(-88),
          ),
        ],
      ),
    ];
  }

  // ---------------------------------------------------------------- buildings

  @override
  Future<Building> building() async {
    const specs = <(String, int, int, int, String, String?)>[
      ('101', 1, 96, 3, 'مهدی رستمی', null),
      ('102', 1, 78, 2, 'زهرا کریمی', 'سعید نوری'),
      ('103', 1, 84, 4, 'حسین مرادی', null),
      ('201', 2, 96, 2, 'فاطمه احمدی', null),
      ('202', 2, 78, 1, 'رضا جعفری', 'مینا شریفی'),
      ('203', 2, 84, 3, 'نگار سلطانی', null),
      ('301', 3, 96, 5, 'کامران بیات', null),
      ('302', 3, 78, 2, 'الهام قاسمی', null),
      ('303', 3, 84, 2, 'بهرام تقوی', 'یاسر عبدی'),
      ('401', 4, 128, 4, 'سمیرا نجفی', null),
      ('402', 4, 128, 3, 'آرش کیانی', null),
      ('403', 4, 92, 0, 'شرکت پارس‌بنا', null),
    ];

    final units = [
      for (final (no, floor, area, residents, owner, tenant) in specs)
        BuildingUnit(
          id: 'unit-$no',
          unitNo: no,
          floor: floor,
          areaSquareMetres: area,
          residentsCount: residents,
          isOccupied: residents > 0,
          ownerName: owner,
          tenantName: tenant,
        ),
    ];

    // Charge is per square metre, matching the building's `per_area` formula.
    const perSquareMetre = 4500;
    final current = _period(0);
    final previous = _period(1);
    final older = _period(2);

    // unitNo → (paid this period, months of arrears)
    const settlement = <String, (int, int)>{
      '101': (100, 0),
      '102': (100, 0),
      '103': (0, 2),
      '201': (100, 0),
      '202': (40, 0),
      '203': (100, 0),
      '301': (0, 1),
      '302': (100, 0),
      '303': (100, 0),
      '401': (0, 3),
      '402': (100, 0),
      '403': (0, 0),
    };

    final charges = <BuildingCharge>[];
    for (final unit in units) {
      final amount = unit.areaSquareMetres * perSquareMetre;
      final (paidPercent, arrearsMonths) = settlement[unit.unitNo]!;
      final paid = amount * paidPercent ~/ 100;

      charges.add(BuildingCharge(
        id: 'chg-${unit.unitNo}-$current',
        unitId: unit.id,
        period: current,
        amount: _m(amount),
        paid: _m(paid),
        status: paid >= amount
            ? ChargeStatus.paid
            : paid > 0
                ? ChargeStatus.partial
                : ChargeStatus.unpaid,
        dueDate: _days(5),
      ),);

      for (var back = 1; back <= arrearsMonths; back++) {
        charges.add(BuildingCharge(
          id: 'chg-${unit.unitNo}-back$back',
          unitId: unit.id,
          period: back == 1 ? previous : older,
          amount: _m(amount),
          paid: _m(0),
          status: ChargeStatus.unpaid,
          dueDate: _days(-25 * back),
        ),);
      }
    }

    return Building(
      id: 'bld-niloofar',
      name: 'برج نیلوفر',
      address: 'تهران، سعادت‌آباد، خیابان ۱۲',
      formula: ChargeFormula.perArea,
      currency: baseCurrency,
      fundBalance: _m(4850000),
      units: units,
      charges: charges,
      currentPeriod: current,
    );
  }

  // ----------------------------------------------------------------- business

  @override
  Future<BusinessLedger> business() async {
    const contacts = [
      BusinessContact(
        id: 'con-arian',
        name: 'آرین سیستم',
        type: ContactType.customer,
        phone: '021-88450012',
        email: 'finance@arian.example',
        taxId: '410298765',
      ),
      BusinessContact(
        id: 'con-dadeh',
        name: 'داده‌پرداز پویا',
        type: ContactType.customer,
        phone: '021-22119080',
        email: 'billing@dadeh.example',
      ),
      BusinessContact(
        id: 'con-raha',
        name: 'تجهیزات فنی رها',
        type: ContactType.supplier,
        phone: '031-36654412',
      ),
      BusinessContact(
        id: 'con-maryam',
        name: 'مریم کاظمی',
        type: ContactType.customer,
        email: 'm.kazemi@example.com',
      ),
    ];

    return BusinessLedger(
      currency: baseCurrency,
      contacts: contacts,
      invoices: [
        _invoice(
          id: 'inv-1',
          number: 'INV-2026-0041',
          contactId: 'con-arian',
          status: InvoiceStatus.sent,
          issueDate: _days(-52),
          dueDate: _days(-22),
          lines: const [
            ('طراحی رابط کاربری', 1, 4800000, 10),
            ('پشتیبانی ماهانه', 3, 650000, 10),
          ],
          paidMinor: 0,
        ),
        _invoice(
          id: 'inv-2',
          number: 'INV-2026-0042',
          contactId: 'con-dadeh',
          status: InvoiceStatus.partial,
          issueDate: _days(-40),
          dueDate: _days(-6),
          lines: const [
            ('توسعه API', 1, 7200000, 10),
          ],
          paidMinor: 3000000,
        ),
        _invoice(
          id: 'inv-3',
          number: 'INV-2026-0043',
          contactId: 'con-maryam',
          status: InvoiceStatus.sent,
          issueDate: _days(-9),
          dueDate: _days(21),
          lines: const [
            ('مشاوره مالی', 6, 320000, 0),
          ],
          paidMinor: 0,
        ),
        _invoice(
          id: 'inv-4',
          number: 'INV-2026-0044',
          contactId: 'con-arian',
          status: InvoiceStatus.paid,
          issueDate: _days(-75),
          dueDate: _days(-45),
          lines: const [
            ('استقرار سامانه', 1, 12500000, 10),
            ('آموزش تیم', 2, 900000, 10),
          ],
          paidMinor: -1,
        ),
        _invoice(
          id: 'inv-5',
          number: 'BIL-2026-0007',
          contactId: 'con-raha',
          direction: InvoiceDirection.purchase,
          status: InvoiceStatus.sent,
          issueDate: _days(-12),
          dueDate: _days(3),
          lines: const [
            ('سرور رک‌مونت', 2, 4150000, 10),
          ],
          paidMinor: 0,
        ),
        _invoice(
          id: 'inv-6',
          number: 'INV-2026-0045',
          contactId: 'con-dadeh',
          status: InvoiceStatus.draft,
          issueDate: _days(-2),
          lines: const [
            ('فاز دوم توسعه', 1, 9600000, 10),
          ],
          paidMinor: 0,
        ),
      ],
    );
  }

  /// Builds an invoice the way `BuildInvoice` does: the line totals sum to the
  /// subtotal, tax is charged per line at that line's own rate, and
  /// `subtotal − discount + tax` equals the total exactly.
  ///
  /// [paidMinor] of −1 means "settled in full", so a paid invoice does not have
  /// to restate a number the lines already determine.
  Invoice _invoice({
    required String id,
    required String number,
    required InvoiceStatus status,
    required DateTime issueDate,
    required List<(String, int, int, int)> lines,
    required int paidMinor,
    String? contactId,
    DateTime? dueDate,
    InvoiceDirection direction = InvoiceDirection.sale,
    int discountMinor = 0,
  }) {
    final items = <InvoiceItem>[];
    var subtotal = 0;
    var tax = 0;

    for (final (description, quantity, unitPrice, taxPercent) in lines) {
      final lineTotal = quantity * unitPrice;
      final lineTax = lineTotal * taxPercent ~/ 100;
      subtotal += lineTotal;
      tax += lineTax;
      items.add(InvoiceItem(
        description: description,
        quantity: Quantity(quantity),
        unitPrice: _m(unitPrice),
        lineTotal: _m(lineTotal),
        tax: _m(lineTax),
        taxRatePercent: taxPercent,
      ),);
    }

    final total = subtotal - discountMinor + tax;

    return Invoice(
      id: id,
      number: number,
      direction: direction,
      status: status,
      issueDate: issueDate,
      dueDate: dueDate,
      contactId: contactId,
      subtotal: _m(subtotal),
      discount: _m(discountMinor),
      tax: _m(tax),
      total: _m(total),
      paid: _m(paidMinor == -1 ? total : paidMinor),
      items: items,
    );
  }
}

/// "Now" as the UI sees it. A single source so a due date and the urgency badge
/// beside it can never disagree, and so a test can pin the clock.
final clockProvider = Provider<DateTime>((ref) => DateTime.now());

final modulesRepositoryProvider = Provider<ModulesRepository>((ref) {
  return InMemoryModulesRepository(
    baseCurrency: ref.watch(baseCurrencyProvider),
    now: ref.watch(clockProvider),
  );
});

final chequesProvider =
    FutureProvider<List<Cheque>>((ref) => ref.watch(modulesRepositoryProvider).cheques());

final loansProvider =
    FutureProvider<List<Loan>>((ref) => ref.watch(modulesRepositoryProvider).loans());

final portfolioProvider =
    FutureProvider<Portfolio>((ref) => ref.watch(modulesRepositoryProvider).portfolio());

final fixedAssetsProvider =
    FutureProvider<List<FixedAsset>>((ref) => ref.watch(modulesRepositoryProvider).assets());

final tripsProvider =
    FutureProvider<List<Trip>>((ref) => ref.watch(modulesRepositoryProvider).trips());

final buildingProvider =
    FutureProvider<Building>((ref) => ref.watch(modulesRepositoryProvider).building());

final businessLedgerProvider =
    FutureProvider<BusinessLedger>((ref) => ref.watch(modulesRepositoryProvider).business());
