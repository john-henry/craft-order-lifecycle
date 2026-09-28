[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-order-lifecycle?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-order-lifecycle)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/order-lifecycle?craft5)

![Order Lifecycle for Craft Commerce](https://johnhenry.ie/images/plugins/promos/order-lifecycle/1.png)

# Order Lifecycle for Craft Commerce

When a customer rings about an order, open it and see every step it took: the cart, checkout, each payment attempt, the emails and any refund. Order Lifecycle records all of it on the order's own page for Craft Commerce, with store analytics and optional AI insights to go with it.

## Features

- **Automatic event tracking**: Records every stage of an order with no extra code: cart created, line items added or changed, coupons, addresses, customer, shipping method, status changes, payments attempted, authorised, captured and refunded, and order emails sent or failed
- **Timeline view**: The whole order journey on the order's own edit screen, filterable by category, with what changed at each step and how long since the last one
- **Order snapshots**: Every event keeps a snapshot of the order at that moment, a click away on the timeline
- **Store statistics**: Conversion, cart abandonment, checkout and completion times, payment retries, returning customers, cart value and email delivery, on the Overview page and a dashboard widget
- **Multi-store aware**: Stats, AI insights and exports follow the store you have picked in the control panel
- **AI insights**: On-demand analysis from Claude of a single order or the whole store, pointing out friction, payment trouble and what's worth acting on. The customer's details are never sent (bring your own Anthropic API key)
- **CSV export**: Export lifecycle events from the Export page, with your own choice of columns, dates, event types and order
- **Configurable logging**: Switch each event category on or off from the control panel, and choose whether to record IP addresses and user IDs
- **Privacy and retention**: IP collection is off by default, old logs can be pruned on a schedule, and one console command erases a customer's history when they ask
- **Permissions**: Separate permissions for exporting and generating insights, on top of Commerce's own order access
- **Developer API**: A Twig variable for recording when checkout starts, and PHP services for logging, reading, updating and deleting events from your own code
- **Native UI**: Fits right into the Craft control panel, keyboard and screen-reader friendly

## Perfect For

- Tracking the full lifecycle of every Craft Commerce order
- Diagnosing checkout friction, payment failures and cart abandonment
- Surfacing store-wide conversion and payment analytics with AI insights

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
