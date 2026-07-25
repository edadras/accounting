import '../core/date/date_formatter.dart';
import '../core/money/currency.dart';
import '../core/money/money.dart';
import '../core/money/quantity.dart';

/// Mirrors `Contact::TYPES`.
enum ContactType { customer, supplier, employee, other }

/// Mirrors the `invoices.direction` column.
enum InvoiceDirection { sale, purchase }

/// Mirrors `Invoice::STATUSES`. `voided` renames the backend's `void`.
enum InvoiceStatus { draft, sent, partial, paid, overdue, voided }

final class BusinessContact {
  const BusinessContact({
    required this.id,
    required this.name,
    required this.type,
    this.phone,
    this.email,
    this.taxId,
  });

  final String id;
  final String name;
  final ContactType type;
  final String? phone;
  final String? email;
  final String? taxId;
}

final class InvoiceItem {
  const InvoiceItem({
    required this.description,
    required this.quantity,
    required this.unitPrice,
    required this.lineTotal,
    required this.tax,
    this.discount,
    this.taxRatePercent = 0,
  });

  final String description;
  final Quantity quantity;
  final Money unitPrice;

  /// Quantity × unit price, *before* any discount — the backend's invariant is
  /// that these sum to the invoice subtotal.
  final Money lineTotal;
  final Money tax;
  final Money? discount;
  final int taxRatePercent;

  Money get net =>
      discount == null ? lineTotal : lineTotal - discount!;
}

final class Invoice {
  const Invoice({
    required this.id,
    required this.number,
    required this.direction,
    required this.status,
    required this.issueDate,
    required this.subtotal,
    required this.discount,
    required this.tax,
    required this.total,
    required this.paid,
    required this.items,
    this.contactId,
    this.dueDate,
  });

  final String id;
  final String number;
  final InvoiceDirection direction;
  final InvoiceStatus status;
  final DateTime issueDate;
  final DateTime? dueDate;
  final Money subtotal;
  final Money discount;
  final Money tax;
  final Money total;
  final Money paid;
  final List<InvoiceItem> items;
  final String? contactId;

  Money get outstanding {
    final left = total - paid;
    return left.isNegative ? Money(0, left.currency) : left;
  }

  /// The backend's `isPastDue()`: a due date in the past on an invoice that is
  /// neither settled nor void.
  bool isOverdueOn(DateTime now) {
    final due = dueDate;
    if (due == null) return false;
    if (status == InvoiceStatus.paid || status == InvoiceStatus.voided) {
      return false;
    }
    return DateFormatter.daysUntil(due, now) < 0;
  }

  int? daysOverdue(DateTime now) {
    if (!isOverdueOn(now)) return null;
    return -DateFormatter.daysUntil(dueDate!, now);
  }

  /// What the user should see, which is not always what the row stores — an
  /// invoice can go past due without anyone having run the nightly job.
  InvoiceStatus effectiveStatus(DateTime now) =>
      isOverdueOn(now) ? InvoiceStatus.overdue : status;
}

final class BusinessLedger {
  const BusinessLedger({
    required this.currency,
    required this.invoices,
    required this.contacts,
  });

  final Currency currency;
  final List<Invoice> invoices;
  final List<BusinessContact> contacts;

  BusinessContact? contactById(String? id) {
    if (id == null) return null;
    for (final contact in contacts) {
      if (contact.id == id) return contact;
    }
    return null;
  }

  /// Void invoices are excluded from every total, matching `scopeCountable()`.
  Iterable<Invoice> get countable =>
      invoices.where((i) => i.status != InvoiceStatus.voided);

  Money get invoiced =>
      countable.fold(Money(0, currency), (sum, i) => sum + i.total);

  Money get received =>
      countable.fold(Money(0, currency), (sum, i) => sum + i.paid);

  Money get outstanding =>
      countable.fold(Money(0, currency), (sum, i) => sum + i.outstanding);

  Money overdueTotal(DateTime now) => countable
      .where((i) => i.isOverdueOn(now))
      .fold(Money(0, currency), (sum, i) => sum + i.outstanding);

  Money outstandingFor(String contactId) => countable
      .where((i) => i.contactId == contactId)
      .fold(Money(0, currency), (sum, i) => sum + i.outstanding);

  /// Status → invoices, in the order a person works a receivables list:
  /// overdue first, then what is out, then what is settled.
  Map<InvoiceStatus, List<Invoice>> groupedByStatus(DateTime now) {
    const order = [
      InvoiceStatus.overdue,
      InvoiceStatus.partial,
      InvoiceStatus.sent,
      InvoiceStatus.draft,
      InvoiceStatus.paid,
      InvoiceStatus.voided,
    ];

    final grouped = <InvoiceStatus, List<Invoice>>{};
    for (final status in order) {
      final matching = [
        for (final invoice in invoices)
          if (invoice.effectiveStatus(now) == status) invoice,
      ]..sort((a, b) => b.issueDate.compareTo(a.issueDate));
      if (matching.isNotEmpty) grouped[status] = matching;
    }
    return grouped;
  }
}
