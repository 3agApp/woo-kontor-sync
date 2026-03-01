=== Woo Kontor Sync ===
Contributors: 3agApp
Tags: woocommerce, kontor, crm, sync, products, import
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize WooCommerce products from Kontor CRM with automatic scheduling, image sideloading, and detailed logging.

== Description ==

Woo Kontor Sync is a premium WordPress plugin that automatically imports and updates your WooCommerce products from Kontor CRM.

**Key Features:**

* **Automatic Sync** – Schedule product imports from every 5 minutes to weekly
* **Full Product Import** – Imports name, description, price, stock, weight, images, and more
* **Category Mapping** – Automatically creates and assigns product categories from Kontor
* **Image Sideloading** – Downloads and attaches product images with intelligent change detection
* **EAN, MPN, Manufacturer** – Stores additional product metadata from Kontor
* **Paginated API** – Fetches products in configurable batches for optimal performance
* **Watchdog Protection** – Automatic recovery if scheduled sync stops working
* **Detailed Logs** – Complete history of all sync operations with statistics
* **Modern Admin UI** – Clean, intuitive dashboard with charts and activity tracking
* **Auto Updates** – Automatic updates from GitHub Releases
* **License Management** – Secure license activation via 3AG License API

**Product Fields Synced:**

* Article Number (SKU)
* Product Name
* Long Description
* Retail Price (UVP)
* Weight
* Stock Level
* Category
* EAN/Barcode
* Manufacturer
* MPN
* Cost Price
* Up to 10 product images

== Installation ==

1. Download the latest release from [GitHub Releases](https://github.com/3agApp/woo-kontor-sync/releases)
2. Upload the plugin files to `/wp-content/plugins/woo-kontor-sync`
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Go to **Kontor Sync → License** to activate your license key
5. Configure your API settings in **Kontor Sync → Settings**
6. Enable scheduled sync or run a manual sync from the **Dashboard**

== Frequently Asked Questions ==

= What is the Kontor API endpoint used? =

The plugin uses `POST /api/v1/kontor/search` with `x-api-key` header for authentication.

= How are products matched? =

Products are matched by SKU (Artnr field from Kontor). If a product with the same SKU exists, it is updated; otherwise, a new product is created.

= How are images handled? =

Images are downloaded from the configured Image Prefix URL + filename. The plugin uses WordPress media handling with change detection to avoid re-downloading unchanged images.

= What happens if the API is unavailable? =

The sync will fail gracefully and log the error. The watchdog cron ensures the schedule is restored if it stops working.

== Changelog ==

= 1.0.1 =
* Version bump

= 1.0.0 =
* Initial release
* Product import/update from Kontor CRM API
* Automatic scheduled sync with configurable intervals
* Image sideloading with change detection
* Category auto-creation and assignment
* Watchdog cron protection
* License management via 3AG License API
* Auto-updates from GitHub Releases
* Modern admin dashboard with charts and logs
