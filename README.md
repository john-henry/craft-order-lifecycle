[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-order-lifecycle?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-order-lifecycle)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/order-lifecycle?craft5)

![Order Lifecycle for Craft Commerce](https://johnhenry.ie/images/plugins/promos/order-lifecycle/1.png)

# Order Lifecycle for Craft Commerce

When a customer rings about an order, open it and see every step it took: the cart, checkout, each payment attempt, the emails and any refund. Order Lifecycle records all of it on the order's own page in Craft Commerce, and adds store figures and optional AI insights on top.

## Features

- Each stage of an order is recorded without code of your own (checkout starting is the one exception, and needs a single Twig tag): the cart being made, line items added or changed, coupons, addresses, the customer, the shipping method, status changes, payments attempted, authorised, captured and refunded, and order emails sent or failed.
- The timeline sits on the order's own edit screen. You can filter it by category, and each step shows what changed and how long it was since the one before. Green marks the order going through and red a failure, the milestones (paid, completed, refunded, status changes, emails) stand out, and the same event logged over and over shows once with a count.
- Every event keeps a snapshot of the order as it was at that moment, a click away on the timeline.
- The Overview page and a dashboard widget show conversion, cart abandonment, checkout and completion times, payment retries, returning customers, cart value and email delivery.
- Stats, AI insights and exports follow whichever store you have picked in the control panel.
- Claude can look at one order or the whole store when you ask, and point out friction, payment trouble and what's worth acting on. You bring your own Anthropic API key, and the customer's name, email and address are never sent.
- Export lifecycle events to CSV from the Export page, picking your own columns, dates and event types, or just the one order.
- Switch each event category on or off in the control panel, and choose whether IP addresses and user IDs are recorded.
- IP addresses aren't collected unless you turn it on, old logs can be pruned on a schedule, and one console command erases a customer's history when they ask.
- Exporting and generating insights have permissions of their own, on top of Commerce's own order access.
- Developers get a Twig variable for recording when checkout starts, and PHP services for logging, reading, updating and deleting events from their own code.
- It uses Craft's own control panel UI and works with the keyboard. It hasn't been tested with a screen reader yet; the [accessibility statement](https://johnhenry.ie/plugins/order-lifecycle/docs/extra/accessibility) has the detail.

## Documentation

Full documentation is at [johnhenry.ie/plugins/order-lifecycle/docs](https://johnhenry.ie/plugins/order-lifecycle/docs/getting-started/overview).

## Requirements

- Craft CMS 5.0 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

## Accessibility

How accessible the plugin is, what's been checked, and how to report a problem are all in the [accessibility statement](https://github.com/john-henry/craft-order-lifecycle/blob/craft-5/ACCESSIBILITY.md).

## Support

Need a hand? Open an issue on the [GitHub Issues page](https://github.com/john-henry/craft-order-lifecycle/issues).

## License

Proprietary. Copyright (c) 2026 John Henry Donovan.

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>
