<?php
/**
 * WISECP · Product Catalog API — data layer.
 *
 * Reads products, per-cycle prices and currencies straight from the database
 * with the core WDB builder, mirroring the queries the platform's own catalog
 * runs (models/website/products.php, Products::catalog_prices_bulk()).
 *
 * Verified v5 schema this file is written against:
 *   products        id, rank, type, category, categories(csv), group_type,
 *                   group_id, status, visibility, override_usrcurrency,
 *                   stock, options(JSON), module, ctime
 *   products_lang   owner_id, lang, title, tagline, description, content,
 *                   features, route, seo_*
 *   categories      id, parent, type('products'), kind, rank, status,
 *                   visibility, options
 *   categories_lang owner_id, lang, title, sub_title, route, content, ...
 *   prices          id, owner('products'), owner_id, type('periodicals'|'sale'),
 *                   status(1), period('month','year','none',...), time(1,3,6,..),
 *                   amount, setup, promotion, promotion_status, cid, rank
 *   currencies      id, code, name, rate, status('active'), local(1=default)
 */

namespace WISECP\Modules\Addons\ProductCatalog\Src;

final class Catalog
{
    /**
     * Endpoint payloads are cached through the core Cache (file backed,
     * Cache::remember) so catalog reads do not hit the database on every
     * request. The cache refreshes after CACHE_TTL seconds, and hooks.php
     * clears it the moment a product or category changes in the panel.
     */
    public const CACHE_GROUP = 'productcatalog';
    public const CACHE_TTL   = 86400; // 24 hours

    /** Core cycle names, in display order. */
    public const CYCLE_ORDER = [
        'hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'semiannually',
        'annually', 'biennially', 'triennially', 'onetime',
    ];

    /** Months covered by a cycle; used for the monthly-equivalent figure. */
    public const CYCLE_MONTHS = [
        'monthly' => 1, 'quarterly' => 3, 'semiannually' => 6,
        'annually' => 12, 'biennially' => 24, 'triennially' => 36,
    ];

    // ------------------------------------------------------------------
    // Reference data
    // ------------------------------------------------------------------

    /**
     * id => ['id','code','name','rate','is_local','status'] for every currency.
     */
    private static function currencies(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $out = [];
        $stmt = \WDB::select('*')->from('currencies');
        if ($stmt->build()) {
            foreach ($stmt->fetch_assoc() as $row) {
                $id = (int) ($row['id'] ?? 0);
                $out[$id] = [
                    'id'       => $id,
                    'code'     => strtoupper((string) ($row['code'] ?? '')),
                    'name'     => (string) ($row['name'] ?? ''),
                    'rate'     => (float) ($row['rate'] ?? 1),
                    'is_local' => (int) ($row['local'] ?? 0) === 1,
                    'status'   => (string) ($row['status'] ?? ''),
                ];
            }
        }

        return $cache = $out;
    }

    /** Currency id for a requested code; 0 falls back to the install default. */
    private static function resolve_currency(string $code = ''): int
    {
        $currencies = self::currencies();

        $want = strtoupper(trim($code));
        if ($want !== '')
            foreach ($currencies as $currency)
                if ($currency['code'] === $want && $currency['status'] === 'active')
                    return $currency['id'];

        foreach ($currencies as $currency)
            if ($currency['is_local']) return $currency['id'];

        // Same fallback the platform uses when nothing is selected.
        return (int) \Config::get('general/currency');
    }

    /**
     * Categories of type 'products' with their language rows.
     * id => ['id','rank','langs'=>[lang=>row]]
     */
    private static function categories(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $out = [];
        $stmt = \WDB::select('t1.*')
            ->from('categories AS t1')
            ->where('t1.type', '=', 'products')
            ->order_by('t1.rank ASC, t1.id ASC');

        foreach (($stmt->build() ? $stmt->fetch_assoc() : []) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $out[$id] = ['id' => $id, 'rank' => (int) ($row['rank'] ?? 0), 'raw' => $row, 'langs' => []];
        }

        if ($out) {
            $stmt = \WDB::select('*')->from('categories_lang')->where('owner_id', 'IN', array_keys($out));
            foreach (($stmt->build() ? $stmt->fetch_assoc() : []) as $row) {
                $owner = (int) ($row['owner_id'] ?? 0);
                $lang  = (string) ($row['lang'] ?? '');
                if (isset($out[$owner]) && $lang !== '') $out[$owner]['langs'][$lang] = $row;
            }
        }

        return $cache = $out;
    }

