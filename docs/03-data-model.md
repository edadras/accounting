# ۰۳ — مدل داده

## ۱. قواعد بنیادی

1. **کلید اصلی: ULID** — قابل تولید در کلاینت (برای Offline-First)، مرتب‌شونده بر اساس زمان.
2. **`workspace_id` روی هر جدول دامنه** — بدون استثنا، با ایندکس ترکیبی.
3. **مبالغ: `BIGINT` در کوچک‌ترین واحد ارز** — هرگز `FLOAT`/`DOUBLE`.
   - `USD 12.34` → `1234` با `minor_unit = 2`
   - `IRR 500000` → `500000` با `minor_unit = 0`
   - `BTC 0.00012345` → `12345` با `minor_unit = 8`
4. **هر مبلغ سه‌گانه ذخیره می‌شود:** `amount` + `currency` + (`fx_rate`, `base_amount`).
5. **`created_at`, `updated_at`, `deleted_at`, `updated_by`, `version`** روی همهٔ جداول دامنه.
6. **`version` (integer افزایشی)** برای حل تعارض در همگام‌سازی.
7. تاریخ‌ها در DB همیشه **UTC**؛ تبدیل به تقویم شمسی/قمری فقط در لایهٔ نمایش.

---

## ۲. جداول هسته

### users
```
id (ULID) · name · email · phone · password · locale · timezone
default_workspace_id · avatar_path · two_factor_secret · two_factor_recovery
email_verified_at · last_active_at · timestamps
```

### workspaces
```
id · owner_id · name · type(personal|business|building|travel|family|store)
base_currency · timezone · calendar(jalali|gregorian|hijri) · locale
settings (JSON) · icon · color · archived_at · timestamps
```

### workspace_members
```
id · workspace_id · user_id · role(owner|admin|accountant|member|viewer)
permissions (JSON overrides) · invited_by · joined_at · timestamps
UNIQUE(workspace_id, user_id)
```

### currencies
```
code (PK, 'USD','IRR','BTC') · name · symbol · minor_unit · type(fiat|crypto|metal)
is_active · display_order
```

### exchange_rates
```
id · base_code · quote_code · rate (DECIMAL(30,12)) · source · rated_at
UNIQUE(base_code, quote_code, rated_at)
```

---

## ۳. دفتر مالی

### accounts
```
id · workspace_id · name · type(cash|bank|card|wallet|fund|petty_cash|crypto|gold|fx)
currency · opening_balance · current_balance (cached) · bank_id · iban · card_number_last4
icon · color · is_archived · sort_order · timestamps
INDEX(workspace_id, type)
```
> `current_balance` یک **cache** است؛ منبع حقیقت همیشه مجموع `entries` است.
> یک Job روزانه صحت آن را بازبینی می‌کند.

### categories
```
id · workspace_id · parent_id · name_key · path ('/food/restaurant')
type(income|expense|both) · icon · color · depth · is_system · sort_order · timestamps
INDEX(workspace_id, path)
```
- عمق نامحدود؛ کوئری زیردرخت با `path LIKE '/food/%'`
- ادغام دسته: انتقال تراکنش‌ها + نگهداری alias

### transactions
```
id · workspace_id · type(income|expense|transfer)
account_id · counter_account_id (برای transfer)
category_id · amount · currency · fx_rate · base_amount
occurred_at · description · notes · merchant_id · project_id
payee · payment_method · reference · tags (JSON)
source(manual|voice|ocr|sms|email|qr|import|recurring|api)
source_meta (JSON) · latitude · longitude · is_reconciled
recurring_rule_id · idempotency_key · version · timestamps · deleted_at
INDEX(workspace_id, occurred_at) · INDEX(workspace_id, category_id)
INDEX(workspace_id, account_id, occurred_at)
```

### entries (دفتر دوطرفه)
```
id · workspace_id · transaction_id · account_id
direction(debit|credit) · amount · currency · base_amount · occurred_at
INDEX(workspace_id, account_id, occurred_at)
```
> هر تراکنش حداقل دو Entry می‌سازد. مجموع debit و credit هر تراکنش **باید** برابر باشد
> (در ارز پایه). این invariant در تست و در یک Job بازبینی روزانه تضمین می‌شود.
> این طراحی گزارش‌های شرکتی و ساختمانی را بدون بازنویسی ممکن می‌کند.

### recurring_rules
```
id · workspace_id · template (JSON: type, account, category, amount, currency)
rrule · starts_at · ends_at · next_run_at · auto_post(bool) · is_paused · timestamps
```

---

## ۴. بانک، چک، وام

### banks
```
id · workspace_id · name · branch · swift · country · logo · timestamps
```

### checks
```
id · workspace_id · account_id · direction(received|issued|guarantee)
check_number · amount · currency · base_amount · due_date
status(draft|issued|in_progress|cleared|bounced|void)
party_name · party_id · document_id · notes · transaction_id · timestamps
INDEX(workspace_id, due_date, status)
```

### loans
```
id · workspace_id · bank_id · account_id · principal · currency · interest_rate
interest_type(simple|compound) · installments_count · start_date
penalty_rate · outstanding_balance · status · timestamps
```

