# Future improvements

Parked features with enough design captured to pick up later.

---

## Coin economy mode (selectable at game start)

**Status:** parked (2026-07). Design agreed in principle; two mechanics still to decide (below).

**Goal.** Offer the Season 9 "coins" economy as an *alternative* to the current card draw/keep
economy, chosen when creating a game. Both stay supported.

### How it works

- **Toggle.** New session config `economy` = `cards` (current, default) | `coins`, surfaced on the
  create-game screen.
- **Earning.** Instead of draw/keep, an answered question grants the hider coins by category. Proposed
  defaults (Season 9 values mapped onto our six categories — keep as tunable config `coin_values`):

  | Our category | Season 9 group | Coins |
  |---|---|---|
  | Matching, Thermometer | Relative | 40 |
  | Radar | Radar | 30 |
  | Photo | Photos | 15 |
  | Tentacles | Oddball | 10 |
  | Measuring | Precision | 10 |

- **Spending.** No hand / no draw — the hider casts curses straight from the catalogue by paying a
  coin cost. All existing curse *effects* (disable categories, blocking, dice, spotty memory, etc.)
  are reused unchanged; only the cost model changes from "discard cards / conditions" to coins.

### Decisions still open

1. **Curse cost model** — one of:
   - Per-curse cost tiered by strength (light social ~30, dice/movement ~50, question/transit
     blockers ~90). Most faithful + strategic. *(leaning here)*
   - Flat cost per curse (e.g. 50). Simplest.
   - Buy a "curse die" for 50 coins, draw a random curse (closest to the show's wording).
2. **Time bonuses + veto/move powerups in coin mode** — buyable with coins / time-banking only /
   dropped entirely (score = pure survival time).

### Implementation checklist

Backend:
- [ ] `HideAndSeekMode::defaultConfig` — add `economy` + `coin_values` (+ curse cost config).
- [ ] Reward branch in `resolveQuestion`: `economy=coins` → add `coin_values[category]` to
      `state_data['coins']` instead of the draw/keep flow.
- [ ] Curse casting: in coins mode, `play_curse` pays coins (validate balance) instead of the
      discard/condition cost; reuse the existing effect application.
- [ ] `CardSeeder` / cards table — add a `coin_cost` per curse (and time-bonus/powerup prices if kept).
- [ ] `GameStatePresenter` — expose `coins` balance + a `curse_shop` (castable curses with cost +
      affordability) when `economy=coins`; keep `hand`/`pending_draw` for `cards`.
- [ ] `availableActions` — hide hand-only actions (keep_cards, discard_card) in coins mode.
- [ ] Feature tests for earning + spending in coin mode.

Frontend (web):
- [ ] Economy selector on the create-game screen.
- [ ] A coin balance + "curse shop" component that replaces `card-deck` when `economy=coins`
      (reuse curse rendering; grey out unaffordable curses).
- [ ] hu/en strings.

Admin (Filament):
- [ ] Show `economy` + `coins` in the session state editor / replay.

### Notes
- The six category icons already carry the Season 9 grouping in their metadata
  (`web/src/app/core/services/category.service.ts`), so a display regroup to the five Season 9
  categories is a small follow-up if wanted alongside this.
