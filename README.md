# Product Catalog API — a WiseCP Addon module

Exposes your whole product catalog — every product with **full details and
prices** — as public JSON endpoints, so another website can render your
packages without an admin API key.

The stock admin API (`/api/v1/admin/products`) lists products but returns **no
price data**. This module reads the same records the panel does, joins the
pricing tables on top, and answers on a public address:

```
GET https://app.ogahost.com/api/v1/products/catalog
```

---

## Install

1. Copy the `ProductCatalog` folder into your WiseCP installation so it lands at:

   ```
   coremio/modules/Addons/ProductCatalog/
   ```

   (The zip contains the folder itself — extract it **into** `coremio/modules/Addons/`.)

2. Open the admin panel → **Addons**. "Product Catalog API" is already listed.
3. Open its settings, fill in what you need (all optional, see below) and
   **enable** the addon. The endpoints only answer while the addon is enabled.

## Endpoints

| Method | Address | What it returns |
| --- | --- | --- |
| GET | `/api/v1/products/catalog` | Every product, fully detailed, with prices |
| GET | `/api/v1/products/catalog/{id}` | One product, fully detailed, with prices |
| GET | `/api/v1/products/catalog/categories` | Categories with product counts |
| GET | `/api/v1/products/catalog/status` | Schema self-check (only when an access token is set) |

### Query parameters (catalog and detail)

| Param | Example | Meaning |
| --- | --- | --- |
| `currency` | `USD` | Currency for the price block. Default: the `default_currency` setting, else the platform default. Prices for **all** currencies are always included per product. |
| `category_id` | `4` | Only products of that category. |
| `type` | `hosting` | Only products of that type (`hosting`, `server`, ...). |
| `search` | `vps` | Title contains (when the installation stores a plain title). |
| `page`, `limit` | `1`, `25` | Pagination. `limit=0` (default) returns **everything**. |

### Example response (trimmed)

```json
{
  "data": [
    {
      "id": 2,
      "type": "hosting",
      "status": "active",
      "visibility": "visible",
      "module": "Webuzo",
      "options": { "disk_limit": "unlimited", "...": "..." },
      "category": { "id": 4, "name": "Shared Hosting", "title": "Shared Hosting" },
      "langs": {
        "en": { "title": "Basic", "route": "basic", "features": "2 GB NVMe Storage\n...", "...": "" }
      },
      "prices": {
        "monthly": { "price": 4.9, "setup_fee": 0 },
        "annually": { "price": 49, "setup_fee": 0 }
      },
      "prices_all_currencies": {
        "USD": { "monthly": { "price": 4.9 }, "annually": { "price": 49 } }
      },
      "price": {
        "amount": 4.9,
        "setup_fee": 0,
        "cycle": "monthly",
        "cycle_label": "Monthly",
        "currency": "USD",
        "formatted": "USD 4.90",
        "formatted_cycle": "USD 4.90/mo",
        "monthly_equivalent": 4.9
      },
      "in_stock": true,
      "order_path": "configure/hosting/2",
      "order_url": "https://app.ogahost.com/configure/hosting/2"
    }
  ],
  "meta": {
    "total": 16, "page": 1, "limit": 16, "next_page": 0,
    "currency": "USD", "currency_id": 1,
    "cycles_available": ["monthly", "annually"],
    "generated_at": "2026-09-16 12:00:00"
  }
}
```

Everything the product row holds is returned (options, module data, language
pack, stock, rank, ...), mirroring what the admin detail API shows — plus the
price data it lacks.

## Settings

| Setting | Default | Meaning |
| --- | --- | --- |
| Access Token | *(empty)* | Leave empty = fully public. When set, requests must present it as `?token=...`, `Authorization: Bearer <token>` or `X-Api-Token: <token>`. Recommended once your other site is stable. |
| CORS Allowed Origins | `*` | Origins allowed to call the endpoint from browser JavaScript, comma separated. Restrict to your own site in production. |
| Site URL | *(empty)* | e.g. `https://app.ogahost.com` — makes `order_url` absolute. |
| Default Currency | *(empty)* | Currency code for the summary price block. |
| Include inactive / hidden products | off | Off = only active + visible products are returned. |

## Consuming it from your other website

Server side (no CORS involved) — PHP:

```php
$catalog = json_decode(
    file_get_contents('https://app.ogahost.com/api/v1/products/catalog'),
    true
);

foreach ($catalog['data'] as $product) {
    echo $product['langs']['en']['title'] . ' — ' . $product['price']['formatted'];
}
```

Or from browser JavaScript (CORS is already answered, restrict the origin
list in the module settings):

```js
const res = await fetch('https://app.ogahost.com/api/v1/products/catalog');
const { data } = await res.json();

for (const product of data) {
    console.log(product.langs.en.title, product.price?.formatted, product.order_url);
}
```

## Troubleshooting

Installations differ slightly in how products/prices are stored internally
(table and column names moved between WiseCP releases). This module resolves
them at runtime against a list of known candidates — it does not hardcode one
name. If the price list ever comes back empty or you see a 500:

1. Set an **Access Token** in the module settings.
2. Open `https://app.ogahost.com/api/v1/products/catalog/status?token=YOUR_TOKEN`
   — it prints exactly which tables/columns were found, the column list of the
   products table, and row counts. Send that output over and the candidate
   lists can be extended in one line each.

The discovery code lives in `src/Catalog.php` (`PRODUCT_TABLES`,
`PRICE_TABLES`, `CURRENCY_TABLES`, `CATEGORY_TABLES`, `LANG_TABLES` and the
`pick([...])` column candidates) — appending a name to the front of a list is
the whole fix.

## Notes

- **No data is written.** The module only reads; there is nothing to clean up
  on uninstall (delete the folder and it is gone).
- The `status` endpoint refuses to answer unless an access token is
  configured, so it can never be used to probe a public installation.
- Billing cycle keys are normalised to
  `onetime / monthly / quarterly / semiannually / annually / biennially /
  triennially`, with any unknown raw spelling passed through as-is.
