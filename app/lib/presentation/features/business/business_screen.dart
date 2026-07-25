import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/date/date_formatter.dart';
import '../../../core/i18n/app_locale.dart';
import '../../../core/i18n/translator.dart';
import '../../../core/money/money_formatter.dart';
import '../../../core/theme/neon_palette.dart';
import '../../../data/modules_repository.dart';
import '../../../domain/business.dart';
import '../../widgets/neon_widgets.dart';
import '../more/module_scaffold.dart';
import 'invoice_detail_screen.dart';

String invoiceStatusKey(InvoiceStatus status) => switch (status) {
      InvoiceStatus.draft => 'biz.stDraft',
      InvoiceStatus.sent => 'biz.stSent',
      InvoiceStatus.partial => 'biz.stPartial',
      InvoiceStatus.paid => 'biz.stPaid',
      InvoiceStatus.overdue => 'biz.stOverdue',
      InvoiceStatus.voided => 'biz.stVoid',
    };

Color invoiceStatusAccent(InvoiceStatus status) => switch (status) {
      InvoiceStatus.overdue => NeonPalette.magenta,
      InvoiceStatus.partial => NeonPalette.amber,
      InvoiceStatus.paid => NeonPalette.lime,
      InvoiceStatus.sent => NeonPalette.cyan,
      InvoiceStatus.draft => NeonPalette.textSecondary,
      InvoiceStatus.voided => NeonPalette.textMuted,
    };

String contactTypeKey(ContactType type) => switch (type) {
      ContactType.customer => 'biz.typeCustomer',
      ContactType.supplier => 'biz.typeSupplier',
      ContactType.employee => 'biz.typeEmployee',
      ContactType.other => 'biz.typeOther',
    };

class BusinessScreen extends ConsumerStatefulWidget {
  const BusinessScreen({super.key});

  @override
  ConsumerState<BusinessScreen> createState() => _BusinessScreenState();
}

class _BusinessScreenState extends ConsumerState<BusinessScreen> {
  bool _showContacts = false;

  @override
  Widget build(BuildContext context) {
    final t = ref.watch(translatorProvider);
    final locale = ref.watch(localeProvider);
    final now = ref.watch(clockProvider);
    final ledger = ref.watch(businessLedgerProvider);

    return ModulePage(
      title: t('module.business'),
      subtitle: t('module.businessHint'),
      child: Column(
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 12),
            child: Row(
              children: [
                NeonChip(
                  label: t('biz.invoices'),
                  selected: !_showContacts,
                  onTap: () => setState(() => _showContacts = false),
                ),
                const SizedBox(width: 10),
                NeonChip(
                  label: t('biz.contacts'),
                  accent: NeonPalette.violet,
                  selected: _showContacts,
                  onTap: () => setState(() => _showContacts = true),
                ),
              ],
            ),
          ),
          Expanded(
            child: ledger.when(
              loading: () => const ModuleLoading(),
              error: (error, _) => ModuleError(message: t('common.error')),
              data: (data) => _showContacts
                  ? _ContactsList(ledger: data, localeCode: locale.code)
                  : _InvoicesList(ledger: data, now: now, locale: locale),
            ),
          ),
        ],
      ),
    );
  }
}

class _InvoicesList extends ConsumerWidget {
  const _InvoicesList({
    required this.ledger,
    required this.now,
    required this.locale,
  });

  final BusinessLedger ledger;
  final DateTime now;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final code = locale.code;
    final grouped = ledger.groupedByStatus(now);

    if (grouped.isEmpty) {
      return ModuleError(message: t('biz.noInvoices'));
    }

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
      children: [
        Row(
          children: [
            Expanded(
              child: StatTile(
                label: t('biz.invoiced'),
                value: MoneyFormatter.format(ledger.invoiced,
                    locale: code, compact: true,),
                accent: NeonPalette.cyan,
                icon: Icons.description_rounded,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: StatTile(
                label: t('biz.outstanding'),
                value: MoneyFormatter.format(ledger.outstanding,
                    locale: code, compact: true,),
                accent: NeonPalette.amber,
                icon: Icons.hourglass_bottom_rounded,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: StatTile(
                label: t('biz.overdueTotal'),
                value: MoneyFormatter.format(ledger.overdueTotal(now),
                    locale: code, compact: true,),
                accent: NeonPalette.magenta,
                icon: Icons.error_outline_rounded,
                glow: !ledger.overdueTotal(now).isZero,
              ),
            ),
          ],
        ),
        const SizedBox(height: 20),
        for (final entry in grouped.entries) ...[
          GroupHeading(
            title: t(invoiceStatusKey(entry.key)),
            accent: invoiceStatusAccent(entry.key),
            glow: entry.key == InvoiceStatus.overdue,
            trailing: DateFormatter.number(entry.value.length, code),
          ),
          for (final invoice in entry.value) ...[
            InvoiceCard(invoice: invoice, ledger: ledger, now: now, locale: locale),
            const SizedBox(height: 10),
          ],
          const SizedBox(height: 8),
        ],
      ],
    );
  }
}

