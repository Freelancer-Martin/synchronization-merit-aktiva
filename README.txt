=== Orders Synchronization for Merit Aktiva ===
Contributors: freelancermartin
Donate link: https://freelancermartin.com/et
Tags: woocommerce, merit aktiva, invoicing, accounting, estonia
Requires at least: 6.7
Tested up to: 7.0
Stable tag: 1.2.5
Requires PHP: 8.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically synchronize WooCommerce orders with Merit Aktiva accounting software.

== Description ==

This plugin synchronizes WooCommerce orders with Merit Aktiva accounting software. A sales invoice is automatically created in Merit Aktiva for every new paid order — no manual work required.

Requires at least the Merit PRO package.

== Features ==

* Automatic invoice creation in Merit Aktiva for every new WooCommerce order (cron, every 5 minutes)
* Manual synchronization from the admin panel
* Estonian banking reference number generation (7-3-1 algorithm) with configurable prefix
* B2B support: company name, Estonian business registry code (validated) and VAT number at checkout
* Multiple Merit Aktiva warehouses: default warehouse, per-product and per-category warehouse
* Item type setting: stock item, non-stock item or service
* VAT types loaded from Merit Aktiva, separate VAT type for shipping
* B2C support: private customer name and contact details
* Payment method mapping (WooCommerce payment method → Merit Aktiva payment code)
* Automatic credit invoice when an order is fully refunded
* Invoice PDF download directly from Merit Aktiva within the WC order view
* Invoice reconciliation: detect discrepancies between WC and Merit Aktiva
* Sync history log with pagination
* API connection test in settings
* Settings export and import (JSON)
* WooCommerce HPOS (High-Performance Order Storage) support
* Toast notifications for sync results (top-right corner, disappear after 5s)

== External Services ==

This plugin connects to the Merit Aktiva API v2 (aktiva.merit.ee).

Data transmitted:
* API authentication (ApiId, timestamp, HMAC-SHA256 signature)
* Invoice data: customer name, address, email, phone number
* Product codes, descriptions, quantities, prices, VAT, warehouse codes
* Customer lookup by name and customer creation (getcustomers, sendcustomer)
* Read-only lookups of VAT types and warehouses (taxes, getlocations)
* Reference number (Estonian 7-3-1 standard), invoice number, payment due date

Data is transmitted every time an invoice is sent (automatically or manually).

Service provider: Merit Aktiva (merit.ee)
Terms of Service: https://www.merit.ee/wp-content/uploads/2024/12/merit-software-terms-of-use-2024.pdf
Privacy Policy: https://www.merit.ee/wp-content/uploads/2024/12/privacy-statement-2024.pdf

== Requirements ==

* WordPress 6.7 or newer
* WooCommerce 8.6 or newer
* PHP 8.0 or newer
* Merit Aktiva account with API access (PRO package or higher)

== Installation ==

1. Download the plugin ZIP file
2. Go to WordPress admin → Plugins → Add New → Upload Plugin → select the ZIP
3. Activate the plugin
4. Go to Merit Aktiva Sync → Settings
5. Enter your Merit Aktiva API ID and API Key (found in Merit Aktiva → Settings → Integrations)
6. Save and test the API connection

== Configuration ==

**API Settings:**
* API ID and API Key — from your Merit Aktiva account
* Reference number prefix — prepended to the reference number (digits only)
* Payment deadline in days
* Contact person — displayed on the invoice
* VAT type — press "Lae Merit API-st" to load VAT types from Merit Aktiva; optional separate VAT type for shipping

**Warehouses and item type:**
* Default warehouse — press "Lae laod Meritist" to load warehouses from Merit Aktiva. Choose "— Ladu ei saadeta —" to send no warehouse.
* Per-product warehouse — Products → edit product → Inventory → "Merit Aktiva ladu"
* Per-category warehouse — Products → Categories → "Merit Aktiva ladu" (also applies to subcategories)
* Order of precedence: product → parent product (variations) → category → default warehouse
* Item type — stock item (uses warehouse), non-stock item or service (no warehouse sent). Use non-stock item if you do not track inventory in Merit Aktiva.

