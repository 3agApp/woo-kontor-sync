# Kontor WooCommerce API Documentation

This document consolidates the Kontor API details shared by Codegarden/3AG for the WooCommerce integration.

The documentation is written in English, but API paths, request field names, response field names, and enum values are kept exactly as shared. German field names are explained in English instead of being renamed.

## Status Summary

| Area | Status | Notes |
| --- | --- | --- |
| Product sync | Implemented | `search` endpoint with `entity: "products"` |
| Product filtering by manufacturer | Implemented | `filter.herstellerids` |
| Shop-specific product texts | Implemented | `filter.shoptype`; returns `Shoptitel`, `Kurztext`, `Langtext` |
| Stock sync | Implemented | `search` endpoint with `entity: "stock"` |
| Manufacturer list | Implemented | `search` endpoint with `entity: "manufacturer"` |
| Shop list | Implemented | `search` endpoint with `entity: "shops"` |
| Category list | Implemented | `search` endpoint with `entity: "categories"` and `filter.shopid` |
| Category upsert | Implemented | `upsert` endpoint with `name: "categories"` |
| Order upload | Implemented for test database | `upsert` endpoint with `name: "orders"` |
| Order search/status lookup | Implemented | `search` endpoint with `entity: "orders"` and `filter.shopid`; returns status/tracking fields |

## Base URL And Authentication

Base API URL for all endpoints:

```text
https://sp3api.kontor-crm.de/api/v1
```

All endpoint paths in this document are shown relative to the API v1 base URL.

Example:

```text
/kontor/search → https://sp3api.kontor-crm.de/api/v1/kontor/search
```

All requests use:

```http
Content-Type: application/json
x-api-key: <YOUR_API_KEY>
```

Important: the real API key was shared in email. Do not store it in documentation, source control, screenshots, or logs. Use a secure plugin setting or environment secret instead. Because the key has been exposed in email and pasted into working context, rotating it would be safest.

## Common Response Envelope

