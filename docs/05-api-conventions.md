# ۰۵ — قراردادهای API

## ۱. اصول

- **REST** پایهٔ همهٔ کلاینت‌هاست. GraphQL در صورت نیاز به‌عنوان لایهٔ مکمل برای گزارش‌های ترکیبی اضافه می‌شود، نه جایگزین.
- نسخه‌بندی در مسیر: `/api/v1/...`
- همهٔ پاسخ‌ها JSON با `Content-Type: application/json; charset=utf-8`
- زمان‌ها همیشه **ISO-8601 در UTC**: `2026-07-25T09:30:00Z`
- شناسه‌ها **ULID** به‌صورت رشته

## ۲. احراز هویت و Scope

```
Authorization: Bearer <sanctum-token>
X-Workspace-Id: <ulid>        # اجباری برای همهٔ endpointهای دامنه
Accept-Language: fa | en | tr | ar
X-Device-Id: <ulid>           # برای همگام‌سازی و مدیریت دستگاه
Idempotency-Key: <ulid>       # اجباری برای POST/PUT/PATCH/DELETE
```

اگر `X-Workspace-Id` غایب یا غیرمجاز باشد → `403 workspace_forbidden`.

## ۳. شکل پاسخ

**موفق — تکی**
```json
{
  "data": { "id": "01J...", "type": "transaction", "amount": 35000, "currency": "TRY" },
  "meta": { "request_id": "01J..." }
}
```

**موفق — لیست**
```json
{
  "data": [ ... ],
  "meta": {
    "page": 1, "per_page": 50, "total": 1240,
    "cursor_next": "eyJ...",
    "request_id": "01J..."
  }
}
```

**خطا**
```json
{
  "error": {
    "code": "validation_failed",
    "message": "مبلغ باید بزرگ‌تر از صفر باشد.",
    "details": { "amount": ["min_value"] },
    "request_id": "01J..."
  }
}
```

`message` **همیشه** ترجمه‌شده بر اساس `Accept-Language` است؛ `code` همیشه انگلیسی و ماشین‌خوان.

## ۴. کدهای خطای استاندارد

| HTTP | code | معنی |
|---|---|---|
| 400 | `bad_request` | درخواست نامعتبر |
| 401 | `unauthenticated` | توکن نامعتبر/منقضی |
| 403 | `forbidden` / `workspace_forbidden` / `plan_limit_reached` | عدم دسترسی |
| 404 | `not_found` | یافت نشد |
| 409 | `conflict` / `version_conflict` | تعارض همگام‌سازی |
| 422 | `validation_failed` | خطای اعتبارسنجی |
| 429 | `rate_limited` | تعداد درخواست بیش از حد |
| 500 | `server_error` | خطای داخلی |
| 503 | `service_unavailable` | سرویس بیرونی در دسترس نیست (AI/OCR) |

## ۵. نمایش مبلغ

هر فیلد پولی به این شکل برگردانده می‌شود:

```json
"amount": {
  "value": 35000,
  "currency": "TRY",
  "minor_unit": 2,
  "formatted": "₺350.00",
  "base": { "value": 105000, "currency": "IRR", "fx_rate": "3.0" }
}
```

کلاینت **هرگز** `formatted` را برای محاسبه استفاده نمی‌کند — فقط برای نمایش.

## ۶. صفحه‌بندی

- لیست‌های کوچک: `?page=1&per_page=50`
- لیست‌های بزرگ و همگام‌سازی: **cursor-based** با `?cursor=...&limit=100`
- حداکثر `per_page` = ۲۰۰

## ۷. فیلتر، مرتب‌سازی، include

بر پایهٔ `spatie/laravel-query-builder`:

```
GET /api/v1/transactions
  ?filter[type]=expense
  &filter[category_id]=01J...
  &filter[occurred_between]=2026-01-01,2026-03-31
  &filter[search]=گوشت
  &sort=-occurred_at
  &include=category,account,documents
```

## ۸. عملیات دسته‌ای

```
POST /api/v1/batch
{
  "operations": [
    { "id": "op1", "method": "POST", "path": "/transactions", "body": {...} },
    { "id": "op2", "method": "PATCH", "path": "/transactions/01J...", "body": {...} }
  ]
}
```
پاسخ per-operation؛ شکست یک عملیات بقیه را باطل نمی‌کند (مگر `atomic: true`).

## ۹. کارهای ناهمگام (OCR / AI / گزارش)

```
POST /api/v1/documents/{id}/ocr     → 202 { "job_id": "01J...", "status": "queued" }
GET  /api/v1/jobs/{job_id}          → { "status": "processing|done|failed", "result": {...} }
```
یا دریافت زنده از طریق کانال WebSocket `workspace.{id}.jobs`.

## ۱۰. محدودیت نرخ (Rate Limit)

| گروه | محدودیت |
|---|---|
| auth | ۵ درخواست در دقیقه بر اساس IP |
| عمومی | ۱۲۰ درخواست در دقیقه بر اساس کاربر |
| AI / OCR | بر اساس پلن (Free: ۰، Premium: ۱۰۰/روز) |
| sync | ۶۰ درخواست در دقیقه بر اساس دستگاه |

هدرهای `X-RateLimit-Limit` و `X-RateLimit-Remaining` همیشه ارسال می‌شوند.

## ۱۱. نقشهٔ endpointهای اصلی (v1)

```
POST   /auth/register · /auth/login · /auth/logout · /auth/refresh
GET    /me
GET    /workspaces                     POST /workspaces
GET    /workspaces/{id}                PATCH /workspaces/{id}
POST   /workspaces/{id}/members        DELETE /workspaces/{id}/members/{userId}

GET    /accounts                       POST /accounts
GET    /categories?tree=1              POST /categories
GET    /transactions                   POST /transactions
PATCH  /transactions/{id}              DELETE /transactions/{id}
POST   /transactions/parse             # زبان طبیعی → پیش‌نویس تراکنش
POST   /transactions/from-voice        # فایل صوتی → پیش‌نویس
POST   /transactions/from-receipt      # سند OCR → پیش‌نویس

GET    /budgets                        POST /budgets
GET    /reports/{type}                 POST /reports/{type}/export
GET    /search?q=...                   GET  /search/semantic?q=...
POST   /documents                      GET  /documents/{id}
GET    /checks · /loans · /investments · /assets
GET    /buildings · /invoices · /contacts · /trips
POST   /ai/chat                        GET  /ai/insights
GET    /sync/pull?since=...            POST /sync/push
GET    /translations/{locale}?since=...
GET    /notifications                  PATCH /notifications/{id}/read
```

## ۱۲. سازگاری رو به عقب

- افزودن فیلد جدید **شکستن نیست**؛ حذف یا تغییر معنی فیلد **شکستن است**.
- هر تغییر شکننده → `v2`؛ `v1` حداقل ۶ ماه پشتیبانی می‌شود.
- کلاینت باید فیلدهای ناشناخته را نادیده بگیرد، نه خطا بدهد.
