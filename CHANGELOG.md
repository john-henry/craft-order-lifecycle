# Release Notes for Order Lifecycle

## 2.0.1 - 2026-11-27

### Changed
- The order timeline uses three icon colours: grey for activity, green for an order going through, red for a failure.
- Milestone events (completed, paid, refunded, status changes and emails) stand out on the timeline.
- Identical events logged within a minute of each other with nothing changed now show once, with a count.
- The timeline no longer shows "+0s since previous" on events logged at the same moment.
- Changes on the timeline show money as currency, true and false as Yes and No, and an empty value as a dash.

### Fixed
- The Lifecycle Stats dashboard widget no longer breaks the whole Dashboard.
- Refund amounts on the timeline show in the order's currency.
- Screen readers now announce the column name on each switch in the export's column list.
- The "Select All" button on the Export page is readable against its background.
- Guest orders no longer show as registered customers on the timeline or in AI insights. Events already logged keep their old label.
- The no-API-key notices point to the right settings page.

## 2.0.0 - 2026-09-22 [CRITICAL]

> [!IMPORTANT]
> The plugin's permissions have new handles: `order-lifecycle:export-events` and
> `order-lifecycle:generate-insights`. The update moves existing user and group grants across for you,
> but any code of your own that checks the old handles needs changing.

> [!NOTE]
> Log times are now stored in UTC, and the update converts the existing ones from your system timezone.
> Entries written by a control panel user with a timezone preference of their own can't be told apart
> from the rest, so those few may sit out by that user's offset.

### Added
- A setting to switch order email logging on and off.
- `order-lifecycle/logs/erase` deletes one customer's history, by `--email` or `--order`.
- The settings page now flags any value set in `config/order-lifecycle.php`.
- Export column rows can now be reordered with the keyboard, not just by dragging.

### Changed
- `craft.orderLifecycle` now only offers `logCheckoutStarted(order)`, `getPreviousOrderCount(order)` and `decodeStructuredInsights(message)`. Use `OrderLifecycle::getInstance()` from PHP for anything else.
- New installs no longer record shoppers' IP addresses unless you turn it on. Existing installs keep what they had.
- The Anthropic API key setting only takes an environment variable, like `$ANTHROPIC_API_KEY`.
- Exporting needs Commerce's "Manage orders" permission as well, and only exports the store you're looking at. Each export is written to the log.
- The dashboard, widgets and AI insights follow the control panel's site switcher on multi-store installs.
- The dashboard widgets are only offered to people who can see orders.
- AI prompts describe what happened to an order without the customer's name, email or address.
- Auto-prune runs on the queue now, in batches.
- A cart stops recording events once it has 500.
- AI insight requests are capped at 20 a minute per person.
- Only an order's 250 most recent events are sent to Claude.
- The package is about half the size.

### Fixed
- Event times showed an hour or more out on sites not set to UTC.
- Trashing an order wiped its timeline.
- A refund the gateway declined was logged as refunded.
- Payment Authorized events now record the amount authorised.
- Failed order emails now show the email's name.
- A coupon, address or customer change made in the same save as a line item change could go unrecorded.
- Bulk resaves no longer add events to every order.
- A logging failure could roll back the order save it was part of.
- Generating insights now checks you can see the order.
- Bold text in a structured order analysis showed its asterisks instead of being bold.
- The timeline now uses the order's own store's statuses.
- The order edit screen loads faster on long timelines.
- A failing dashboard widget could stop the whole dashboard loading.
- Exporting a large log could run out of memory.
- Picking event types on the Export page that matched nothing exported every event.
- Exported dates are in the site's timezone, and the message column has no HTML in it.
- Stats load faster on large logs.
- Store insights no longer count declined refunds.
- Saving settings in the control panel copied values from `config/order-lifecycle.php` into project config.
- An Anthropic API key from an environment variable is now trimmed.
- The purge commands now say so when there's nothing to delete, and `logs/purge` refuses `--days` below 1.
- The field no longer offers a Required toggle or translation options, and stays out of GraphQL.
- Strings in the control panel's JavaScript and templates can now be translated.
- The settings model now fires Craft's `defineRules` event.
- The example config file now lists `asyncLogging`.
- Screen readers now announce when AI insights are generating, and when they finish or fail.
- A successful payment now shows a status label, not just an icon colour.
- Decorative icons in the event timeline and dashboard alerts are no longer read aloud by screen readers.
- Currency symbols and other non-English characters, and slashes in links, now show as written in the timeline's change table and snapshots, rather than as codes like `\u20ac`.