Most implemented API responses use this envelope:

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "meta": {
    "durationMs": 363,
    "rowCount": 2,
    "totalCount": null
  },
  "data": [],
  "errorCode": null,
  "details": null
}
```

For searches, the message may be `"Search completed successfully"`.

## Endpoint: Search

```http
POST /kontor/search
```

The `search` endpoint is used for multiple read operations. The operation is selected by the request body field `entity`.

Generic request shape:

```json
{
  "entity": "orders",
  "filter": {
    "shopid": "136cdc2f-f5af-4e04-8e29-654e04fc707b"
  }
}
```

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `entity` | string | Yes | Name of the entity to search, for example `products`, `stock`, `manufacturer`, `shops`, `categories`, or `orders`. |
| `filter` | object | Depends on entity | Key/value pairs used to filter the search results. Required for some entities such as `categories` and `orders`. |
| `paging` | object | Depends on entity | Used by paginated entities such as `products`. |

### Product Search

Use this to retrieve product master data from Kontor.

```json
{
  "entity": "products",
  "paging": {
    "skip": 0,
    "take": 50
  }
}
```

Paging:

| Field | Type | Description |
| --- | --- | --- |
| `paging.skip` | number | Number of records to skip. Use `0` for the first page. |
| `paging.take` | number | Page size. Maximum confirmed value is `2000`. |

Example with filters:

```json
{
  "entity": "products",
  "paging": {
    "skip": 0,
    "take": 500
  },
  "filter": {
    "herstellerids": "11,c076,0c78",
    "shoptype": "B2C"
  }
}
```

Filters:

| Field | Type | Description |
| --- | --- | --- |
| `filter.herstellerids` | string | Comma-separated manufacturer IDs. Example: `"11,076,078"`. |
| `filter.shoptype` | string | Selects shop-specific product text variant. Possible values: `B2B`, `B2C`, `EDU`. Default: `B2B`. |

Example response item:

```json
{
  "Artnr": "ltvbk730",
  "Artean": "5060023417303",
  "Herstellerid": "11",
  "Hersteller": "Le Toy Van",
  "Mpn": "BK730",
  "Gewnetto": 0.000,
  "Artzentralnr": "ltvbk730",
  "Bez1": "Königliche Garde",
  "Shoptype": "B2C",
  "Shoptitel": "Königliche Garde",
  "Kurztext": "Budkins Holzspielpuppe",
  "Langtext": "Budkins Holzspielpuppe Höhe: ca. 10 cm. Passend zu den Spielhäusern und Fahrzeugen von Le Toy Van. Empfohlen ab 3 Jahren.",
  "Ek": 6.0000,
  "UVP": 12.0000,
  "Lagerbestand": 9,
  "MainImageURL": "ltvbk730.jpg",
  "ImageURL_1": "ltv_bk730cutout.jpg",
  "ImageURL_2": null,
  "ImageURL_3": null,
  "ImageURL_4": null,
  "ImageURL_5": null,
  "ImageURL_6": null,
  "ImageURL_7": null,
  "ImageURL_8": null,
  "ImageURL_9": null,
  "Categories": "D444E512-20AB-45B5-B8C8-C968A934DB52,17"
}
```

Product field reference:

| API field | English meaning |
| --- | --- |
| `Artnr` | Article number / SKU. |
| `Artean` | EAN / GTIN. |
| `Herstellerid` | Manufacturer ID. |
| `Hersteller` | Manufacturer name. |
| `Mpn` | Manufacturer part number. |
| `Katname` | Category name. Present in earlier product response examples. |
| `Gewnetto` | Net weight. |
| `Artzentralnr` | Central article number. |
| `Bez1` | Base product title/name. |
| `Shoptype` | Product text variant returned for the requested shop type. |
| `Shoptitel` | Shop-specific product title. |
| `Kurztext` | Shop-specific short description. |
| `Langtext` | Shop-specific long description. Earlier responses also used this as the main product long text. |
| `Ek` | Purchase price / cost price. |
| `UVP` | Recommended retail price / MSRP. |
| `Lagerbestand` | Stock quantity. |
| `MainImageURL` | Main product image filename. |
| `ImageURL_1` to `ImageURL_9` | Additional product image filenames. |
| `Categories` | Comma-separated category IDs assigned to the product. |

Image URL handling:

- Image fields currently contain filenames only, not absolute URLs.
- During testing, images were confirmed to load by prefixing the filename with:

```text
https://daten.3ag.ch/images/
```

- The plugin should keep this image base URL configurable instead of hardcoding it permanently.

Shop-specific text notes:

- `shoptype` values: `B2B`, `B2C`, `EDU`.
- At the time the extension was shared, all shop-specific descriptions were still identical because the specific content still needed to be maintained in Kontor.
- The API names must stay as-is. For example, use `Shoptitel`, not `shopTitle`.

### Stock Search

Use this for lightweight stock synchronization without pulling full product payloads.

```json
{
  "entity": "stock"
}
```

No paging or filtering is required.

Example response item:

```json
{
  "Artnr": "011-006-005",
  "Lagerbestand": 46.000
}
```

Field reference:

| API field | English meaning |
| --- | --- |
| `Artnr` | Article number / SKU. |
| `Lagerbestand` | Stock quantity. |

Notes:

- On 2026-05-19 there was a temporary API bug where product and stock requests returned the same stock output. Codegarden confirmed the bug was fixed the same day.
- Future availability flags such as "available on order", extended delivery time, reorder, or backorder data were discussed, but no final implemented response fields were shared in the emails.

### Manufacturer Search

Use this to retrieve available manufacturers.

```json
{
  "entity": "manufacturer"
}
```

Example response:

```json
{
  "success": true,
  "message": "Search completed successfully",
  "meta": {
    "durationMs": 1,
    "rowCount": 112,
    "totalCount": 112
  },
  "data": [
    {
      "Herstellerid": "075",
      "Hersteller": "Die Spiegelburg"
    },
    {
      "Herstellerid": "076",
      "Hersteller": "CrazyClay"
    }
  ],
  "errorCode": null,
  "details": null
}
```

Response item fields:

| API field | English meaning |
| --- | --- |
| `Herstellerid` | Manufacturer ID. Use this value in `filter.herstellerids` when filtering products. |
| `Hersteller` | Manufacturer name. |

Notes:

- `Herstellerid` is returned as a string, not a number. Preserve leading zeroes such as `"075"`.
- The current response does not include product counts.

### Shop Search

Use this to retrieve configured shops from Kontor.

```json
{
  "entity": "shops"
}
```

Example response:

```json
{
  "success": true,
  "message": "Search completed successfully",
  "meta": {
    "durationMs": 1,
    "rowCount": 13,
    "totalCount": 13
  },
  "data": [
    {
      "Shopid": "72aa5fcd-5296-4c67-908f-3f2cc3bd09e0",
      "Name": "ArmoAccessories"
    },
    {
      "Shopid": "136cdc2f-f5af-4e04-8e29-654e04fc707b",
      "Name": "Education"
    },
    {
      "Shopid": "a3a88a91-8a0e-4a3a-9ead-a645f0fb80c7",
      "Name": "LivingNature"
    },
    {
      "Shopid": "8be9a1bc-1bbd-4a3d-a736-4acace82cc8e",
      "Name": "MagnaTiles"
    },
    {
      "Shopid": "5a2d07b1-9d7c-412e-b913-e73470f49c6c",
      "Name": "NaturalAqua"
    },
    {
      "Shopid": "1e9d1d9e-7485-41fb-a72a-96d5cfb886c6",
      "Name": "Papierdrachen"
    },
    {
      "Shopid": "c13730ca-dc5f-435f-a15e-7b1120b920d2",
      "Name": "Pikosch"
    },
    {
      "Shopid": "0579e3c9-112a-4761-a952-bfa756da7c1c",
      "Name": "Retailer"
    },
    {
      "Shopid": "3fb38157-7269-427c-a9eb-905244c10a3f",
      "Name": "Shopware 6"
    },
    {
      "Shopid": "732ffcc4-58c5-42c2-9c63-d1d2189c005c",
      "Name": "Tigerbox"
    },
    {
      "Shopid": "3ab38157-7269-427c-a9eb-905244c10aaf",
      "Name": "ToysOnline"
    },
    {
      "Shopid": "6b33e5ac-d4d9-4878-9e7d-76a84293b297",
      "Name": "Waytoplay"
    },
    {
      "Shopid": "9b23648d-fed2-4258-b8bd-9b37268fd5c6",
      "Name": "WikkiStix"
    }
  ],
  "errorCode": null,
  "details": null
}
```

Response item fields:

| API field | English meaning |
| --- | --- |
| `Shopid` | Kontor shop ID. Use this value as `shopid` when syncing categories or orders. |
| `Name` | Shop name. |

Expected usage:

- Populate plugin settings with Kontor shop IDs.
- Use the selected `shopid` when syncing categories or orders.

Current shop IDs:

| `Shopid` | `Name` |
| --- | --- |
| `72aa5fcd-5296-4c67-908f-3f2cc3bd09e0` | `ArmoAccessories` |
| `136cdc2f-f5af-4e04-8e29-654e04fc707b` | `Education` |
| `a3a88a91-8a0e-4a3a-9ead-a645f0fb80c7` | `LivingNature` |
| `8be9a1bc-1bbd-4a3d-a736-4acace82cc8e` | `MagnaTiles` |
| `5a2d07b1-9d7c-412e-b913-e73470f49c6c` | `NaturalAqua` |
| `1e9d1d9e-7485-41fb-a72a-96d5cfb886c6` | `Papierdrachen` |
| `c13730ca-dc5f-435f-a15e-7b1120b920d2` | `Pikosch` |
| `0579e3c9-112a-4761-a952-bfa756da7c1c` | `Retailer` |
| `3fb38157-7269-427c-a9eb-905244c10a3f` | `Shopware 6` |
| `732ffcc4-58c5-42c2-9c63-d1d2189c005c` | `Tigerbox` |
| `3ab38157-7269-427c-a9eb-905244c10aaf` | `ToysOnline` |
| `6b33e5ac-d4d9-4878-9e7d-76a84293b297` | `Waytoplay` |
| `9b23648d-fed2-4258-b8bd-9b37268fd5c6` | `WikkiStix` |

### Category Search

Use this to retrieve the current category tree stored in Kontor for a specific shop.

```json
{
  "entity": "categories",
  "filter": {
    "shopid": "3fb38157-7269-427c-a9eb-905244c10a3f"
  }
}
```

Rules:

| Field | Required | Description |
| --- | --- | --- |
| `filter.shopid` | Yes | Must be a valid Kontor shop ID. |

Example response:

```json
{
  "success": true,
  "message": "Search completed successfully",
  "meta": {
    "durationMs": 2,
    "rowCount": 3,
    "totalCount": 3
  },
  "data": [
    {
      "Katid": "16",
      "Katidparent": "",
      "Katname": "test cat"
    },
    {
      "Katid": "15",
      "Katidparent": "",
      "Katname": "Uncategorized"
    },
    {
      "Katid": "17",
      "Katidparent": "16",
      "Katname": "test sub cat"
    }
  ],
  "errorCode": null,
  "details": null
}
```

Response item fields:

| API field | English meaning |
| --- | --- |
| `Katid` | Category ID. |
| `Katidparent` | Parent category ID. Empty string means root category. |
| `Katname` | Category name. |

Notes:

- Categories are primarily created/managed in WooCommerce and sent to Kontor with the upsert endpoint.
- The category list in Kontor can be retrieved for information and verification.
- Product category assignments depend on category IDs, so WooCommerce category IDs must remain stable over time.

### Order Search

Use this to retrieve order status and shipment/tracking data from Kontor for a specific shop.

```bash
curl --location 'https://sp3api.kontor-crm.de/api/v1/kontor/search' \
--header 'x-api-key: <YOUR_API_KEY>' \
--header 'Content-Type: application/json' \
--data '{
  "entity": "orders",
  "filter": {
    "shopid": "136cdc2f-f5af-4e04-8e29-654e04fc707b"
  }
}'
```

Request body:

```json
{
  "entity": "orders",
  "filter": {
    "shopid": "136cdc2f-f5af-4e04-8e29-654e04fc707b"
  }
}
```

Rules:

| Field | Required | Description |
| --- | --- | --- |
| `entity` | Yes | Must be `"orders"`. |
| `filter` | Yes | Key/value pairs used to filter the order search results. |
| `filter.shopid` | Yes | Valid Kontor shop ID. Example: `136cdc2f-f5af-4e04-8e29-654e04fc707b`. |

Example response:

```json
{
  "success": true,
  "message": "Search completed successfully",
  "meta": {
    "durationMs": 737,
    "rowCount": 1,
    "totalCount": 1
  },
  "data": [
    {
      "Auftrnr": "AW 214641",
      "ordernumber": "70525",
      "orderstatus": "completed",
      "provider": null,
      "trackinginfo": null,
      "trackingurl": null
    }
  ],
  "errorCode": null,
  "details": null
}
```

Response item fields:

| API field | English meaning |
| --- | --- |
| `Auftrnr` | Kontor internal order number. |
| `ordernumber` | External/shop order number. |
| `orderstatus` | Current order status. |
| `provider` | Shipping provider. |
| `trackinginfo` | Tracking information or tracking number. |
| `trackingurl` | Shipment tracking URL. |

Notes:

- Returned fields may change depending on the selected `entity`.
- If no matching records are found, `data` will be an empty array and `meta.rowCount` will be `0`.
- For current order status/tracking sync, use this implemented `search` endpoint with `entity: "orders"`.

## Endpoint: Upsert

```http
POST /kontor/upsert
```

The `upsert` endpoint is used for write operations. The operation is selected by the request body field `name`.

Common request wrapper:

```json
{
  "name": "categories",
  "meta": {
    "userId": "CG"
  },
  "params": {}
}
```

| Field | Description |
| --- | --- |
| `name` | Operation name, for example `categories` or `orders`. |
| `meta.userId` | Required. Can be an arbitrary user ID. Example: `"CG"`. |
| `params` | Operation-specific payload. |

### Category Upsert

Use this to send WooCommerce categories to Kontor.

```json
{
  "name": "categories",
  "meta": {
    "userId": "CG"
  },
  "params": {
    "shopid": "9B23648D-FED2-4258-B8BD-9B37268FD5C6",
    "overwrite_all": true,
    "categories": [
      {
        "katid": "2",
        "katidparent": "",
        "katname": "kat-1-1"
      },
      {
        "katid": "3",
        "katidparent": "",
        "katname": "kat-1-2"
      },
      {
        "katid": "4",
        "katidparent": "3",
        "katname": "kat-3-11111"
      },
      {
        "katid": "5",
        "katidparent": "3",
        "katname": "kat-3-222222"
      }
    ]
  }
}
```

Field reference:

| API field | Required | English meaning |
| --- | --- | --- |
| `name` | Yes | Must be `"categories"`. |
| `meta.userId` | Yes | Arbitrary user ID. |
| `params.shopid` | Yes | Valid Kontor shop ID. |
| `params.overwrite_all` | Yes | If `true`, all categories for the shop are overwritten by the payload. |
| `params.categories` | Yes | Category tree payload. |
| `katid` | Yes | Category ID from WooCommerce. Can be a string. Must remain stable. |
| `katidparent` | Yes | Parent category ID. Can be an empty string for root categories. |
| `katname` | Yes | Category name. |

Critical warnings:

- Use `overwrite_all: true` carefully. It overwrites the complete category tree for that shop.
- Product assignments work by category ID only.
- If WooCommerce changes `katid` values or parent IDs incorrectly, product assignments in Kontor can be lost or mismatched.
- Parent IDs must refer to valid category IDs in the payload/tree, or be empty for root categories.

### Order Upsert

Use this to upload WooCommerce orders to Kontor.

Codegarden confirmed this endpoint is ready for testing and creates orders in the test database, so uploaded test orders should not affect production. Confirm the current environment before using it for live orders.

```json
{
  "name": "orders",
  "meta": {
    "userId": "CG"
  },
  "params": {
    "shopid": "9B23648D-FED2-4258-B8BD-9B37268FD5C6",
    "overwrite_all": false,
    "orders": [
      {
        "orderId": "ORD-100001",
        "orderPlatformid": "Toysonline",
        "orderAccountid": null,
        "orderNumber": "SO-2026-0001",
        "orderDate": "2026-03-26T10:15:00Z",
        "salesChannelName": "Webshop",
        "billingAddress": {
          "firstName": "Max",
          "lastName": "Mustermann",
          "company": "Musterfirma GmbH",
          "name": "Max Mustermann",
          "attn": "Einkauf",
          "additionalAddress": "2. Etage",
          "department": "Beschaffung",
          "street": "Musterstraße 1",
          "street2": "Gebäude B",
          "zipcode": "50667",
          "city": "Köln",
          "countryName": "Deutschland",
          "countryCode": "DE",
          "phone": "+49 221 123456",
          "vatId": "DE123456789",
          "externalId": "ADR-1001"
        },
        "deliveryAddress": {
          "firstName": "Max",
          "lastName": "Mustermann",
          "company": "Musterfirma GmbH",
          "name": "Max Mustermann",
          "attn": "Wareneingang",
          "additionalAddress": "Rampe 3",
          "department": "Lager",
          "street": "Lieferstraße 5",
          "street2": "",
          "zipcode": "50667",
          "city": "Köln",
          "countryName": "Deutschland",
          "countryCode": "DE",
          "phone": "+49 221 123456",
          "vatId": "DE123456789",
          "externalId": "ADR-2001"
        },
        "shippingTotal": 5.90,
        "customerName": "Max Mustermann",
        "customerNumber": "K-10023",
        "customerEmail": "max.mustermann@example.com",
        "customerPhone": "+49 221 123456",
        "customerVatId": "DE123456789",
        "customerGroup": "B2B",
        "language": "de",
        "items": [
          {
            "itemId": "10",
            "productId": "P-1000",
            "sku": "ART-1000",
            "quantity": 2,
            "unitPrice": 49.90,
            "regularPrice": 59.90,
            "priceFaktor": 1,
            "discount": 10.00,
            "totalPrice": 89.80,
            "description": "Produkt A",
            "position": 1,
            "taxRate": 19.0
          }
        ],
        "paymentMethod": "invoice",
        "paymentMethodName": "Rechnung",
        "paymentTransactionId": "TX-20260326-0001",
        "paymentState": "paid",
        "shippingMethod": "DHL",
        "taxStatus": "net",
        "remarks": "Bitte Lieferung nur vormittags",
        "currency": "EUR"
      }
    ]
  }
}
```

Order identity rules:

| API field | Meaning / guidance |
| --- | --- |
| `orderNumber` | WooCommerce unique internal/display order number. Kontor uses this to check if the order was already imported. If it was not imported, a new order is created. |
| `orderPlatformid` | Platform identifier string, for example `"Toysonline"`. Despite the example `SHOP-987654`, Frank clarified this identifies the platform. |
| `orderId` | WooCommerce internal order ID or display order number. Frank suggested the display order number is best. |
| `orderAccountid` | Intended as a subaccount ID relative to the platform. Frank clarified it is not relevant here and can be left empty. |

Mandatory information guidance:

Frank clarified that most fields are not mandatory. The required practical data is mainly:

- Customer data needed for invoicing.
- Delivery address data if different from the invoice/billing address.
- Order items with `sku`, `quantity`, `price`, and VAT/tax rate.

Order item field reference:

| API field | English meaning |
| --- | --- |
| `itemId` | Shop/order line item ID. |
| `productId` | Shop product ID. |
| `sku` | Product SKU / article number. |
| `quantity` | Quantity ordered. |
| `unitPrice` | Unit price. |
| `regularPrice` | Regular/list unit price. |
| `priceFaktor` | Price factor. Keep API spelling exactly as provided. |
| `discount` | Discount amount. |
| `totalPrice` | Line total price. |
| `description` | Item description. |
| `position` | Line item position. |
| `taxRate` | VAT/tax rate percentage. |

Example response:

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "meta": {
    "durationMs": 363,
    "rowCount": 2,
    "totalCount": null
  },
  "data": [
    {
      "orderId": "ORD-100001",
      "orderNumber": "SO-2026-0001",
      "auftrnr": "AW 214625",
      "status": "ok",
      "message": null
    },
    {
      "orderId": "ORD-100002",
      "orderNumber": "SO-2026-00012",
      "auftrnr": "AW 214626",
      "status": "ok",
      "message": null
    }
  ],
  "errorCode": null,
  "details": null
}
```

