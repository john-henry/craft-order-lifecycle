# Release Notes for Order Lifecycle

## 1.0.0 - 2026-07-05

### Security
- Fixed a stored XSS vulnerability where customer-supplied order data (addresses, coupon codes, SKUs) could end up rendered as unescaped HTML in the event log.
- Hardened the plugin settings save process against unauthorized or unintended changes.
- Added safeguards against AI prompt injection via customer-supplied order data.
- Fixed a CSV injection vulnerability in event exports - a coupon code, SKU, or other order data starting with `=`, `+`, `-`, or `@` could be interpreted as a formula by Excel or Google Sheets when the export was opened.
- CSV exports now require a POST request, closing off accidental large exports triggered by a crawled or bookmarked link.

### Added
- AI-generated store insights now run in the background instead of holding up the page, and can be generated on demand with a Period selector and a Context/Notes field right there in the AI Insights widget - no need to visit a separate settings screen.
- A new Performance guide, with recommended settings for high-traffic checkouts.
- A new Field Type guide, covering where the Order Lifecycle field goes, what it shows, and its empty/error states, in one place.
- A new "Per-Order Insights Style" setting lets you choose how the order's AI analysis is presented: a structured view with a priority-action callout and labeled Action / Info / Good insight cards, or a plain narrative summary.
- The order's Lifecycle timeline can now be filtered by event category (Cart, Line Items, Coupons, Customer, Address, Shipping, Payments, Order, Emails) and sorted oldest-first or newest-first.
- Each timeline event now shows exactly what changed on the order since the previous event, with a "View full snapshot" link alongside it for the full raw detail underneath.
- The Order Lifecycle Stats widget's "Top Events" list can now be turned off in the widget's settings, for stores that just want the stat grid.

### Changed
- First stable release. The plugin is out of beta and ready to run in production.
- - Cut down database storage per logged event by dropping data that was never actually surfaced anywhere in the plugin.
- Improved keyboard navigation, screen reader support, and colour contrast throughout the control panel.
- The "Top Events" list now shows readable event names (e.g. "Billing Address Set") instead of raw internal codes.
- The AI Insights dashboard widget and page now share the same layout and controls, and the widget's separate settings screen is gone, since its one setting now lives directly on the widget.
- Store-wide AI insight generation now has more headroom before Craft's queue considers it stuck, and a failed run now shows up as a retryable failure in the Queue Manager instead of disappearing silently.
- The Lifecycle field's summary cards (Time to Convert, Checkout Duration, Cart Items, Customer) and the Started/Completed bar have a cleaner card layout.
- Timeline events now show the time elapsed since the previous event, and only show the date when the timeline spans more than one day - every event still shows its own time.
- Generating an order's AI analysis now shows a loading indicator with a short preview of what's coming, instead of just a disabled button.
- "Order Completed" and "Order Paid" now use distinct icons in the timeline so the two milestones are easier to tell apart at a glance.
- Status badges on timeline events (Payment Processed, Order Paid, Order Completed, Email Sent, Email Failed) no longer repeat what the event title already says - a badge now only appears when it adds real information, such as a failure or a specific status.
- The Order Lifecycle Stats dashboard widget has a cleaner, single-surface layout, with stats grouped by what they measure (volume, conversion, timing) instead of a loose grid, and the order count moved into the widget's own title bar.
- Line item change messages now show the product and variant name instead of the raw SKU.
- Address change messages no longer repeat "Shipping/Billing address set" - just the address itself, since the event title already says what happened.
- The AI Insights dashboard widget now uses the same card styling as the per-order "Order analysis" panel - a dashed border until insights exist, a plain "Generate Insights" button instead of a purple one - and its description now shows as the widget's subtitle instead of repeating inside the card.

### Fixed
- Shipping and billing country changes are now correctly recorded in the event log.
- Fixed the AI Insights widget: results weren't loading after generation, saved insights showed up as raw text instead of formatted output, the button had lost its icon and label, and the spacing was off.
- Fixed the "Auto-Prune Logs" setting not saving.
- Improved CSV export performance on large date ranges.
- Fixed several minor display and settings-saving bugs.
- Fixed a bug where a Commerce order confirmation email could be sent to the customer more than once for the same order under load.
- Timeline messages for coupon, address, customer, shipping-method, and line-item changes are now always generated from the correct prior state, even when Async Queue Logging is enabled and several events land for the same order in quick succession.
- Fixed "Billing Address Set" and "Shipping Address Set" showing no changes when both addresses were saved together - each event now always shows its own correct field-by-field changes.
- Fixed the sent email's name always showing blank in the event log.
- Fixed "Cart Created", "Checkout Started", "Coupon Applied", "Coupon Removed", and "Order Completed" events not showing any description of what actually happened.
- "Payment Refunded" events now show the amount refunded and the gateway it went through, instead of just the raw gateway/transaction-status field changes.
- "Payment Refunded" events now show "Amount Refunded" and, if one was entered, the refund note in the changes table - the gateway and transaction status shown there previously belonged to the original charge, not the refund, and repeated the gateway already named in the event's message.
- Fields that were never set no longer show up as a change from `null` to an empty value (e.g. "Shipping Method: null → ''") in an event's changes table - only changes with a real value on at least one side are shown.


## 1.0.0-beta.1 - 2026-06-05

### Added
- Initial beta release
