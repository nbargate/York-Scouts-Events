=== York Scouts Events ===
Contributors: York Scouts
Requires at least: 6.3
Requires PHP: 7.4
Requires Plugins: woocommerce, the-events-calendar
Stable tag: 1.0.15
License: GPLv2 or later

Ticket sales and booking management for The Events Calendar using WooCommerce checkout.

== Description ==

York Scouts Events adds per-event ticket sales to The Events Calendar events. The Events Calendar event remains the only event record; booking settings and counters are stored as meta on tribe_events posts.

Bookings are stored in a dedicated database table named wp_ys_bookings on standard WordPress installs, or {prefix}_ys_bookings when a custom database prefix is used. The table is queried with indexes, limits and offsets, so admin booking views do not load every WooCommerce order or every booking into memory.

== Features ==

* The Events Calendar is the master event system.
* No custom ys_event post type is registered.
* One hidden WooCommerce product is reused for each event.
* Booking rows are created from WooCommerce order line items.
* Sold, reserved and remaining counters are stored per event and per slot.
* Counters are updated when WooCommerce order statuses change.
* Admin booking overview supports search, pagination, event filter, status filter and booking date filters.
* Bookings are available from a top-level WordPress admin menu item.
* Event editor meta box supports enable bookings, capacity, timed slots, ticket price and field switches.
* Timed events support automatic evenly spaced slots or explicit custom slots with independent timings and capacity.
* Event editors can add custom booking or per-participant information fields from the WordPress UI.
* Booking forms collect parent contact and emergency contact details per participant, with a per-participant parent-contact reuse checkbox.
* Organisers can receive and download readable participant information PDF reports.
* Admins can export per-event booking and participant parent email lists for Gmail or other email tools.
* Booking access can be delegated through Members by MemberPress using York Scouts-specific capabilities.
* Legacy or missing event booking references can be reviewed and cleaned from the bookings screen.
* Legacy booking order-item metadata is imported in small batches without loading all orders.
* Public booking forms, admin booking screens and participant PDFs use the Skills for Life visual style.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate WooCommerce and The Events Calendar before activating York Scouts Events.
3. Activate York Scouts Events.
4. Edit a The Events Calendar event and enable York Scouts ticket sales.

== Upgrade Notes ==

Version 1.0.0 creates the ys_bookings table and removes the old custom event post type registration. Existing booking data stored on WooCommerce order items is imported into the bookings table in small batches through admin requests and WP-Cron.

== Permissions ==

York Scouts Events adds custom WordPress capabilities so role plugins such as Members by MemberPress can grant booking access without broad WooCommerce permissions:

* ys_events_view_bookings
* ys_events_manage_bookings
* ys_events_download_reports
* ys_events_export_emails
* ys_events_manage_booking_settings
* ys_events_manage_cleanup

Administrators receive all York Scouts capabilities automatically. WooCommerce shop managers receive booking view, report download and email export access automatically. Custom team roles should be granted the required York Scouts capabilities in Members by MemberPress.

== Changelog ==

= 1.0.15 =
* Fixed participant report email attachments so they arrive with the intended .pdf filename instead of a temporary .tmp filename.
* Added a manual participant PDF email action to the selected-event Bookings screen for repeatable testing and operational sends.
* Decoded event titles in participant report email subject and body text.

= 1.0.14 =
* Added a live public availability refresh so cached event pages update remaining places, timed-slot availability and booking nonces before submission.
* Kept checkout submission validation as the final protection against overbooking.

= 1.0.13 =
* Automatically mark future paid WooCommerce orders as completed when every line item is a York Scouts event ticket.
* Mixed baskets containing non-event products keep WooCommerce's normal paid order status.

= 1.0.12 =
* Added York Scouts-specific capabilities for bookings, reports, email exports, booking settings and cleanup.
* Moved the top-level Bookings screen away from the broad manage_woocommerce capability.
* Restricted event booking settings to users who can edit the event and have ys_events_manage_booking_settings.
* Updated report downloads, email exports and cleanup actions to use dedicated capabilities.

= 1.0.11 =
* Added parent email and parent tel number fields to each participant.
* Changed the per-participant emergency contact shortcut to reuse parent contact details.
* Updated the accessibility field wording across forms, admin details, WooCommerce metadata and participant PDFs.
* Updated per-event email exports to include participant parent emails in paged batches.

= 1.0.10 =
* Moved emergency contact collection from the overall booking to each participant.
* Added a per-participant checkbox to reuse booking contact details as emergency contact details.
* Updated admin booking details, WooCommerce order item metadata and participant PDFs to show emergency contacts per participant.

= 1.0.9 =
* Moved the Bookings screen out from under Events into a top-level WordPress admin menu item.
* Updated internal booking links to use the new top-level admin page URL.

= 1.0.8 =
* Updated public booking form visuals to match the Skills for Life theme typography, colours and button treatment.
* Updated admin booking and event ticket settings screens with scoped Scouts theme styling.
* Updated participant PDF reports with Scouts purple, blue and yellow visual hierarchy.

= 1.0.7 =
* Added an emergency contact same-as-booking-contact checkbox to the public booking form.
* Emergency contact validation now accepts the checkbox and stores a readable booking contact summary.

= 1.0.6 =
* Improved participant PDF layout with a clear report header, summary blocks, booking sections and participant blocks.
* Added explicit white page backgrounds for more reliable PDF rendering.
* Added per-event booking contact email exports as plain text files with one unique active booking email per line.

= 1.0.5 =
* Added custom information fields to the event editor.
* Custom fields can apply to the overall booking or repeat for each participant.
* Custom answers are validated, stored in booking data, shown on WooCommerce order items, visible in admin booking details and included in participant PDFs.

= 1.0.4 =
* Added a legacy/orphaned booking cleanup panel to the bookings admin screen.
* Non-Events-Calendar event references no longer appear in the main event selector.
* Cleanup removes only York Scouts Events booking rows, not WooCommerce orders or payments.

= 1.0.3 =
* Changed the bookings admin screen to require an event selection before showing bookings.
* Booking lists are now scoped to one event at a time, with status, date and search filters applied within that event.

= 1.0.2 =
* Added participant information PDF reports.
* Added organiser report recipients and booking close time settings.
* Added automatic report sending at 00:01 on the event day, booking close, or sell-out, whichever sends first.
* Added participant PDF download actions in event and booking admin screens.

= 1.0.1 =
* Added custom timed slots for events that need non-uniform timings, such as lunch breaks or variable session lengths.
* Added per-custom-slot capacity while retaining the existing automatic evenly spaced slot mode.

= 1.0.0 =
* Refactored into a namespaced object-oriented plugin structure.
* Added dedicated bookings table.
* Removed order scans from capacity, statistics and booking overview flows.
* Added stored event and slot counters.
* Removed custom ys_event post type registration.
* Added paginated booking management screen.
* Added hidden WooCommerce product reuse logic.
