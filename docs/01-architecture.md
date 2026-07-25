# ۰۱ — معماری

## ۱. نمای کلان

```
┌──────────────────────────────────────────────────────────────┐
│  Clients (Flutter)                                           │
│  Android · iOS · Windows · macOS · Linux · Web               │
│  Clean Architecture · Riverpod · GoRouter · Isar (offline)   │
└───────────────┬──────────────────────────────────────────────┘
                │ HTTPS / REST (JSON) + Sanctum Token
                │ WebSocket (Reverb) برای Realtime
┌───────────────▼──────────────────────────────────────────────┐
│  API Gateway Layer (Laravel 12 / PHP 8.4)                    │
│  Routing · Auth · Rate Limit · Workspace Scope · Versioning  │
└───────────────┬──────────────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────────┐
│  Domain Modules (Modular Monolith)                           │
│  Core · Ledger · Personal · Business · Buildings · Travel    │
│  Investment · Assets · Banking · Budget · Documents          │
│  Reports · AI · Search · Notifications · Billing             │
└───┬────────────┬──────────────┬─────────────┬────────────────┘
    │            │              │             │
┌───▼───┐  ┌─────▼─────┐  ┌─────▼──────┐  ┌───▼─────────┐
│MySQL 8│  │Redis      │  │Meilisearch │  │Object Store │
│       │  │Cache/Queue│  │Search      │  │S3 / MinIO   │
└───────┘  └─────┬─────┘  └────────────┘  └─────────────┘
                 │
          ┌──────▼──────────────────────────────┐
          │ Workers (Horizon)                   │
          │ OCR · STT · AI · Reports · Notify   │
          │ Sync · Import · FX Rates            │
          └──────┬──────────────────────────────┘
                 │
          ┌──────▼──────────────────────────────┐
          │ External: OpenAI · OCR · FFmpeg     │
          │ FX Providers · SMS · Telegram · FCM │
          └─────────────────────────────────────┘
```

## ۲. چرا Modular Monolith (و نه Microservices)؟

در فاز اول، Microservices هزینهٔ عملیاتی سنگین و بدون بازدهی دارد. در عوض:

- کد از **روز اول** به ماژول‌های با مرز مشخص تقسیم می‌شود (`app/Modules/*` یا package جدا).
- هر ماژول: `Domain`, `Application`, `Infrastructure`, `Http`, `Database`, `Tests`.
- ارتباط بین ماژول‌ها **فقط** از طریق:
  - **Contract/Interface** ثبت‌شده در Service Container، یا
  - **Domain Event** (مثلاً `TransactionRecorded`).
- ماژول A هرگز مدل Eloquent ماژول B را مستقیماً import نمی‌کند.

نتیجه: هر ماژول در آینده می‌تواند بدون بازنویسی به سرویس مستقل تبدیل شود.

### قانون وابستگی

```
Core  ←  همه‌ی ماژول‌ها می‌توانند به Core وابسته باشند
Ledger ← ماژول‌های مالی به Ledger وابسته‌اند
هیچ ماژولی به ماژول هم‌سطح خود وابستهٔ مستقیم نیست
```

## ۳. ساختار پوشهٔ Backend

```
backend/
├── app/
│   ├── Core/                    # Workspace, Auth, Currency, Money, Tenancy
│   └── Support/
├── modules/
│   ├── Ledger/
│   │   ├── Domain/              # Entity, ValueObject, Event, Exception
│   │   ├── Application/         # UseCase, DTO, Query
│   │   ├── Infrastructure/      # Eloquent Model, Repository, Adapter
│   │   ├── Http/                # Controller, Request, Resource, Routes
│   │   ├── Database/            # Migration, Factory, Seeder
│   │   └── Tests/
│   ├── Personal/
│   ├── Business/
│   ├── Buildings/
│   ├── Travel/
│   ├── Investment/
│   ├── Assets/
│   ├── Banking/
│   ├── Budget/
│   ├── Documents/
│   ├── Reports/
│   ├── Search/
│   ├── AI/
│   ├── Notifications/
│   └── Billing/
├── config/
├── routes/
└── tests/
```

