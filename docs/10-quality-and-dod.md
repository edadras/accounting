# ۱۰ — کیفیت، تست و Definition of Done

## ۱. چرا این سند وجود دارد

نرم‌افزار مالی نمی‌تواند «تقریباً درست» باشد. یک خطای گرد کردن یا یک نشت داده بین
Workspaceها اعتماد کاربر را برای همیشه از بین می‌برد. این سند حداقل استانداردی است
که هیچ PRای زیر آن merge نمی‌شود.

## ۲. Definition of Done (برای هر تسک)

یک تسک زمانی «تمام» است که **همهٔ** موارد زیر برقرار باشند:

- [ ] کد نوشته شده و در ماژول درست قرار گرفته (مرز دامنه نقض نشده)
- [ ] تست واحد برای منطق دامنه نوشته شده
- [ ] تست Feature برای endpoint نوشته شده (شامل حالت خطا)
- [ ] **تست دسترسی**: کاربر Workspace دیگر `403` می‌گیرد
- [ ] مهاجرت DB برگشت‌پذیر است (`migrate:rollback` کار می‌کند)
- [ ] همهٔ رشته‌های نمایشی در Translation DB برای `fa` و `en` موجودند
- [ ] در حالت RTL و LTR بررسی شده (اگر UI دارد)
- [ ] PHPStan level 8 و Pint بدون خطا
- [ ] `flutter analyze` بدون warning (اگر Flutter دارد)
- [ ] OpenAPI به‌روز شده (اگر API تغییر کرده)
- [ ] کوئری‌ها بررسی N+1 شده‌اند
- [ ] CHANGELOG به‌روز شده

## ۳. هرم تست

```
        ┌──────────────┐
        │ E2E / Golden │   کم، ولی روی مسیرهای حیاتی
        ├──────────────┤
        │   Feature    │   هر endpoint، شامل مجوزها
        ├──────────────┤
        │     Unit     │   زیاد — همهٔ منطق مالی
        └──────────────┘
```

### تست‌های مالی که هرگز حذف نمی‌شوند

| تست | تضمین |
|---|---|
| `MoneyTest` | جمع دو ارز متفاوت بدون نرخ **باید** خطا بدهد |
| `RoundingTest` | گرد کردن در تبدیل ارز نباید ریال گم کند |
| `DoubleEntryTest` | مجموع debit = credit برای هر تراکنش |
| `BalanceIntegrityTest` | `current_balance` == مجموع entryها |
| `TransferTest` | انتقال چندارزی هر دو مانده را درست تغییر دهد |
| `WorkspaceIsolationTest` | برای **هر** endpoint، عدم دسترسی متقابل |
| `IdempotencyTest` | ارسال دوبارهٔ یک درخواست، رکورد تکراری نسازد |
| `SyncConflictTest` | تعارض مالی هرگز بی‌صدا حل نشود |
| `RecurringTest` | ثبت خودکار تکراری، دوبار ثبت نکند |

### تست کلاینت
- Unit برای UseCaseها و Sync Engine
- Widget test برای فرم‌های ورودی
- **Golden test برای هر صفحه در `fa` (RTL) و `en` (LTR)**
- Integration test برای مسیر «ثبت آفلاین → همگام‌سازی»

## ۴. اهداف پوشش

| بخش | حداقل پوشش |
|---|---|
| ماژول Ledger و Money | ۹۰٪ |
| سایر ماژول‌های دامنه | ۸۰٪ |
| Sync Engine | ۸۵٪ |
| UI | بدون هدف عددی، اما مسیرهای حیاتی Golden دارند |

## ۵. CI/CD

**در هر PR:**
```
1. Laravel Pint (فرمت)
2. PHPStan level 8
3. Pest/PHPUnit + پوشش
4. composer audit (آسیب‌پذیری وابستگی‌ها)
5. flutter analyze
6. flutter test (شامل golden)
7. بررسی کلیدهای ترجمهٔ گمشده (fa/en)
8. بررسی migration برگشت‌پذیر
```

**در merge به `main`:** ساخت خودکار، deploy به staging، اجرای smoke test.

**انتشار:** tag نسخه (SemVer)، تولید CHANGELOG، ساخت باینری‌های Flutter برای ۶ پلتفرم.

## ۶. اهداف کارایی

| سنجه | هدف |
|---|---|
| پاسخ API (p95) | زیر ۲۰۰ms |
| جستجو روی ۱۰۰هزار رکورد | زیر ۲۰۰ms |
| گزارش سالانه روی ۵۰هزار تراکنش | زیر ۵ ثانیه (تازه)، زیر ۱ ثانیه (cache) |
| باز شدن سرد اپ | زیر ۲ ثانیه |
| ثبت هزینه (کاربر) | زیر ۵ ثانیه، حداکثر ۳ لمس |
| OCR رسید | زیر ۱۵ ثانیه تا پیش‌نویس |

## ۷. قواعد کد

**Backend**
- Controller نازک؛ منطق در UseCase
- بدون منطق کسب‌وکار در Model
- بدون کوئری خام در Controller
- هر عملیات نوشتنی مالی داخل تراکنش دیتابیس
- `declare(strict_types=1)` در همهٔ فایل‌ها
- Enum به‌جای رشته‌های جادویی

**Flutter**
- بدون منطق در Widget؛ منطق در UseCase/Notifier
- بدون `setState` برای وضعیت اشتراکی
- بدون `EdgeInsets.only(left:)` — همیشه `EdgeInsetsDirectional`
- بدون رشتهٔ فارسی/انگلیسی hard-code
- بدون `double` برای مبالغ

## ۸. مدیریت شاخه‌ها و Commit

```
main         ← پایدار، همیشه قابل انتشار
develop      ← یکپارچه‌سازی
feature/*    ← هر تسک
fix/*        ← رفع باگ
```

**Conventional Commits:**
```
feat(ledger): add multi-currency transfer
fix(sync): prevent duplicate on retry
docs(roadmap): update M5 acceptance criteria
test(money): add rounding edge cases
```

## ۹. پایش تولید (Production)

- Sentry برای خطاهای Backend و Flutter
- Horizon برای سلامت صف‌ها + هشدار روی صف عقب‌افتاده
- لاگ ساخت‌یافته با `request_id` و `workspace_id` (بدون دادهٔ مالی حساس در لاگ)
- Uptime check روی endpointهای حیاتی
- هشدار روی: نرخ خطای ۵xx، طول صف، تأخیر OCR/AI، شکست بکاپ

## ۱۰. سه قانونی که هرگز شکسته نمی‌شوند

1. **هیچ مبلغی با `float` محاسبه نمی‌شود.**
2. **هیچ کوئری دامنه‌ای بدون `workspace_id` اجرا نمی‌شود.**
3. **هیچ تعارض مالی بی‌صدا حل نمی‌شود.**