Response field reference:

| API field | English meaning |
| --- | --- |
| `orderId` | Submitted order ID. |
| `orderNumber` | Submitted order number used for duplicate checking. |
| `auftrnr` | Kontor internal order number. |
| `status` | Import status, for example `ok`. |
| `message` | Optional message/error detail for the order. |

## Integration Workflow

### Product Sync From Kontor To WooCommerce

1. Fetch shops with `entity: "shops"`.
2. Fetch manufacturers with `entity: "manufacturer"` if the plugin needs manufacturer selection/filtering.
3. Fetch products with `entity: "products"`.
4. Use `paging.skip` and `paging.take` until all records are retrieved.
5. Use `filter.herstellerids` to limit by selected manufacturers when possible.
6. Use `filter.shoptype` to retrieve the correct text variant:
   - `B2B` for retail/dealer shop.
   - `EDU` for education shop.
   - `B2C` for end-customer shops.
7. Convert image filenames to full URLs using the configured image base URL.
8. Map `Categories` to WooCommerce categories where applicable.

### Stock Sync

1. Call `entity: "stock"` frequently for a lightweight SKU/quantity sync.
2. Match stock rows by `Artnr`.
3. Update WooCommerce stock quantity from `Lagerbestand`.

### Category Sync From WooCommerce To Kontor

