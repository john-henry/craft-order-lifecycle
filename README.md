
[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-order-lifecycle?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-order-lifecycle)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/order-lifecycle?craft5)

<p align="center"><img width="120" height="120" alt="craft-order-lifecycle-plugin-icon" src="https://johnhenry.ie/images/plugins/craft-order-lifecycle.svg"></p>

<h1 align="center">Order Lifecycle for Craft Commerce</h1>

Keeps track of every event in a Craft Commerce order's journey, from the first cart through to payment, refund and beyond, with a timeline view and analytics to go with it.

## Features

- **Event tracking** – Records every stage of an order automatically: cart created, line items added or changed, coupons applied, addresses set, shipping method chosen, payments attempted, captured and refunded, emails sent or failed, and status changes
- **Timeline view** – A clear, filterable timeline of the whole order journey on the order's own edit screen, with each event showing exactly what changed since the one before it
- **Order snapshots** – Every event stores a full snapshot of the order at that moment, so you can see the state it was in when anything happened
- **Store statistics** – Conversion rate, abandonment, average cart value, checkout and completion times, payment retries and email success, over the period you choose
- **AI insights** – On-demand summaries of a single order or your whole store, written by Claude, calling out the anomalies and what's worth acting on (bring your own Anthropic API key)
- **Dashboard widgets** – A stats widget and an AI insights widget for the Craft dashboard
- **CSV export** – Export lifecycle events for a date range or specific orders, with your own choice of columns, for analysis in a spreadsheet or BI tool
- **Order field** – A summary field showing an order's key conversion metrics at a glance
- **Log maintenance** – Auto-prune old logs on a schedule, or purge them from the command line, so the table never runs away on you
- **Native UI** – Fits right into the Craft control panel, keyboard and screen-reader friendly

## Perfect for

- Tracking the full lifecycle of every Craft Commerce order
- Diagnosing checkout friction, payment failures and cart abandonment
- Surfacing store-wide conversion and payment analytics with AI insights

## Documentation

Full documentation is at [https://johnhenry.ie/plugins/order-lifecycle/](https://johnhenry.ie/plugins/order-lifecycle/)

## Requirements

- Craft CMS 5.0 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

## Support

For support, drop by the [GitHub Issues page](https://github.com/john-henry/craft-order-lifecycle/issues).

## License

Proprietary - Copyright (c) 2026 John Henry Donovan

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