    /**
     * Product language rows grouped per product: productId => lang => row.
     */
    private static function product_langs(array $productIds): array
    {
        if (!$productIds) return [];

        $stmt = \WDB::select('*')->from('products_lang')->where('owner_id', 'IN', array_values($productIds));

        $out = [];
        foreach (($stmt->build() ? $stmt->fetch_assoc() : []) as $row) {
            $owner = (int) ($row['owner_id'] ?? 0);
            $lang  = (string) ($row['lang'] ?? '');
            if ($owner > 0 && $lang !== '') $out[$owner][$lang] = $row;
        }

        return $out;
    }

    /**
     * Every active price row of the given products, grouped per product.
     * Mirrors Products::catalog_prices_bulk(): owner='products', status=1,
     * ordered rank ASC. Includes both recurring ('periodicals') and one-time
     * ('sale') rows.
     */
    private static function prices_bulk(array $productIds): array
    {
        if (!$productIds) return [];

        $ids  = array_values(array_unique(array_map('intval', $productIds)));
        $stmt = \WDB::select('owner_id, period, time, amount, setup, promotion, promotion_status, cid, type')
            ->from('prices')
            ->where('owner', '=', 'products')
            ->where('owner_id', 'IN', $ids)
            ->whereGroup(function ($q) {
                $q->where('type', '=', 'periodicals', '||');
                $q->where('type', '=', 'sale');
            }, '&&')
            ->where('status', '=', 1)
            ->order_by('rank ASC, id ASC');

        $grouped = [];
        foreach (($stmt->build() ? $stmt->fetch_assoc() : []) as $row)
            $grouped[(int) $row['owner_id']][] = $row;

        return $grouped;
    }

    // ------------------------------------------------------------------
    // Products
    // ------------------------------------------------------------------

    /**
     * Product rows after the configured filters, ranked order.
     * Filters: status, visibility, category_id, type, search.
     */
    private static function product_rows(array $filters = []): array
    {
        $stmt = \WDB::select('t1.*')->from('products AS t1');

        if (!empty($filters['status']))
            $stmt->where('t1.status', '=', (string) $filters['status']);

        if (!empty($filters['visibility']))
            $stmt->where('t1.visibility', '=', (string) $filters['visibility']);

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            // A product belongs to a category by id or by the csv `categories`
            // column, exactly like the website catalog query.
            $stmt->whereGroup(function ($q) use ($categoryId) {
                $q->where('t1.category', '=', $categoryId, '||');
                $q->where("FIND_IN_SET('" . $categoryId . "', t1.categories)", '', '');
            }, '&&');
        }

        if (!empty($filters['type']))
            $stmt->where('t1.type', '=', (string) $filters['type']);

        $stmt->order_by('t1.rank ASC, t1.id ASC');

