# Finora — Flutter client

Multi-platform client: Android, iOS, Web, Windows, macOS, Linux.

## Status

Milestone **M2 (First App)** from [`docs/04-roadmap.md`](../docs/04-roadmap.md).

The app runs standalone against an in-memory repository, so `flutter run` gives
you a working, populated UI with no backend. Swapping in the real API is a
single provider override — see `ledgerRepositoryProvider` in
`lib/data/ledger_repository.dart`.

## Running

```bash
flutter pub get
flutter run            # add -d chrome / -d windows / -d linux as needed
flutter test
flutter analyze
```

> The platform runner directories (`android/`, `ios/`, `web/`, …) are not
> committed. Generate them once with:
> `flutter create --platforms=android,ios,web,windows,macos,linux .`

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
├── domain/          entities, free of Flutter and of the API
├── data/            repositories (in-memory today, API + Isar in M7)
└── presentation/
    ├── widgets/     NeonCard, NeonButton, StatTile, NeonBarChart, …
    ├── features/    dashboard, transactions, accounts, settings
    └── shell.dart   nav bar + quick-add button
```

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