1. WooCommerce is the source of truth for category creation/management.
2. Fetch Kontor shops with `entity: "shops"`.
3. Select the correct `shopid`.
4. Send the full category tree with `name: "categories"`.
5. Keep `katid` values stable across future syncs.

### Order Sync From WooCommerce To Kontor

For B2B Retail and Education shops:

1. Send complete orders and customer data to Kontor with `name: "orders"`.
2. Kontor handles invoices, delivery notes, stock deductions, and fulfillment workflow.
3. WooCommerce should retrieve order status/tracking with the implemented search endpoint using `entity: "orders"` and `filter.shopid`.

For B2C end-customer shops:

1. Invoices, delivery notes, and Planzer package labels may remain in WooCommerce.
2. Orders still need to reserve or update Kontor stock immediately to avoid overselling.
3. The final implemented stock reservation behavior was discussed but not fully specified in the emails.

## Open Questions / Confirm Before Final Production Use

| Topic | Question |
| --- | --- |
| API key | Should the existing key be rotated because it was shared in email and pasted into working docs/context? |
| Order endpoint environment | Is order upsert still test-database only, or has a production endpoint/environment been enabled? |
| Order duplicate behavior | If an existing `orderNumber` is sent again, is it ignored, updated, or returned as already imported? |
| Stock reservation | For B2C shops, what exact API behavior reserves stock without full invoicing/fulfillment in Kontor? |
| Availability/backorder | What exact fields will represent "on order", extended delivery time, reorder, or backorder state? |