        return $stmt->build() ? $stmt->fetch_assoc() : [];
    }

    // ------------------------------------------------------------------
    // Assembly
    // ------------------------------------------------------------------

    /** Preferred language row: selected language, then primary, then first. */
    private static function pick_lang(array $langs): array
    {
        if (!$langs) return [];

        $selected = (string) \Language::selected();
        $primary  = (string) \Language::primary();

        return $langs[$selected] ?? $langs[$primary] ?? reset($langs);
    }

    /**
     * Resolves the price map for one product into the requested currency,
     * following Products::plan_price_map(): products with
     * override_usrcurrency=1 keep their own currency unconverted, everything
     * else is exchanged into the display currency. Promotions resolve to the
     * effective amount the way invoices do.
     *
     * @return array [cycle => price row] plus '_cid' => display currency id
     */
    private static function resolve_prices(array $productRow, array $priceRows, int $displayCid): array
    {
        $override  = (int) ($productRow['override_usrcurrency'] ?? 0) === 1;
        $ownCid    = (int) ($priceRows[0]['cid'] ?? 0);
        $outCid    = $override ? ($ownCid ?: $displayCid) : $displayCid;
        $out       = [];

        foreach ($priceRows as $row) {
            $cycle = ((string) ($row['type'] ?? '')) === 'sale'
                ? 'onetime'
                : (string) \Products::cycle((string) ($row['time'] ?? ''), (string) ($row['period'] ?? ''));

            if ($cycle === '') continue;

            $amount   = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) continue;

            $promo    = (float) ($row['promotion'] ?? 0);
            $promoOn  = ((int) ($row['promotion_status'] ?? 0)) === 1 && $promo > 0 && $promo < $amount;

            $cid = (int) ($row['cid'] ?? 0);
            if ($override) {
                if ($cid !== $outCid) continue;
            }
            elseif ($cid && $outCid && $cid !== $outCid) {
                $amount = (float) \Money::exChange($amount, $cid, $outCid);
                if ($promoOn) $promo = (float) \Money::exChange($promo, $cid, $outCid);
            }

            $effective  = $promoOn ? $promo : $amount;
            $months     = self::CYCLE_MONTHS[$cycle] ?? null;

            $out[$cycle] = [
                'amount'             => round($amount, 2),
                'promotion'          => $promoOn ? round($promo, 2) : null,
                'effective'          => round($effective, 2),
                'setup'              => round((float) ($row['setup'] ?? 0), 2),
                'formatted'          => \Money::formatter_symbol(round($effective, 2), $outCid),
                'monthly_equivalent' => $months ? round($effective / $months, 2) : null,
            ];
        }

        $out['_cid'] = $outCid;
        return $out;
    }

    /** Cycles in canonical order, unknown spellings appended behind them. */
    private static function order_cycles(array $cycles): array
    {
        $ordered = [];
        foreach (self::CYCLE_ORDER as $key)
            if (isset($cycles[$key])) $ordered[$key] = $cycles[$key];
        foreach ($cycles as $key => $value)
            if ($key !== '_cid' && !isset($ordered[$key])) $ordered[$key] = $value;
        return $ordered;
    }

    /** The "from" price a price tag prints. */
    private static function summary(array $prices, int $cid): array
    {
        $cycle = null;
        foreach (self::CYCLE_ORDER as $key)
            if (isset($prices[$key])) { $cycle = $key; break; }

        if ($cycle === null) return [];

        $row = $prices[$cycle];
        $suffix = (string) \Products::price_suffix($cycle, (float) $row['effective']);

        return [
            'cycle'       => $cycle,
            'amount'      => $row['amount'],
            'effective'   => $row['effective'],
            'promotion'   => $row['promotion'],
            'setup'       => $row['setup'],
            'currency'    => \Money::currency_code($cid),
            'currency_id' => $cid,
            'formatted'   => \Money::formatter_symbol((float) $row['effective'], $cid),
            'suffix'      => $suffix,
            'monthly_equivalent' => $row['monthly_equivalent'],
        ];
    }

    /**
     * Full product record: every column of the row (JSON decoded), the
     * language pack, the category, resolved prices, stock and order links.
     */
    private static function assemble(array $row, array $langs, array $prices, array $categories, array $options): array
    {
        $pid  = (int) ($row['id'] ?? 0);
        $type = (string) ($row['type'] ?? 'hosting');

        // JSON columns arrive as strings; decode the known ones for output.
        $jsonKeys = ['options', 'additional_tax', 'module_data'];

        $out = [];
        foreach ($row as $key => $value)
            $out[$key] = in_array($key, $jsonKeys, true)
                ? (\Utility::jdecode((string) $value, true) ?: $value)
                : $value;

        // ctime is already a DATETIME string in this table.
        $out['created_at'] = (string) ($row['ctime'] ?? '');

        // Language pack, keyed by lang exactly like the admin detail API.
        $out['langs'] = [];
        foreach ($langs as $lang => $langRow) {
            unset($langRow['id'], $langRow['owner_id'], $langRow['lang']);

            $out['langs'][$lang] = array_map(
                static fn ($v) => is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')
                    ? (\Utility::jdecode($v, true) ?: $v)
                    : $v,
                $langRow
            );
        }

        // Flat convenience fields for the display language.
        $display = self::pick_lang($langs);
        $out['title']    = (string) ($display['title'] ?? '');
        $out['tagline']  = (string) ($display['tagline'] ?? '');
        $out['features'] = (string) ($display['features'] ?? '');

        // Category.
        $catId = (int) ($row['category'] ?? 0);
        $cat   = $categories[$catId] ?? null;
        $out['category'] = null;
        if ($cat) {
            $catDisplay = self::pick_lang($cat['langs']);
            $out['category'] = [
                'id'    => $cat['id'],
                'name'  => (string) ($catDisplay['title'] ?? ''),
                'title' => (string) ($catDisplay['title'] ?? ''),
                'route' => (string) ($catDisplay['route'] ?? ''),
                'langs' => $cat['langs'],
            ];
        }

        // Prices.
        $resolved = self::resolve_prices($row, $prices, (int) $options['currency_id']);
        $cid      = (int) ($resolved['_cid'] ?? $options['currency_id']);
        unset($resolved['_cid']);

        $out['prices']        = self::order_cycles($resolved);
        $out['price']         = self::summary($out['prices'], $cid);
        $out['currency']      = \Money::currency_code($cid);
        $out['currency_id']   = $cid;

        // Stock and order links.
        $stock = (string) ($row['stock'] ?? '');
        $out['in_stock'] = ($stock === '' || (int) $stock > 0);

        $out['link'] = (string) \LinkGenerator::client('configure', [$type, $pid]);

        // LinkGenerator usually returns an absolute URL; only prefix the
        // configured site URL when the link is relative, never on top of one.
        $siteUrl = trim((string) ($options['site_url'] ?? ''), " /");
        $isAbsolute = (bool) preg_match('#^https?://#i', $out['link']);
        $out['order_url'] = (!$isAbsolute && $siteUrl !== '')
            ? $siteUrl . '/' . ltrim($out['link'], '/')
            : $out['link'];

        return $out;
    }

    /** Everything the endpoints need resolved once, not per product. */
    private static function context(array $filters): array
    {
        return [
            'currency_id' => self::resolve_currency((string) ($filters['currency'] ?? '')),
            'categories'  => self::categories(),
            'site_url'    => (string) ($filters['site_url'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // Endpoint payloads
    // ------------------------------------------------------------------

    /**
     * The catalog list: every product, fully assembled. Mirrors the core list
     * envelope: page/limit in, meta.total/page/limit/next_page out.
     * limit=0 (default) returns everything.
     *
     * Cached for CACHE_TTL (24h) in the core Cache; hooks.php clears the
     * group whenever a product or category changes, so panel edits show up
     * immediately and the TTL is only the backstop.
     */
    public static function catalog(array $filters = []): array
    {
        $key = 'catalog_' . md5((string) json_encode([$filters, \Language::selected()]));

        return \Cache::remember(self::CACHE_GROUP, $key, self::CACHE_TTL,
            static fn (): array => self::catalog_fresh($filters));
    }

    private static function catalog_fresh(array $filters): array
    {
        $context = self::context($filters);
        $rows    = self::product_rows($filters);

        $ids   = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $rows);
        $langs = self::product_langs($ids);
        $prices = self::prices_bulk($ids);

        $products = [];
        foreach ($rows as $row) {
            $pid = (int) ($row['id'] ?? 0);
            $products[] = self::assemble($row, $langs[$pid] ?? [], $prices[$pid] ?? [], $context['categories'], $context);
        }

        $total  = count($products);
        $page   = max(1, (int) ($filters['page'] ?? 1));
        $limit  = (int) ($filters['limit'] ?? 0);

        if ($limit > 0)
            $products = array_slice($products, ($page - 1) * $limit, $limit);

        $cycles = [];
        foreach ($products as $product)
            foreach (array_keys($product['prices'] ?? []) as $cycle)
                $cycles[$cycle] = true;

        $present    = array_keys($cycles);
        $canonical  = array_values(array_intersect(self::CYCLE_ORDER, $present));
        $extra      = array_values(array_diff($present, self::CYCLE_ORDER));

        return [
            'data' => $products,
            'meta' => [
                'total'            => $total,
                'page'             => $page,
                'limit'            => $limit > 0 ? $limit : $total,
                'next_page'        => ($limit > 0 && $page * $limit < $total) ? $page + 1 : 0,
                'currency'         => \Money::currency_code((int) $context['currency_id']),
                'currency_id'      => (int) $context['currency_id'],
                'cycles_available' => array_merge($canonical, $extra),
                'generated_at'     => date('Y-m-d H:i:s'),
            ],
        ];
    }

    /** One assembled product, or null. Cached like the catalog list. */
    public static function product(int $id, array $filters = []): ?array
    {
        $key = 'product_' . (int) $id . '_' . md5((string) json_encode([$filters, \Language::selected()]));

        return \Cache::remember(self::CACHE_GROUP, $key, self::CACHE_TTL,
            static fn (): ?array => self::product_fresh($id, $filters));
    }

    private static function product_fresh(int $id, array $filters = []): ?array
    {
        $context = self::context($filters);

        $stmt = \WDB::select('t1.*')->from('products AS t1')->where('t1.id', '=', $id);
        $row  = $stmt->build() ? $stmt->getAssoc() : [];
        if (!$row) return null;

        // Respect the visibility filters for detail requests too.
        if (!empty($filters['status']) && (string) ($row['status'] ?? '') !== (string) $filters['status']) return null;
        if (!empty($filters['visibility']) && (string) ($row['visibility'] ?? '') !== (string) $filters['visibility']) return null;

        $pid    = (int) ($row['id'] ?? 0);
        $langs  = self::product_langs([$pid]);
        $prices = self::prices_bulk([$pid]);

        return self::assemble($row, $langs[$pid] ?? [], $prices[$pid] ?? [], $context['categories'], $context);
    }

    /** Categories with their product counts. Cached like the catalog list. */
    public static function categories_detailed(array $filters = []): array
    {
        $key = 'categories_' . md5((string) json_encode([$filters, \Language::selected()]));

        return \Cache::remember(self::CACHE_GROUP, $key, self::CACHE_TTL,
            static fn (): array => self::categories_fresh($filters));
    }

    private static function categories_fresh(array $filters): array
    {
        $context = self::context($filters);
        $rows    = self::product_rows($filters);

        $counts = [];
        foreach ($rows as $row) {
            $catId = (int) ($row['category'] ?? 0);
            if ($catId > 0) $counts[$catId] = ($counts[$catId] ?? 0) + 1;
            foreach (array_filter(explode(',', (string) ($row['categories'] ?? ''))) as $extra)
                if ((int) $extra > 0) $counts[(int) $extra] = ($counts[(int) $extra] ?? 0) + 1;
        }

        $list = [];
        foreach ($context['categories'] as $category) {
            $display = self::pick_lang($category['langs']);
            $list[] = [
                'id'             => $category['id'],
                'name'           => (string) ($display['title'] ?? ''),
                'title'          => (string) ($display['title'] ?? ''),
                'route'          => (string) ($display['route'] ?? ''),
                'products_count' => $counts[$category['id']] ?? 0,
                'langs'          => $category['langs'],
            ];
        }

        return ['data' => $list, 'meta' => ['total' => count($list), 'generated_at' => date('Y-m-d H:i:s')]];
    }

    /** Wipes every cached payload of this module (used by the panel hooks). */
    public static function clear_cache(): void
    {
        try {
            \Cache::getInstance()->clear(self::CACHE_GROUP);
        }
        catch (\Throwable $e) {
            // A cache flush must never take a panel operation down with it.
        }
    }

    /** Diagnostics for the token gated status endpoint. */
    public static function describe(array $filters = []): array
    {
        $context   = self::context($filters);
        $rows      = self::product_rows($filters);
        $ids       = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $rows);
        $priceRows = self::prices_bulk($ids);

        return [
            'php'          => PHP_VERSION,
            'currency'     => \Money::currency_code((int) $context['currency_id']),
            'currency_id'  => (int) $context['currency_id'],
            'counts'       => [
                'products'      => count($rows),
                'with_prices'   => count($priceRows),
                'currencies'    => count(self::currencies()),
                'categories'    => count($context['categories']),
                'price_rows'    => array_sum(array_map('count', $priceRows)),
            ],
        ];
    }
}
