=== Bizuno RESTful API for WooCommerce ===
Contributors: phreesoft
Tags: woocommerce, erp, accounting, rest-api, inventory
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 7.4.3
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Real-time WooCommerce sync with Bizuno ERP & Accounting: push orders, pull inventory, prices and customers through a secure REST API.

== Description ==

**Bizuno RESTful API for WooCommerce** is the connector that links your WordPress/WooCommerce store to the Bizuno Accounting/ERP system, so orders, customers, inventory, prices and payments stay in sync without manual exports or double entry.

It works with self-hosted Bizuno or PhreeSoft Cloud, and authenticates with a shared secret token so your store and your books can live on separate domains.

= Why use it =

* **WooCommerce to Bizuno orders** — push WooCommerce orders into Bizuno as sales orders or invoices, automatically or on demand from the order screen.
* **Bizuno to WooCommerce inventory & prices** — keep stock levels and pricing accurate across both platforms.
* **Customer sync** — share customer records so you have one view across store and back office.
* **Bizuno shipping rates** — optionally calculate WooCommerce shipping through Bizuno.
* **Secure token authentication** — a shared `X-Bizuno-Token` secret over HTTPS; no store credentials are exposed.
* **Standalone** — runs as an ordinary WordPress plugin; the Bizuno core library is not required on the store site.

This plugin is the companion to the Bizuno Accounting portal. Use it alongside self-hosted Bizuno or PhreeSoft Cloud for the complete e-commerce + accounting experience.

== Installation ==

1. Install and activate **Bizuno RESTful API for WooCommerce** from the WordPress Plugins screen. WooCommerce must be installed and active.
2. Go to **Settings → Bizuno → General** and enter your connection details: Server URL, REST user name, REST password, and API token (generated in your Bizuno instance under the API settings).
3. Open the **Bizuno API** settings tab and choose your sync options: stock management, backorders, order/customer prefixes, target journal, and auto-download.
4. Save. Orders can now be exported to Bizuno from the WooCommerce order list or the single-order screen, and inventory/price updates flow back to WooCommerce.

== Frequently Asked Questions ==

= What does this plugin do? =
It provides a secure REST bridge so WooCommerce (or your own code) can exchange orders, inventory, prices and customers with your Bizuno ERP/Accounting system, with no manual import/export.

= Do I need the Bizuno Accounting plugin on my store? =
No. Bizuno can live on a separate domain or sub-domain to keep your books isolated from your public store. This plugin only needs the connection settings to reach it.

= Is it secure? =
Yes. It uses a shared token sent in the `X-Bizuno-Token` request header over HTTPS and follows WordPress and WooCommerce security best practices.

= Can inventory and prices sync automatically? =
Yes. Bizuno can push inventory and price changes to WooCommerce, and new WooCommerce orders can be pushed to Bizuno automatically or on demand.

= Where do I get an API token? =
Generate it in your Bizuno instance under the API settings, then paste it into Settings → Bizuno → General on your store.

== Screenshots ==

1. Settings → Bizuno → General tab: shared connection details (Server URL, REST user name, password, API token).
2. Bizuno API settings tab: sync options for stock management, backorders, order/customer prefixes, journal and auto-download.
3. WooCommerce order list showing the Exported column and the "Export to Bizuno" row action.
4. Single WooCommerce order screen with the "Export to Bizuno" action.
5. Bizuno Shipping method available in a WooCommerce shipping zone.

== Changelog ==

= 7.4.3 =
* Fixed: REST authentication rejected a plain WordPress user name (only an email address worked), which blocked product upload, refresh, sync and shipment confirmation from Bizuno.
* The product refresh endpoint now returns the number of products updated.
* Fixed: REST responses only returned error messages, so Synchronize Products and Confirm Shipments showed nothing in Bizuno; success and info results are now returned too.
* Product page Volume Pricing table shows the Bizuno sell unit name when the price tiers carry one.

= 7.4.2 =
* Renamed to "Bizuno RESTful API for WooCommerce" for the WordPress.org Plugin Directory; text domain updated to match the slug.
* Decoupled from the Bizuno core library — now a fully standalone WordPress plugin using native WordPress messaging.
* Shared connection settings (Server URL, REST user name/password, API token) consolidated into a single Settings → Bizuno → General tab used by all Bizuno WooCommerce plugins.
* Token authentication via the `X-Bizuno-Token` request header.
* Declares WooCommerce as a required plugin and adds WooCommerce version compatibility headers.

= 7.3.7 =
* Code fixes to pass WordPress Plugin Check.

= 7.3.6 =
* Modernized for PHP 8.x and current WordPress; improved REST security and token handling; better WooCommerce support; enhanced logging.

= 7.3.4 =
* Architectural alignment with Bizuno 7.3+ portal changes.

= 7.0 =
* Initial public release for PhreeSoft Cloud and self-hosted Bizuno customers.

== Upgrade Notice ==

= 7.4.2 =
Plugin renamed and now fully standalone. After upgrading, confirm your connection details under Settings → Bizuno → General (Server URL, REST user name/password, API token).

== About PhreeSoft ==

PhreeSoft has built open-source accounting and ERP tools since creating PhreeBooks in 2007. **Bizuno** is our modern flagship, and this plugin connects your WooCommerce store to it.

* https://www.phreesoft.com
* https://www.bizuno.com
* https://github.com/phreesoft/bizuno-restful-api-for-woocommerce