### Security
- `craft.orderLifecycle` handed any template, front end included, the full logger and services, so a template could read or delete any order's history.
- Event messages and saved AI insights are sanitised before they're shown.
- The plugin's control panel pages could be loaded by URL without their permission checks.
- The exported CSV tells browsers not to treat it as a web page.

## 1.0.5 - 2026-07-09

### Fixed
- Shipping Method Set and address events were logged when nothing had really changed.

## 1.0.4 - 2026-07-08

### Fixed
- Events logged from a queue job, such as order email events, failed and were never recorded.
- A normal Stripe checkout was recorded as a failed payment.
- A failure while logging could make Commerce treat a successful payment as failed, or resend an order email.

## 1.0.3 - 2026-07-06

### Changed
- Empty carts are no longer counted in the Conversion and Abandonment stats.
- Store-wide AI insights now generate straight away on the page, with no queue worker needed.
- The AI Insights page and widget have a tidier layout, and the page has a short intro.

### Fixed
- Store-wide AI insights disappeared on reload and never showed on the dashboard widget.
- Copying AI insights copied the raw Markdown instead of the readable text.

## 1.0.2 - 2026-07-06

### Fixed
- The Abandonment stat could show a rate over 100%.
- The Order Lifecycle menu didn't show for non-admins with the "Access Order Lifecycle" permission. If a group had "Access dashboard" before, give it "Access Order Lifecycle" instead.
- Shipping methods from some third-party plugins showed with a blank name on the timeline.

## 1.0.1 - 2026-07-06

### Changed
- The AI Insights page now matches the dashboard widget's card and button.

### Fixed
- The Cart Value stat sometimes showed the wrong currency.
- The Abandonment and Conversion stats didn't agree with each other.
- A cart counted as abandoned straight away instead of after an hour with no activity.
- On multi-store installs, stats, AI insights and the stats widget could mix in or show another store's figures.

## 1.0.0 - 2026-07-05

### Added
- Store-wide AI insights can be generated from the widget, with a period picker and a notes field.
- A Performance guide with recommended settings for busy stores.
- A Field Type guide.
- A "Per-Order Insights Style" setting: a structured view with action cards, or a plain summary.
- The timeline can be filtered by event category and sorted oldest or newest first.
- Each timeline event shows what changed since the previous one, with the full snapshot a click away.
- The stats widget's Top Events list can be switched off.

### Changed
- First stable release.
- Each logged event takes up less room in the database.
- Better keyboard navigation, screen reader support and colour contrast in the control panel.
- Top Events shows readable event names.
- The AI Insights widget and page share one layout, and the widget no longer has a settings screen.
- The dashboard stats widget has a cleaner layout, with the order count in its title bar.
- Timeline events show the time since the previous event, and the date only when the timeline spans more than one day.
- Generating an order's analysis shows a loading state.
- Order Completed and Order Paid have their own icons.
- Status badges only show on timeline events when they add something the title doesn't.
- Line item messages show the product name instead of the SKU.
- Address messages no longer repeat the event title.
- The AI Insights widget uses the same card style as the order analysis panel.

### Fixed
- Country changes on shipping and billing addresses weren't recorded.
- AI Insights widget results didn't load after generating, showed as raw text, and the button lost its label.
- The Auto-Prune Logs setting didn't save.
- Slow CSV exports over large date ranges.
- An order confirmation email could be sent more than once under load.
- Change messages could describe the wrong prior state when async logging was on.
- Billing and Shipping Address Set showed no changes when both were saved together.
- Sent emails showed no name in the log.
- Cart Created, Checkout Started, Coupon Applied, Coupon Removed and Order Completed events had no description.
- Payment Refunded events now show the amount, gateway and refund note.
- Fields that were never set no longer show as a change from `null` to empty.
- Several smaller display and settings-saving bugs.

### Security
- Customer-entered order data could be shown as unescaped HTML in the event log.
- Saving plugin settings is better protected against unauthorised changes.
- AI prompts are protected against instructions hidden in customer-entered order data.
- Exported values starting with `=`, `+`, `-` or `@` could run as formulas in Excel or Google Sheets.
- CSV exports need a POST request, so a crawled or bookmarked link can't start one.

## 1.0.0-beta.1 - 2026-06-05

### Added
- Initial beta release