### loan_installments
```
id · loan_id · workspace_id · number · due_date · principal_part · interest_part
total_amount · paid_amount · paid_at · penalty_amount · status(due|paid|late|partial)
transaction_id
INDEX(workspace_id, due_date, status)
```

---

## ۵. سرمایه‌گذاری و دارایی

### investments
```
id · workspace_id · name · kind(gold|fx|stock|etf|crypto|real_estate|vehicle|startup)
symbol · quantity · avg_buy_price · currency · current_price · current_value
realized_profit · unrealized_profit · roi (computed) · notes · timestamps
```

### investment_transactions
```
id · workspace_id · investment_id · action(buy|sell|dividend|fee|split)
quantity · price · fee · currency · fx_rate · base_amount · occurred_at · transaction_id
```

### assets
```
id · workspace_id · name · kind(house|land|car|gold|watch|art|nft|other)
purchase_price · purchase_date · current_value · currency
depreciation_method(none|linear|declining) · depreciation_rate · salvage_value
insurance_provider · insurance_expires_at · notes · timestamps
```

---

## ۶. ساختمان

```
buildings          : id · workspace_id · name · address · units_count · fund_account_id
building_units     : id · building_id · workspace_id · unit_no · area_m2 · residents_count
                     owner_name · owner_contact · tenant_name · tenant_contact · share_factor
building_charges   : id · building_id · unit_id · period (YYYY-MM) · amount · currency
                     due_date · status(unpaid|partial|paid) · transaction_id
building_expenses  : id · building_id · category_id · amount · currency · occurred_at
                     document_id · description
```

---

## ۷. کسب‌وکار

```
contacts    : id · workspace_id · type(customer|supplier|employee|other) · name
              phone · email · tax_id · address · balance
projects    : id · workspace_id · name · status · budget_amount · currency
              starts_at · ends_at
invoices    : id · workspace_id · number · contact_id · project_id · issue_date · due_date
              subtotal · discount · tax · total · currency · fx_rate · base_total
              status(draft|sent|partial|paid|overdue|void) · notes
invoice_items: id · invoice_id · description · quantity · unit_price · tax_rate · total
payments    : id · workspace_id · invoice_id · contact_id · amount · currency
              paid_at · method · transaction_id
```

---

## ۸. سفر و تقسیم هزینه

```
trips         : id · workspace_id · name · destination · starts_at · ends_at · base_currency
trip_members  : id · trip_id · user_id (nullable) · display_name · weight
split_expenses: id · trip_id · workspace_id · payer_member_id · amount · currency
                fx_rate · base_amount · category_id · occurred_at · description
                latitude · longitude · document_id
split_shares  : id · split_expense_id · member_id · share_amount
                mode(equal|percent|weight|exact)
settlements   : id · trip_id · from_member_id · to_member_id · amount · currency
                settled_at · transaction_id
```

---

## ۹. بودجه، اسناد، هشدار

```
budgets       : id · workspace_id · name · scope(overall|category|project|trip|building|member)
                scope_id · period(monthly|yearly|custom) · starts_at · ends_at
                amount · currency · rollover(bool) · alert_thresholds (JSON)
budget_usages : id · budget_id · period_key · spent_amount · updated_at  (materialized)

documents     : id · workspace_id · disk · path · original_name · mime · size
                kind(receipt|invoice|contract|photo|voice|video|archive|other)
                ocr_status · ocr_text (LONGTEXT) · ocr_data (JSON)
                uploaded_by · checksum · timestamps
documentables : document_id · documentable_type · documentable_id   (polymorphic pivot)

alerts        : id · workspace_id · user_id · type · payload (JSON) · scheduled_at
                sent_at · read_at · channels (JSON) · status
```

---

## ۱۰. جستجو، AI، همگام‌سازی، امنیت

```
search_index_queue : id · workspace_id · indexable_type · indexable_id · action · created_at
embeddings         : id · workspace_id · owner_type · owner_id · vector (JSON/BLOB)
                     model · created_at
ai_conversations   : id · workspace_id · user_id · title · created_at
ai_messages        : id · conversation_id · role(user|assistant|tool) · content
                     tool_calls (JSON) · tokens · created_at
ai_insights        : id · workspace_id · type · title_key · body · severity
                     data (JSON) · valid_until · dismissed_at

sync_changes       : id · workspace_id · device_id · entity_type · entity_id
                     operation(create|update|delete) · payload (JSON)
                     client_version · server_version · applied_at
devices            : id · user_id · platform · name · push_token · last_seen_at · revoked_at
audit_logs         : id · workspace_id · user_id · action · subject_type · subject_id
                     before (JSON) · after (JSON) · ip · user_agent · created_at

translations       : id · locale · group · key · value · is_overridden · updated_at
                     UNIQUE(locale, group, key)
```

---

## ۱۱. یادداشت‌های عملکردی

- ایندکس ترکیبی `(workspace_id, occurred_at)` روی `transactions` و `entries` حیاتی است.
- جداول `transactions` و `entries` در بلندمدت کاندیدای **پارتیشن‌بندی بر اساس سال** هستند.
- گزارش‌های سنگین از جداول تجمیعی (`monthly_summaries`) خوانده می‌شوند که با Event به‌روز می‌شوند.
- `ocr_text` و متن اسناد در MySQL نگه‌داری اما در **Meilisearch** ایندکس می‌شوند.
