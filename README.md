<div align="center">

# Woo 1C Sync

![WordPress](https://img.shields.io/badge/WordPress-5.0+-21759B?style=for-the-badge&logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-Required-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Version](https://img.shields.io/badge/Version-0.9.20-blue?style=for-the-badge)
![License](https://img.shields.io/badge/License-Apache%202.0-blue?style=for-the-badge)

**Synchronizes WooCommerce with 1C:Enterprise 8 (Trade Management) — catalog, prices, stock, and orders via CommerceML**

[Features](#features) • [Installation](#installation) • [How It Works](#how-it-works) • [Admin Page](#admin-page) • [Configuration](#configuration) • [1C Setup](#1c-setup) • [Project Structure](#project-structure) • [Changelog](#what-changed-since-the-original-plugin)

**Repository:** [github.com/knyazevetc/Woo-1C-Sync](https://github.com/knyazevetc/Woo-1C-Sync) · **Author:** [knyazevetc](https://github.com/knyazevetc)

</div>

---

## Description

**Woo 1C Sync** is a WordPress plugin that connects your WooCommerce store to **1C:Enterprise 8** (Управление торговлей / Trade Management) using the standard **CommerceML** exchange protocol.

1C exports catalog, prices, and stock to the site; WooCommerce exports new orders back to 1C. Product matching is done by **1C GUID** stored in WordPress meta (`_wc1c_guid`).

The admin interface is **Russian by default**. English UI is available when WordPress is set to **English (US)** (`languages/woo-1c-sync-en_US.mo`).

---

## Features

- **Catalog import** — categories, attributes, products, images from `import.xml`
- **Prices & stock** — `offers.xml` updates prices, quantities, and product variations
- **Order export** — WooCommerce orders are sent to 1C as CommerceML documents
- **Standard 1C protocol** — `checkauth`, `init`, `file`, `import`, `query`, `success` modes
- **Short exchange URLs** — `/e`, `/wc1c/exc` (≤ 35 chars for 1C address field)
- **Configurable import** — match by SKU, match categories/attributes by title, partial exchange options
- **Admin settings UI** — tabbed settings page **1С → Настройки обмена с 1С** with contextual help tooltips
- **Cleanup tool** — remove all plugin-created categories, attributes, and products
- **wp-config overrides** — any setting can be forced via `WC1C_*` constants
- **No Composer on server** — built-in PSR-4 autoloader, pure WordPress deployment

---

## Requirements

| Component | Version |
|-----------|---------|
| WordPress | **5.0+** |
| WooCommerce | **Active and required** |
| PHP | **7.4+** (extensions: `xml`, `zip`, `mbstring` recommended) |
| 1C | **1C:Enterprise 8** — Trade Management (УТ), CommerceML exchange |
| Web server | `unzip` CLI or PHP `ZipArchive`; nginx/Apache rewrite support |

---

## Installation

1. Download or clone the repository into `wp-content/plugins/`:

   ```bash
   cd wp-content/plugins
   git clone https://github.com/knyazevetc/Woo-1C-Sync.git woo-1c-sync
   ```

   Or upload a ZIP and extract to `wp-content/plugins/woo-1c-sync/`.

2. Ensure the entry point is: `wp-content/plugins/woo-1c-sync/woo-1c-sync.php`

3. Go to **Plugins → Installed Plugins**

4. Activate **Синхронизация WooCommerce и 1С** (Woo 1C Sync)

5. Open **1С → Настройки обмена с 1С** and copy an **Exchange URL**

6. Go to **Settings → Permalinks → Save** (required for pretty URLs and nginx)

---

## How It Works

### Data flow

```
1C (УТ)                          WordPress / WooCommerce
────────                         ──────────────────────
import.xml  ──►  catalog import  ──►  products, categories, attributes
offers.xml  ──►  offers import   ──►  prices, stock, variations
                query  ◄──────────      new shop orders (XML)
                success ◄─────────      mark orders as exported
```

### Exchange session (1C side)

1. **checkauth** — WordPress user login (Shop Manager or Administrator)
2. **init** — server returns `zip=yes` and `file_limit=…`
3. **file** — 1C uploads ZIP/XML chunks
4. **import** — plugin parses `import.xml` / `offers.xml`
5. **query** — 1C requests new orders; plugin returns CommerceML XML
6. **success** — orders marked as exported (`wc1c_queried` meta)

### Authentication

Use a WordPress account with role **Shop Manager** or **Administrator**.  
1C sends HTTP Basic Auth on the first request; the plugin returns a `wc1c-auth` cookie for subsequent requests in the same session.

---

## Admin Page

### 1С → Настройки обмена с 1С

`admin.php?page=woo-1c-sync`

**Access:** `manage_woocommerce` capability (typically Shop Manager / Administrator)

Settings are split into **tabs** at the top of the page. Each tab with editable options has its own **Save settings** button; saving one tab does not reset options on other tabs.

| Tab | URL parameter | Description |
|-----|---------------|-------------|
| **Подключение 1С** | `tab=connection` (default) | Exchange URLs, authentication, data directory, server limits |
| **Обмен** | `tab=exchange` | XML charset, file limit, variations, stock status, temp file cleanup |
| **Импорт каталога** | `tab=import` | Product matching, slugs, descriptions, full-exchange cleanup |
| **Цены и остатки** | `tab=offers` | Price type GUID, preserve variations on partial sync |
| **Заказы** | `tab=orders` | Order currency code for 1C export |
| **Инструменты** | `tab=tools` | Link to cleanup page (removes all synced data) |

Example: `admin.php?page=woo-1c-sync&tab=import`

### Help tooltips

Fields and sections that commonly cause support issues show a **?** icon next to the label. Hover or focus the icon for troubleshooting hints (wrong URL, auth behind FastCGI, charset, partial offers sync, accidental catalog cleanup, etc.).

---

## Configuration

### Exchange URLs

Shown on the settings page. Use one of these in 1C (field length limit ~35 chars):

| URL | Use case |
|-----|----------|
| `https://example.com/e` | Shortest — best for 1C address field |
| `https://example.com/wc1c/exc` | Short alternative |
| `https://example.com/?wc1c=exchange` | Query-string (always works) |
| `https://example.com/wc1c/exchange/` | Pretty permalink |
| `https://example.com/woo-1c-sync/exchange/` | New pretty URL |

After changing permalinks or updating the plugin: **Settings → Permalinks → Save**.

### Settings (admin UI)

Settings are grouped by tab. Constants in `wp-config.php` override the admin UI (shown as read-only).

#### Tab: Обмен (`tab=exchange`)

| Setting | Constant | Default |
|---------|----------|---------|
| Подавлять PHP notices | `WC1C_SUPPRESS_NOTICES` | off |
| Лимит размера файла | `WC1C_FILE_LIMIT` | server limit |
| Кодировка XML | `WC1C_XML_CHARSET` | `UTF-8` |
| Отключить вариации | `WC1C_DISABLE_VARIATIONS` | off |
| Статус «нет в наличии» | `WC1C_OUTOFSTOCK_STATUS` | `outofstock` |
| Управление запасами | `WC1C_MANAGE_STOCK` | `yes` |
| Очищать временные файлы | `WC1C_CLEANUP_GARBAGE` | on |

#### Tab: Импорт каталога (`tab=import`)

| Setting | Constant | Default |
|---------|----------|---------|
| Описание в контент | `WC1C_PRODUCT_DESCRIPTION_TO_CONTENT` | off |
| Не удалять отсутствующие данные | `WC1C_PREVENT_CLEAN` | off |
| Обновлять slug товара | `WC1C_UPDATE_POST_NAME` | off |
| Сопоставлять по артикулу | `WC1C_MATCH_BY_SKU` | off |
| Категории по названию | `WC1C_MATCH_CATEGORIES_BY_TITLE` | off |
| Свойства по названию | `WC1C_MATCH_PROPERTIES_BY_TITLE` | off |
| Значения свойств по названию | `WC1C_MATCH_PROPERTY_OPTIONS_BY_TITLE` | off |
| GUID как slug значения свойства | `WC1C_USE_GUID_AS_PROPERTY_OPTION_SLUG` | on |
| Разделитель множественных значений | `WC1C_MULTIPLE_VALUES_DELIMETER` | empty |

#### Tab: Цены и остатки (`tab=offers`)

| Setting | Constant | Default |
|---------|----------|---------|
| Тип цены | `WC1C_PRICE_TYPE` | first from XML |
| Сохранять вариации при частичном обмене | `WC1C_PRESERVE_PRODUCT_VARIATIONS` | off |

#### Tab: Заказы (`tab=orders`)

| Setting | Constant | Default |
|---------|----------|---------|
| Валюта заказов | `WC1C_CURRENCY` | from order |

#### Tab: Подключение 1С (`tab=connection`)

Read-only: exchange URLs (with character counts for 1C), authentication notes, data directory path, PHP/server limits (`post_max_size`, `upload_max_filesize`, `memory_limit`, `max_execution_time`).

#### Tab: Инструменты (`tab=tools`)

Link to the cleanup page (`/?wc1c=clean`) — removes categories, attributes, and products created by the plugin.

### wp-config.php overrides

Any constant can be set in `wp-config.php` to override the admin UI (shown as read-only on the settings page):

```php
define('WC1C_XML_CHARSET', 'windows-1251');
define('WC1C_PRICE_TYPE', 'Розничная');
define('WC1C_CURRENCY', 'RUB');
define('WC1C_MATCH_BY_SKU', true);
```

### FastCGI / nginx auth fix

If authentication fails behind FastCGI, add to `.htaccess` after `RewriteEngine On`:

```apache
RewriteRule . - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

For nginx, pass the `Authorization` header to PHP-FPM.

### Data directory

Exchange files are stored in:

```
wp-content/uploads/woo-1c-sync/
├── catalog/
└── sale/
```

Protected by `.htaccess` (`Deny from all`).

---

## 1C Setup

1. In **1C:Trade Management**, open exchange with site / online store settings
2. Set the **site URL** to one of the [Exchange URLs](#exchange-urls) above
3. Enter the WordPress **login and password** (Shop Manager or Administrator)
4. Enable exchange of: **catalog**, **prices**, **stock**, **orders** (as needed)
5. Run the first full catalog upload, then schedule regular sync

---

## Project Structure

```
woo-1c-sync/
├── woo-1c-sync.php                   # Plugin bootstrap
├── uninstall.php
├── README.md
├── LICENSE
├── languages/
│   ├── woo-1c-sync-en_US.po
│   └── woo-1c-sync-en_US.mo
├── assets/
│   └── css/
│       └── admin-settings.css        # Settings page tabs & help tooltips
├── exchange/                         # Handler bootstraps (loaded per import type)
│   ├── import.php
│   ├── offers.php
│   └── orders.php
└── src/
    ├── Plugin.php
    ├── Autoloader.php                # PSR-4 autoload (no Composer required)
    ├── Legacy/functions.php          # wc1c_* wrappers for handlers
    ├── Admin/
    │   └── SettingsPage.php
    ├── Services/
    │   ├── SettingsService.php
    │   ├── AttributeService.php
    │   └── CleanupService.php
    └── Exchange/
        ├── ExchangeService.php       # HTTP exchange router
        ├── ExchangeState.php
        ├── ExchangeSupport.php
        ├── Handlers/
        │   ├── ImportHandler.php     # import.xml
        │   ├── OffersHandler.php     # offers.xml
        │   └── OrdersHandler.php     # orders.xml (import from 1C)
        └── Actions/
            ├── QueryOrdersAction.php # export orders to 1C
            └── ConfirmOrdersAction.php
```

---

## Security

| Mechanism | Description |
|-----------|-------------|
| HTTP Basic Auth | `checkauth` mode validates WordPress credentials |
| Auth cookie | `wc1c-auth` cookie for session after checkauth |
| Capability check | `shop_manager` or `administrator` required |
| Upload directory | `uploads/woo-1c-sync/` denied via `.htaccess` |
| Direct file access | Bootstrap files exit without `ABSPATH` |
| DB transactions | Import runs inside MySQL transaction with rollback on error |
| Cleanup page | POST-only; requires Shop Manager / Administrator |

---

## Localization

| Locale | Behavior |
|--------|----------|
| Russian (default) | Source strings in code; admin UI in Russian |
| English (`en_US`) | `languages/woo-1c-sync-en_US.mo` |
| Other locales | Falls back to Russian (plugin locale filter) |

---

## License

Licensed under the **Apache License 2.0**.  
See [LICENSE](LICENSE) for the full text.

---

## What Changed Since the Original Plugin

This project is a fork and refactor of **WooCommerce 1C Data Exchange** (knyazevetc, v0.9.x).

### Branding & packaging

| Original | Woo 1C Sync |
|----------|-------------|
| Plugin slug `woocommerce-1c` | `woo-1c-sync` |
| Folder `woocommerce-1c` | `woo-1c-sync` |
| Upload dir `uploads/woocommerce-1c/` | `uploads/woo-1c-sync/` |
| English-only admin labels | Russian default + English `en_US` translation |

### Architecture

| Original | Woo 1C Sync |
|----------|-------------|
| Procedural PHP (`wc1c_*` functions in flat files) | PSR-4 classes under `src/`, `Woo1cSync\` namespace |
| `admin.php`, `settings.php`, `exchange.php` | `SettingsPage`, `SettingsService`, `ExchangeService` |
| `exchange/import.php` (~1000 lines of functions) | `ImportHandler` class + thin bootstrap |
| No autoloader | `Autoloader.php` (WordPress-native, no Composer) |

### New / improved

- Tabbed settings UI (connection, exchange, import, offers, orders, tools) with per-tab save
- Contextual **?** help tooltips on common failure points
- Settings editable in admin (previously mostly `wp-config.php` constants only)
- Pretty URL `/woo-1c-sync/exchange/`
- Apache 2.0 license in repository
- GitHub: [github.com/knyazevetc/Woo-1C-Sync](https://github.com/knyazevetc/Woo-1C-Sync)

### Unchanged (compatible with existing stores)

- CommerceML protocol and exchange modes
- Meta keys `_wc1c_guid`, options `wc1c_*` in database
- Exchange URLs `/e`, `/wc1c/exchange`, `?wc1c=exchange`
- Query var `wc1c` and cookie `wc1c-auth`

> **Migration from the original plugin:** deactivate the old plugin, install **woo-1c-sync** in `wp-content/plugins/woo-1c-sync/`, activate, save permalinks. Existing product GUIDs and settings in the database are preserved. Update the exchange path in 1C only if you switch to the new `/woo-1c-sync/exchange/` URL.