class InvoiceCard extends ConsumerWidget {
  const InvoiceCard({
    super.key,
    required this.invoice,
    required this.ledger,
    required this.now,
    required this.locale,
  });

  final Invoice invoice;
  final BusinessLedger ledger;
  final DateTime now;
  final AppLocale locale;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);
    final code = locale.code;
    final status = invoice.effectiveStatus(now);
    final accent = invoiceStatusAccent(status);
    final contact = ledger.contactById(invoice.contactId);
    final overdueDays = invoice.daysOverdue(now);

    return NeonCardShell(
      accent: accent,
      // Overdue is the only state that costs money to ignore.
      glow: status == InvoiceStatus.overdue,
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => InvoiceDetailScreen(invoice: invoice, contact: contact),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      contact?.name ?? invoice.number,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        color: NeonPalette.textPrimary,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      '${invoice.number} · '
                      '${t(invoice.direction == InvoiceDirection.sale ? 'biz.sale' : 'biz.purchase')}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 11.5,
                        color: NeonPalette.textMuted,
                      ),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    MoneyFormatter.format(invoice.total, locale: code),
                    style: const TextStyle(
                      fontSize: 14.5,
                      fontWeight: FontWeight.w800,
                      color: NeonPalette.textPrimary,
                    ),
                  ),
                  if (!invoice.outstanding.isZero) ...[
                    const SizedBox(height: 3),
                    Text(
                      '${t('biz.outstanding')} '
                      '${MoneyFormatter.format(invoice.outstanding, locale: code, compact: true)}',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: accent,
                      ),
                    ),
                  ],
                ],
              ),
            ],
          ),
          const SizedBox(height: 11),
          Row(
            children: [
              const Icon(Icons.event_rounded,
                  size: 14, color: NeonPalette.textMuted,),
              const SizedBox(width: 6),
              Text(
                invoice.dueDate == null
                    ? DateFormatter.short(invoice.issueDate, locale)
                    : '${t('biz.due')} ${DateFormatter.short(invoice.dueDate!, locale)}',
                style: const TextStyle(
                  fontSize: 11.5,
                  color: NeonPalette.textSecondary,
                ),
              ),
              const Spacer(),
              if (overdueDays != null)
                NeonChip(
                  label: t('biz.overdueBy', args: {
                    'days': DateFormatter.number(overdueDays, code),
                  },),
                  accent: NeonPalette.magenta,
                  selected: true,
                  icon: Icons.priority_high_rounded,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ContactsList extends ConsumerWidget {
  const _ContactsList({required this.ledger, required this.localeCode});

  final BusinessLedger ledger;
  final String localeCode;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = ref.watch(translatorProvider);

    return ListView(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 4, 16, 32),
      children: [
        SectionHeader(title: t('biz.contacts'), accent: NeonPalette.violet),
        for (final contact in ledger.contacts) ...[
          NeonCardShell(
            accent: NeonPalette.violet,
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: NeonPalette.forSeed(contact.id).withValues(alpha: 0.14),
                    border: Border.all(
                      color:
                          NeonPalette.forSeed(contact.id).withValues(alpha: 0.4),
                    ),
                  ),
                  child: Text(
                    contact.name.characters.first,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      color: NeonPalette.forSeed(contact.id),
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        contact.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                          color: NeonPalette.textPrimary,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        [
                          t(contactTypeKey(contact.type)),
                          contact.phone,
                          contact.email,
                        ].whereType<String>().join(' · '),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 11,
                          color: NeonPalette.textMuted,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Text(
                  MoneyFormatter.format(
                    ledger.outstandingFor(contact.id),
                    locale: localeCode,
                    compact: true,
                  ),
                  style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                    color: ledger.outstandingFor(contact.id).isZero
                        ? NeonPalette.textMuted
                        : NeonPalette.amber,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
        ],
      ],
    );
  }
}
