# EVE Trade Tools — Mineral Compare

A WordPress plugin companion to [ETT Price Helper](https://github.com/C4813/EVE-Trade-Tools-Price-Helper). Displays mineral price comparison tables and trade opportunity tools for EVE Online, with per-character fee calculations via EVE SSO.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress) ![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php) ![License](https://img.shields.io/badge/license-GPLv2-green)

---

## Features

- **Buy Values table** — highest buy order prices across all five main trade hubs, with 30-day trend indicators
- **Sell Values table** — lowest sell order prices across the same hubs, with 30-day trend indicators
- **Extended Trade Opportunities** — cross-hub arbitrage simulation that walks the live order book, with adjustable buy/sell legs and a minimum margin filter
- **No-Undock Trading** — per-hub margin estimate for buying and selling within the same station
- **Character Profile** — EVE SSO authentication card showing per-hub broker fees and sales tax derived from the character's Accounting and Broker Relations skill levels

Brokerage fees and sales tax are calculated from authenticated character skill levels. Without a connected character, default rates (3% broker fee, 8% sales tax) are used.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| [ETT Price Helper](https://github.com/C4813/EVE-Trade-Tools-Price-Helper) | 1.8.2+ |
| External MySQL / MariaDB database | configured in ETT Price Helper |

> ETT Price Helper must be installed and active before this plugin can be activated.

---

## Installation

1. Ensure **ETT Price Helper v1.8.2+** is installed and active.
2. Clone or download this repository into `/wp-content/plugins/ett-mineral-compare/`.
3. Activate **EVE Trade Tools Mineral Compare** through the WordPress Plugins screen.
4. Add the shortcodes to any page or post (see [Shortcodes](#shortcodes) below).
5. Run a price job in ETT Price Helper to populate order book and trend data.

---

## Shortcodes

### `[ettmc_mineral_compare]`

Renders the full suite of price tables and trade opportunity sections:

- Buy Values table
- Sell Values table
- Extended Trade Opportunities table (with hub filters, buy/sell leg selectors, and margin filter)
- No-Undock Trading table

### `[ettmc_mineral_profile]`

Renders the EVE SSO character connect/disconnect card. Shows each connected character's Accounting and Broker Relations skill levels, their resulting sales tax, and per-hub broker fee.

> Place `[ettmc_mineral_profile]` on the same page as, or on a page linked from, `[ettmc_mineral_compare]` so users can connect a character and have their fees applied automatically.

---

## How It Works

### Price & Trend Data

Prices are read from ETT Price Helper's `ett_prices` table (populated during price runs). Trend arrows reflect today's value versus the 30-day average:

- **Buy table trend** — today's highest buy vs. average highest buy over the past 30 days
- **Sell table trend** — today's lowest sell vs. average lowest sell over the past 30 days

Trend data is written to `ettmc_mineral_trends` during ETT Price Helper history jobs and will not appear until at least one history job has completed.

### Extended Trade Simulation

During each ETT Price Helper price run, individual market orders for the 8 mineral type IDs are captured page-by-page and stored in `ettmc_mineral_orders`. The frontend then simulates walking the order book — buying from sell orders at one hub and selling into buy orders at another — to find the realistic profitable volume up to the point where the margin falls below the configured minimum.

### Fee Calculation

Broker fee and sales tax are calculated from character skill levels fetched via ESI:

```
Broker fee  = 3% − (0.3% × Broker Relations) − (0.03% × faction standing) − (0.02% × corp standing)
Sales tax   = 8% × (1 − 0.11 × Accounting)
```

The best (lowest) fee across all connected characters is used for trade calculations. Without a connected character, defaults of 3% broker / 8% sales tax apply.

### EVE SSO

This plugin shares the same EVE developer app and callback URL as ETT Reprocess Trading — no second EVE app is needed. Characters authenticated here are stored separately (user meta key `ettmc_characters`) and are only used by Mineral Compare.

Required ESI scopes:
- `esi-skills.read_skills.v1`
- `esi-characters.read_standings.v1`

---

## Database Tables

Two tables are created in the external database configured in ETT Price Helper:

| Table | Purpose |
|---|---|
| `ettmc_mineral_orders` | Individual mineral market orders captured during price runs, used for order-book simulation |
| `ettmc_mineral_trends` | Pre-computed 30-day trend percentages for buy and sell prices, written during history jobs |

Both tables are dropped automatically when the plugin is uninstalled.

---

## File Structure

```
ett-mineral-compare/
├── assets/
│   ├── admin.css          # Admin tab styles
│   ├── admin.js           # Admin tab JS (DB connection test)
│   ├── frontend.css       # All frontend styles
│   └── frontend.js        # Price table rendering, trade simulation, accordion
├── includes/
│   ├── class-ettmc-admin.php   # Admin tab registration and AJAX
│   ├── class-ettmc-extdb.php   # External DB read/write layer
│   ├── class-ettmc-esi.php     # Data loading and formatting
│   ├── class-ettmc-hooks.php   # ETT Price Helper job hooks
│   ├── class-ettmc-oauth.php   # EVE SSO authentication
│   └── class-ettmc-render.php  # Shortcodes, enqueuing, fee computation
├── templates/
│   ├── admin/
│   │   └── settings-page.php   # Admin settings tab template
│   └── frontend/
│       ├── mineral-compare.php # Main compare page template
│       └── mineral-profile.php # Character profile template
├── ett-mineral-compare.php     # Plugin entry point
├── uninstall.php               # Cleanup on uninstall
└── readme.txt                  # WordPress.org readme
```

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
