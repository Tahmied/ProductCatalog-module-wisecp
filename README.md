# Product Catalog API — a WiseCP v5 Addon module

Exposes your whole product catalog — every product with **full details and
prices** — as public JSON endpoints, so another website can render your
packages without an admin API key.

The stock admin API (`/api/v1/admin/products`) lists products but returns **no
price data**. This module reads the same records the WiseCP website catalog
reads — same tables, same promotion and currency logic (`Products::cycle()`,
`Money::exChange()`, `Money::formatter_symbol()`) — and answers on a public
address.

---

## The endpoint you asked for

```
GET https://app.ogahost.com/api/v1/products/catalog
```

That single address returns **all packages, all details, including prices**.
Related endpoints:

| Method | Address | What it returns |
| --- | --- | --- |
| GET | `/api/v1/products/catalog` | Every product, fully detailed, with prices |
| GET | `/api/v1/products/catalog/{id}` | One product, fully detailed, with prices |
| GET | `/api/v1/products/catalog/categories` | Categories with product counts |
| GET | `/api/v1/products/catalog/status` | Diagnostics (only when an access token is set) |

### Query parameters

| Param | Example | Meaning |
| --- | --- | --- |
| `currency` | `USD` | Currency for prices. Default: the *Default Currency* setting, else the installation default. |
| `category_id` | `4` | Only products of that category. |
| `type` | `hosting` | Only products of that type (`hosting`, `server`, ...). |
| `search` | `vps` | Title contains (current language). |
| `page`, `limit` | `1`, `25` | Pagination. `limit=0` (default) returns everything. |

### Response shape (trimmed)

```json
{
  "data": [
    {
      "id": 2,
      "type": "hosting",
      "title": "Basic",
      "tagline": "",
      "status": "active",
      "visibility": "visible",
      "module": "Webuzo",
      "options": { "disk_limit": "unlimited" },
      "created_at": "2026-09-13 06:44:29",
      "category": { "id": 4, "name": "Shared Hosting", "route": "shared-hosting", "langs": { "..." : "..." } },
      "langs": { "en": { "title": "Basic", "route": "basic", "features": "2 GB NVMe Storage\n..." } },
      "prices": {
        "monthly": {
          "amount": 4.90, "promotion": null, "effective": 4.90, "setup": 0.00,
          "formatted": "$4.90", "monthly_equivalent": 4.9
        },
        "annually": { "amount": 49.00, "effective": 49.00, "formatted": "$49.00", "monthly_equivalent": 4.08 }
      },
      "price": {
        "cycle": "monthly", "effective": 4.90, "currency": "USD", "currency_id": 1,
        "formatted": "$4.90", "suffix": "/mo"
      },
      "currency": "USD",
      "in_stock": true,
      "link": "/configure/hosting/2",
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

`prices` keys are WiseCP billing cycles (`monthly`, `quarterly`,
`semiannually`, `annually`, `biennially`, `triennially`, `onetime`). Active
promotions appear as `"promotion": <amount>` with `"effective"` carrying the
discounted price — the same rule invoices use.

---

## Install

1. Copy the `ProductCatalog` folder so it lands at:

   ```
   coremio/modules/Addons/ProductCatalog/
   ```

   (The zip contains the folder itself — extract it **into** `coremio/modules/Addons/`.)

2. Admin panel → **Addons** → "Product Catalog API" appears in the list.
3. Open it, set the options below, tick the enable checkbox, save.

## Configuration after installing

| Setting | Default | What to do |
| --- | --- | --- |
| **Enable** (status checkbox) | off | **Must be on** — routes are only registered while the addon is enabled. |
| Access Token | *(empty)* | Leave empty for a fully public endpoint. Set a random string to require `?token=...` (or an `Authorization: Bearer` / `X-Api-Token` header) on every call. Recommended once your other site is live. |
| Site URL | *(empty)* | `https://app.ogahost.com` — makes `order_url` absolute so your other site can link straight to the order page. |
| Default Currency | *(empty)* | e.g. `USD` — used when the caller doesn't pass `?currency=`. |
| Include inactive / hidden | off | Keep off to mirror what customers see. |

Platform options that apply automatically (no module setup):

- **CORS** — the API Kernel answers preflight and sets `Access-Control-Allow-Origin`
  from the platform option `api-cors-origins` (empty or `*` = allow all
  origins). Restrict it to your other site's domain in production if you call
  the API from browser JavaScript; server-side calls don't need it at all.
- **Rate limit** — public API calls are throttled per IP to
  `api-module-public-rate-limit-per-minute` (default 120/min).

## Consuming it

PHP (server side, no CORS involved):

```php
$catalog = json_decode(
    file_get_contents('https://app.ogahost.com/api/v1/products/catalog'),
    true
);

foreach ($catalog['data'] as $product) {
    echo $product['title'] . ' — ' . $product['price']['formatted'] . $product['price']['suffix'];
}
```

Browser JavaScript:

```js
const { data } = await fetch('https://app.ogahost.com/api/v1/products/catalog').then(r => r.json());

for (const p of data) {
    console.log(p.title, p.price?.formatted, p.order_url);
}
```

## Troubleshooting

- **404 unknown endpoint** — the addon is not enabled; routes only exist while it is.
- **503 module_disabled** — same, or the addon was disabled after a cached route hit.
- **401 unauthorized** — an access token is set and the request didn't send it.
- **Empty `prices`** — the product has no active price rows (`prices.status = 1`)
  for `type = periodicals`/`sale`, or its price currency doesn't match a
  `override_usrcurrency` product's own currency.
- **Diagnostics** — set an access token, then open
  `/api/v1/products/catalog/status?token=...`: it reports row counts
  (products, price rows, currencies, categories) and the resolved currency.

## Notes

- **No data is written.** The module only reads; uninstall = delete the folder.
- Prices come out exactly as the website catalog computes them: promotions
  applied, currency exchanged into the requested currency, `override_usrcurrency`
  products kept in their own currency.