## ۴. پشتهٔ فناوری Backend

| لایه | انتخاب | دلیل |
|---|---|---|
| Framework | Laravel 12 | اکوسیستم، سرعت توسعه، Horizon/Scout/Sanctum |
| Language | PHP 8.4 | property hooks، asymmetric visibility، performance |
| DB | MySQL 8 | JSON، CTE، Window Function، بلوغ عملیاتی |
| Cache/Queue | Redis | صف، قفل، rate limit، cache |
| Queue UI | Laravel Horizon | مانیتورینگ صف‌های سنگین (OCR/AI) |
| Auth | Sanctum | توکن برای موبایل و دسکتاپ |
| Search | Scout + Meilisearch | جستجوی متنی سریع + typo tolerance فارسی |
| Storage | S3 / MinIO | اسناد، رسید، صوت، ویدیو |
| Realtime | Laravel Reverb | همگام‌سازی زنده و اعلان |
| Packages | Spatie (permission, media-library, activitylog, backup, query-builder, translatable) | استاندارد و آزموده |

## ۵. صف‌ها (Queues)

هر کار سنگین **باید** به صف برود. تفکیک صف‌ها:

| صف | کارها | اولویت |
|---|---|---|
| `realtime` | اعلان، Push، sync push | بالا |
| `ocr` | استخراج رسید، اسکن چک | متوسط |
| `ai` | تحلیل، Insight، NLP، Embedding | متوسط |
| `media` | تبدیل صوت (FFmpeg)، thumbnail | پایین |
| `reports` | گزارش‌های سنگین و PDF | پایین |
| `maintenance` | نرخ ارز، بکاپ، بازسازی ایندکس | پایین |

قوانین: هر Job **idempotent**، دارای `tries`/`backoff`، و متصل به `workspace_id` برای ردیابی.

## ۶. معماری Flutter

```
app/lib/
├── core/                 # theme, i18n, di, error, network, result
├── data/
│   ├── local/            # Isar collections, DAO, migrations
│   ├── remote/           # Dio client, endpoints, DTO
│   └── repositories/     # پیاده‌سازی repository
├── domain/
│   ├── entities/
│   ├── repositories/     # اینترفیس‌ها
│   └── usecases/
├── presentation/
│   ├── router/           # GoRouter
│   ├── widgets/          # design system
│   └── features/
│       ├── dashboard/
│       ├── transactions/
│       ├── accounts/
│       ├── budget/
│       ├── investment/
│       ├── documents/
│       ├── reports/
│       ├── ai/
│       └── settings/
└── sync/                 # sync engine, queue, conflict resolver
```

| موضوع | انتخاب |
|---|---|
| State | Riverpod (کد کمتر، تست‌پذیر، compile-safe) |
| Routing | GoRouter (deep link، وب، دسکتاپ) |
| Local DB | Isar (سریع، query قوی، cross-platform) |
| HTTP | Dio + interceptor برای auth/retry/offline |
| DI | Riverpod providers |
| i18n | ARB + Translation DB (بارگیری زنده) |
| Charts | fl_chart |
| Testing | unit + widget + golden + integration |

## ۷. اصول عرضی (Cross-cutting)

1. **Workspace Scoping** — یک Global Scope مرکزی؛ نشت داده بین Workspaceها یک باگ امنیتی درجه‌یک است.
2. **Money** — یک Value Object واحد: `amount(int, minor units)` + `currency`. عملیات ریاضی روی float ممنوع.
3. **Idempotency** — هر عملیات نوشتن از کلاینت با هدر `Idempotency-Key`.
4. **Soft Delete + Audit** — حذف واقعی فقط با فرایند مشخص؛ همه‌چیز در Activity Log.
5. **Feature Flags** — هر ماژول قابل خاموش/روشن شدن per-plan و per-workspace.
6. **Observability** — لاگ ساخت‌یافته، Sentry، متریک صف‌ها، trace شناسهٔ درخواست.