**Payment Method Mapping:**
Link WooCommerce payment methods to Merit Aktiva payment codes (e.g. "Bank Transfer" → "T").

**Business customers at checkout:**
Customers can check "Olen ettevõte" (I am a company) and enter the company name, business registry code and VAT number. Company name and registry code are required when ordering as a company. The registry code is validated using the Estonian business registry algorithm (mod-11).

== Usage ==

Orders are synchronized automatically every 5 minutes. For manual sync:
* All orders: Merit Aktiva Sync → Settings → "Sync all orders"
* Single order: WooCommerce → Orders → open order → "Send invoice to Merit Aktiva"

== Changelog ==

= 1.2.5 =
* New: Merit Aktiva warehouse selection — default warehouse in settings (loaded from Merit via getlocations), per-product and per-category warehouse. Fixes "Puudub ladu koodiga 1." when the company has no warehouse with code 1
* New: item type setting (stock item / non-stock item / service); warehouse is sent only for stock items
* New: Item.DefLocationCode is sent for stock items (required by Merit when the company has several warehouses)

= 1.2.4 =
* Fix: add-on prices from product field plugins (e.g. Advanced Product Fields gift card +1 €) now reach Merit — line price is taken from the order line instead of the catalog price
* Fix: order fees are now sent as invoice rows ("Ridade summa ei võrdu arve summaga" error)
* Fix: TotalAmount is sent net of VAT and TaxAmount carries the actual VAT
* Fix: private customers are no longer merged into the first private customer card in Merit — customer is looked up by exact name or created, and the invoice is sent with its CustomerId
* Fix: extra spaces in customer names no longer create duplicate customers
* Fix: VAT types can be loaded from Merit (v2/taxes endpoint) and the loaded list is remembered
* Fix: credit invoices use order line prices and include fees and discounts
* New: invoice sending stops with a clear message if rows differ from the order total by more than 0.05 €
* New: separate VAT type setting for shipping rows (SmartPost, Omniva, courier); invoice VAT summary is split by VAT type
* New: "Ettevõtte nimi" checkout field shown with "Olen ettevõte"; company name and registration code are required when ordering as a company
* Fix: "Olen ettevõte", registration code and KMKR from the block checkout were not read from the order (wrong meta keys), so company orders were sent to Merit as private persons

= 1.2.3 =
* Fix: RoundingAmount calculation now includes VAT (TaxAmount) per line so the comparison against the gross WooCommerce order total is correct when prices are stored excluding tax

= 1.2.2 =
* Fix: invoice amount mismatch error resolved by sending Merit API RoundingAmount field — corrects 0.01–0.02 floating-point rounding differences between WooCommerce order total and sum of individual line items

= 1.2.1 =
* Fix: myplugin/ field prefix renamed to merit-aktiva/ with automatic database migration
* Fix: text domain corrected to synchronization-merit-aktiva

= 1.2.0 =
* Toast notifications for sync results (red/green, top-right corner, disappears after 5s)
* Fix: incorrect NotTDCustomer value for B2C customers (false → true)
* Fix: trim whitespace from customer name

= 1.1.5 =
* Configurable reference number prefix in settings
* Fix: RefNo now generated using 7-3-1 algorithm (Merit API requirement)
* Payment method mapping moved into general settings

= 1.1.0 =
* Redux-style admin UI (dark sidebar, cards)
* Sync history log with pagination (15 entries per page)
* Automatic API test after saving settings
* Settings export and import
* Invoice reconciliation between Merit Aktiva and WooCommerce

= 1.0.0 =
* Initial release
* WooCommerce order export to Merit Aktiva
* Automatic cron synchronization

== Support ==

Email: freelancermartin1@gmail.com
Website: https://freelancermartin.com/et
