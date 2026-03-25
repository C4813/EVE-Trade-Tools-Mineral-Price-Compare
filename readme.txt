=== EVE Trade Tools Mineral Compare ===
Contributors: purposefullyobtuse
Tags: eve online, minerals, trading, market
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mineral price comparison and trade opportunity tables for EVE Online, powered by ETT Price Helper.

== Description ==

ETT Mineral Compare is a companion plugin for ETT Price Helper. It provides shortcodes to display:

* **Buy Values** — highest buy order prices across the five main trade hubs (Jita, Amarr, Rens, Hek, Dodixie), with 30-day trend indicators.
* **Sell Values** — lowest sell order prices across the same hubs, with 30-day trend indicators.
* **Extended Trade Opportunities** — cross-hub arbitrage simulation walking the live order book, with adjustable buy/sell legs and minimum margin filter.
* **No-Undock Trading** — per-hub margin estimates for buying and selling within the same station.
* **Character Profile** — EVE SSO authentication card showing per-hub broker fees and sales tax based on the character's Accounting and Broker Relations skills.

Brokerage fees and sales tax are calculated from authenticated EVE character skill levels. Without a connected character, default rates (3% broker fee, 8% sales tax) are used.

== Requirements ==

* ETT Price Helper v1.8.2 or later (for order book capture and trend history jobs)
* An external MySQL/MariaDB database configured in ETT Price Helper

== Shortcodes ==

* `[ettmc_mineral_compare]` — displays all price tables and trade opportunity sections.
* `[ettmc_mineral_profile]` — displays the EVE SSO character connect/disconnect card with skill and fee breakdown.

== Installation ==

1. Ensure ETT Price Helper v1.8.2+ is installed and active.
2. Upload the `ett-mineral-compare` folder to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Add the shortcodes to any page or post.
5. Run a price job in ETT Price Helper to populate order book and trend data.

== Frequently Asked Questions ==

= Do I need an EVE character connected? =
No. Without a connected character the plugin uses default fees (3% broker, 8% sales tax). Connect a character via the `[ettmc_mineral_profile]` shortcode to apply your actual skill-based rates.

= Why are Extended Trade Opportunities empty? =
Order book data is captured during ETT Price Helper price runs. Run at least one price job with ETT Price Helper v1.8.2+ to populate the data.

= Why are there no trend arrows? =
Trend data is populated during ETT Price Helper history jobs. Run at least one history job to see trend indicators.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
