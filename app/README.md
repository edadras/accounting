# Finora — Flutter client

Multi-platform client: Android, iOS, Web, Windows, macOS, Linux.

## Status

Milestones **M2 (First App)** and the client half of **M3 (Budget & Reports)**
from [`docs/04-roadmap.md`](../docs/04-roadmap.md).

Five screens: Dashboard, Transactions, Reports (cash flow + budgets), Accounts,
Settings — plus the quick-add sheet.

The app runs standalone against an in-memory repository, so `flutter run` gives
you a working, populated UI with no backend. Swapping in the real API is a
single provider override — see `ledgerRepositoryProvider` in
`lib/data/ledger_repository.dart`.

**Verified:** `flutter analyze` clean, **30 tests passing** on Flutter 3.29 —
unit tests for money and formatting, widget tests that boot the whole app in
both writing directions, and 11 golden images.

## Running

```bash
flutter pub get
flutter run            # add -d chrome / -d windows / -d linux as needed
flutter test
flutter analyze

# Regenerate the golden images after an intentional visual change
flutter test --update-goldens test/golden_test.dart
```

> Only the `web/` runner is committed. Add the others once:
> `flutter create --platforms=android,ios,windows,macos,linux .`

## Goldens

`test/goldens/` holds a rendered image of every screen in Persian (RTL) and
English (LTR), plus one in the light theme. They are the only test that can
catch a mirrored layout or a colour that stops meaning what it meant — both of
which pass every behavioural assertion. Vazirmatn and the Material icon font
are loaded explicitly in the test, because without them the engine draws boxes
and the images become worthless.

## The neon design system

The visual language lives in three files and nothing outside them hard-codes a
colour or a shadow.

| File | Owns |
|---|---|
| `core/theme/neon_palette.dart` | Every colour, dark and light |
| `core/theme/neon_effects.dart` | Glow, glass, gradients, radii, motion |
| `core/theme/neon_theme.dart` | The Material `ThemeData` built from both |

The rule that keeps it from looking like a toy:

> **Glow is information, not decoration.**

Only elements that carry meaning glow — the net-worth figure, the active tab,
the primary button, a breached budget. Everything else is a flat dark surface
with a hairline border. If everything glows, nothing reads.

Colour is semantic and consistent everywhere:

| Colour | Meaning |
|---|---|
| Cyan `#00E5FF` | Primary, balance, transfer, focus |
| Magenta `#FF2E88` | Expense, negative, danger |
| Lime `#9BFF3D` | Income, positive |
| Violet `#8B5CFF` | Investment, AI |
| Amber `#FFB020` | Warning, due date, pending sync |

There is a light theme. It is a *muted* translation of the same hues, not neon
on white — neon on white is unreadable, so the light palette darkens each hue
until it passes contrast.

## Structure

```
lib/
├── core/
│   ├── money/       Money, Currency, MoneyFormatter  (mirrors the backend)
│   ├── i18n/        AppLocale, Translator, bundled fallback strings
│   └── theme/       the neon design system
├── domain/          entities + analytics, free of Flutter and of the API
├── data/            repositories (in-memory today, API + Isar in M7)
└── presentation/
    ├── widgets/     NeonCard, NeonButton, StatTile, NeonBarChart, …
    ├── features/    dashboard, transactions, reports, accounts, settings
    └── shell.dart   nav bar + quick-add button
```

### One trap worth remembering

A Flutter `BoxDecoration` **ignores its `color` whenever a `gradient` is set**.
Setting both — which is easy to do and reads as harmless — silently drops the
fill, so a card becomes whatever is painted behind it. On a glowing card that
meant its own halo bled through and turned a dark glass panel into a solid slab
of accent colour. `NeonEffects.glass()` therefore blends the tint *into* the
surface colour and returns one opaque gradient, and no widget passes both.

## Rules this client follows

1. **No `double` holds an amount.** `Money` is integer minor units, and it
   refuses to add two currencies without an explicit rate.
2. **No hard-coded display string.** Everything resolves through `Translator`,
   which overlays server translations on the bundled fallback.
3. **`EdgeInsetsDirectional`, never `EdgeInsets.only(left:)`.** The app runs in
   Persian and Arabic; a physical inset breaks those layouts.
4. **Amounts render LTR inside RTL text.** `MoneyFormatter` wraps them in bidi
   isolates so a minus sign never jumps to the wrong end.
5. **Writes land locally first.** The UI never waits on the network to show the
   user their own money; unsynced rows are marked, never hidden.
