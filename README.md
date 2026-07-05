# Hide & Seek — Backend

Server-authoritative backend for **Hide & Seek**, a real-time, location-based hide-and-seek game
played across Hungarian cities (inspired by Jet Lag: The Game). This service owns **all** game state
and rules — clients never run game logic or trust their own clocks — and exposes a REST + WebSocket
contract that every client (web today, native later) consumes.

Built with **Laravel 13**, **Filament v5** (admin/ops panel), **Laravel Reverb** (WebSockets) and
**Sanctum** (API auth).

**Live:** the game runs at **[hideandseek.hu](https://hideandseek.hu)**; this backend serves the API at **[api.hideandseek.hu](https://api.hideandseek.hu)** (admin panel at `/admin`).

> The web client lives in a separate repository: **[hide-and-seek-web](https://github.com/lcsoka/hide-and-seek-web)**.

---

## Highlights

- **Server-authoritative game engine** — a generic engine plus pluggable *game modes*. Hide & Seek
  is the first mode; the mode contract's most important method is `locationVisibility()`, which
  decides who may see whose position (e.g. the hider stays hidden from seekers).
- **Geo question engine** — radar, thermometer, matching, measuring, tentacles and photo questions
  are evaluated server-side against **OpenStreetMap** data (via a cached, resilient Overpass client),
  so answers can't be forged.
- **Real-time** — lobby, roles, movement, questions, curses and transit are broadcast over Reverb
  (Pusher protocol) with reconnection/catch-up support.
- **Admin & ops panel** (Filament, at `/admin`) — sessions, players, teams, action logs, game
  results, moderation of user-generated cards/questions, a session state/config visual editor, a
  leaderboard/stats dashboard, and a **map + timeline replay** of any finished game.
- **Reproducible games** — seeded, logged randomness (dice/cards) means seed + action log = an exact
  replay of any game.
- **Hungarian-first localization** — `hu` is the default locale, `en` the fallback
  (`spatie/laravel-translatable` for models, `lang/{hu,en}` for UI strings).

## Tech stack

| | |
|---|---|
| Language / framework | PHP 8.4+, Laravel 13 |
| Admin panel | Filament v5 |
| Realtime | Laravel Reverb (Pusher protocol) |
| API auth | Laravel Sanctum (token) |
| Database | SQLite (default, dev) — any Laravel-supported driver in production |
| Geo data | OpenStreetMap via Overpass (cached) + Nominatim (city boundaries) + OSRM (routing) |
| i18n | `spatie/laravel-translatable`, `laravel-lang` |
| Tests | PHPUnit |

## Requirements

- PHP **8.4+** with the usual Laravel extensions (Laravel 13 + its Symfony components require 8.4.1+)
- [Composer](https://getcomposer.org/)
- Node.js **20+** and npm (to build the admin/Vite assets)
- Optionally [Laravel Herd](https://herd.laravel.com/) to serve `http://hide-and-seek.test`

## Getting started

```bash
git clone git@github.com:lcsoka/hide-and-seek-backend.git
cd hide-and-seek-backend

# One-shot setup: install deps, create .env, generate key, migrate, build assets
composer setup

# Seed the card + question catalogue (and reference data)
php artisan migrate --seed
```

`composer setup` copies `.env.example` → `.env`, generates the app key, runs migrations and builds
front-end assets. Review `.env` afterwards — in particular the `REVERB_*` keys and `APP_URL`.

### Running it

Serving the app (via Herd or `php artisan serve`) is **not enough** on its own: the game also needs
the queue worker (question-truth jobs), the Reverb WebSocket server (live events), and the
**scheduler** (which prunes abandoned sessions and guest cruft every 15 minutes).

With **Herd**, the app is served at `http://hide-and-seek.test`; start the rest alongside it:

```bash
composer services   # queue:listen + reverb:start + schedule:work
```

Without Herd, run everything (PHP server, queue, Reverb, scheduler, logs, Vite) in one command:

```bash
composer dev
```

If none of those is running, abandoned games and guest users/tokens accumulate. Clean up manually at
any time:

```bash
php artisan game:prune-abandoned                     # threshold-based (safe for prod)
php artisan game:prune-abandoned --idle=0 --purge    # dev: wipe every idle game + orphan guest now
```

### Admin panel

The Filament panel is at **`/admin`** (e.g. `http://hide-and-seek.test/admin`). Access is restricted
to allow-listed emails — add yours to `game.admin_emails` (see `config/game.php`) and register/login
with that address.

## Generating a sample game

To explore the admin panel (state editor + replay) without playing a full game, generate one rich,
fully-persisted sample — multiple rounds with a rotating hider, players moving on street-routed
paths, every question category, curses, and a transit leg:

```bash
php artisan game:sample --rounds=2 --seekers=2
```

It prints a join code plus direct links to the session's state editor and map/timeline replay.

## Testing

```bash
php artisan test        # or: composer test
```

Code style is enforced with [Pint](https://laravel.com/docs/pint):

```bash
./vendor/bin/pint
```

## API contract

The REST and realtime contracts are the source of truth every client generates against:

- **`openapi.yaml`** — REST contract (OpenAPI 3.1)
- **`asyncapi.yaml`** — realtime/Reverb contract (AsyncAPI 3.0)
- **`backend-documentation.md`** — engine/mode architecture, the full contract, the export pipeline,
  the developer/debug API and the admin panel

## Project layout

```
app/
  Console/Commands/      Artisan commands (e.g. game:sample, game:prune-abandoned)
  Filament/Resources/    Admin panel resources (Sessions, Players, Cards, Questions, Users, …)
  Game/
    GameEngine.php       Generic, mode-agnostic engine
    Modes/HideAndSeek/   The Hide & Seek game mode + question evaluators
    Geo/                 Map data sources (Overpass) + geometry helpers
    ReplayBuilder.php    Assembles the admin map/timeline replay bundle
  Http/Controllers/Api/  REST endpoints
  Models/
config/game.php          Game configuration (sizes, questions, hiding zone, admin emails)
database/                Migrations, seeders (cards + questions), factories
lang/{hu,en}/            UI translations (Hungarian-first)
tests/                   Feature + unit tests
```

## License

Private project. All rights reserved unless noted otherwise.
