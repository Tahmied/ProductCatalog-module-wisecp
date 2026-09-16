<?php
namespace WISECP\Modules\Addons\ProductCatalog\Src;

/**
 * Reads the product catalog — products, per-cycle prices and currencies —
 * straight from the database through the core WDB query builder.
 *
 * The admin API answers products and products/{id} without any price data, so
 * this class performs the same read the panel does and joins the pricing
 * tables on top.
 *
 * Table and column names are resolved at runtime rather than hardcoded: the
 * schema pieces behind the product catalog have moved between WiseCP
 * releases, and a module that guesses one fixed name fails silently. For each
 * piece a priority-ordered candidate list is probed with WDB::hasTable() and
 * SHOW COLUMNS (the two discovery primitives the module guide documents) and
 * the first match wins. describe() exposes what was resolved, and the token
 * gated status endpoint prints it so a mismatch can be diagnosed from the
 * outside without touching the installation.
 */
final class Catalog
{
    /** Canonical display order for billing cycles. */
    public const CYCLE_ORDER = [
        'onetime', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially',
    ];

    public const CYCLE_LABELS = [
        'onetime'      => 'One Time',
        'monthly'      => 'Monthly',
        'quarterly'    => 'Quarterly',
        'semiannually' => 'Semi-Annually',
        'annually'     => 'Annually',
        'biennially'   => 'Biennially',
        'triennially'  => 'Triennially',
    ];

    /** How many months a canonical cycle covers; null = not recurring. */
    public const CYCLE_MONTHS = [
        'monthly' => 1, 'quarterly' => 3, 'semiannually' => 6,
        'annually' => 12, 'biennially' => 24, 'triennially' => 36,
    ];

    /** Short suffix a price tag prints, e.g. "USD 4.90/mo". */
    public const CYCLE_SUFFIX = [
        'monthly' => '/mo', 'quarterly' => '/qtr', 'semiannually' => '/6mo',
        'annually' => '/yr', 'biennially' => '/2yr', 'triennially' => '/3yr',
    ];

    /** Raw cycle spellings seen in billing schemas, mapped onto canonical keys. */
    private const CYCLE_ALIASES = [
        'onetime' => 'onetime', 'one_time' => 'onetime', 'one time' => 'onetime', 'once' => 'onetime',
        'none' => 'onetime', 'single' => 'onetime', 'ot' => 'onetime',

        'monthly' => 'monthly', 'month' => 'monthly', 'm' => 'monthly', 'm1' => 'monthly', '1m' => 'monthly',
        '1month' => 'monthly', 'per_month' => 'monthly',

        'quarterly' => 'quarterly', 'quarter' => 'quarterly', 'q' => 'quarterly', 'q1' => 'quarterly',
        'm3' => 'quarterly', '3m' => 'quarterly', '3months' => 'quarterly',

        'semiannually' => 'semiannually', 'semiannual' => 'semiannually', 'semi_annually' => 'semiannually',
        'semi' => 'semiannually', 'half_year' => 'semiannually', 'halfyear' => 'semiannually',
        's' => 'semiannually', 's1' => 'semiannually', 'm6' => 'semiannually', '6m' => 'semiannually',
        '6months' => 'semiannually',

        'annually' => 'annually', 'annual' => 'annually', 'yearly' => 'annually', 'year' => 'annually',
        'a' => 'annually', 'a1' => 'annually', 'y' => 'annually', 'y1' => 'annually',
        'm12' => 'annually', '12m' => 'annually', '12months' => 'annually',

        'biennially' => 'biennially', 'biennial' => 'biennially', 'biennium' => 'biennially',
        'b' => 'biennially', 'b1' => 'biennially', 'y2' => 'biennially', '2y' => 'biennially',
        'm24' => 'biennially', '24m' => 'biennially', '24months' => 'biennially',

        'triennially' => 'triennially', 'triennial' => 'triennially',
        't' => 'triennially', 't1' => 'triennially', 'y3' => 'triennially', '3y' => 'triennially',
        'm36' => 'triennially', '36m' => 'triennially', '36months' => 'triennially',
    ];

    private const PRODUCT_TABLES  = ['products', 'product'];
    private const PRICE_TABLES    = ['product_prices', 'products_prices', 'product_price', 'products_pricing', 'pricing', 'prices'];
    private const CURRENCY_TABLES = ['currencies', 'currency'];
    private const CATEGORY_TABLES = ['product_categories', 'products_categories', 'product_category', 'products_category', 'categories', 'category'];
    private const LANG_TABLES     = ['products_lang', 'products_langs', 'product_lang', 'product_langs'];

    /** Product row values that arrive as JSON strings and are decoded for output. */
    private const JSON_FIELDS = [
        'options', 'module_data', 'langs', 'additional_tax', 'prorate',
        'recurring_cycles_limit', 'auto_terminate', 'upgradeable_product_ids',
        'addon_ids', 'requirement_ids',
    ];

    private static array $tableCache = [];
    private static array $colCache   = [];

    // ------------------------------------------------------------------
    // Schema discovery
    // ------------------------------------------------------------------

    /** First candidate table that exists in this installation, or null. */
    public static function table(array $candidates): ?string
    {
        foreach ($candidates as $table) {
            $key = strtolower((string) $table);

            if (!array_key_exists($key, self::$tableCache))
                self::$tableCache[$key] = (bool) \WDB::hasTable($table);

            if (self::$tableCache[$key]) return (string) $table;
        }

        return null;
    }

    /** Lower-cased column names of a table, via SHOW COLUMNS. */
    public static function columns(string $table): array
    {
        if (isset(self::$colCache[$table])) return self::$colCache[$table];

        $cols = [];
        $stmt = \WDB::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');

        if ($stmt) {
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $field = (string) ($row['Field'] ?? $row['field'] ?? '');
                if ($field !== '') $cols[] = strtolower($field);
            }
        }

        return self::$colCache[$table] = $cols;
    }

    /** First candidate column that exists on the table. */
    public static function pick(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate)
            if (in_array(strtolower((string) $candidate), $columns, true))
                return (string) $candidate;

        return null;
    }

    /**
     * Every row a built WDB statement holds. The builder's list accessor
     * differs across releases (getAll, then a single-row getAssoc, then plain
     * iteration); each is tried in that order so the read works on all of
     * them.
     */
    private static function rows($stmt): array
    {
        if (!$stmt || !$stmt->build()) return [];

        if (method_exists($stmt, 'getAll')) {
            $rows = $stmt->getAll();
            if (is_array($rows)) return $rows;
        }

        if (method_exists($stmt, 'getAssoc')) {
            $first = $stmt->getAssoc();
            if ($first) return [$first];
        }

        if ($stmt instanceof \Traversable) {
            $rows = [];
            foreach ($stmt as $row) $rows[] = (array) $row;
            return $rows;
        }

        return [];
    }

    // ------------------------------------------------------------------
    // Reference data
    // ------------------------------------------------------------------

    /**
     * @return array id => ['id', 'code', 'rate', 'is_default']
     */
    public static function currencies(): array
    {
        $table = self::table(self::CURRENCY_TABLES);
        if (!$table) return [];

        $cols    = self::columns($table);
        $codeCol = self::pick($cols, ['code', 'iso', 'iso_code', 'currency_code', 'code_a', 'name', 'title']);
        $rateCol = self::pick($cols, ['rate', 'exchange_rate', 'value', 'converter']);
        $defCol  = self::pick($cols, ['default', 'is_default', 'is_default_currency', 'primary', 'main']);

        $select = ['id'];
        foreach ([$codeCol, $rateCol, $defCol] as $col)
            if ($col) $select[] = '`' . $col . '`';

        $stmt = \WDB::select(implode(', ', $select))->from($table)->orderBy('id', 'ASC');

        $out = [];
        foreach (self::rows($stmt) as $row) {
            $id   = (int) ($row['id'] ?? 0);
            $code = '';

            if ($codeCol) {
                foreach (['code', 'iso', 'iso_code', 'currency_code', 'code_a', 'name', 'title'] as $key)
                    if (!empty($row[$key])) { $code = (string) $row[$key]; break; }
            }

            $out[$id] = [
                'id'         => $id,
                'code'       => strtoupper($code),
                'rate'       => $rateCol ? (float) ($row[$rateCol] ?? 1) : 1.0,
                'is_default' => $defCol ? (bool) ($row[$defCol] ?? false) : false,
            ];
        }

        return $out;
    }

    /**
     * @return array id => ['id', 'name', 'title']
     */
    public static function categories(): array
    {
        $table = self::table(self::CATEGORY_TABLES);
        if (!$table) return [];

        $cols     = self::columns($table);
        $titleCol = self::pick($cols, ['title', 'name', 'category_name', 'label']);
        $slugCol  = self::pick($cols, ['slug', 'route', 'seo_link']);

        $select = ['id'];
        foreach ([$titleCol, $slugCol] as $col)
            if ($col) $select[] = '`' . $col . '`';

        $order = self::pick($cols, ['rank', 'ordering', 'sort', 'order']);
        $stmt  = \WDB::select(implode(', ', $select))->from($table);
        $stmt  = $order ? $stmt->orderBy($order, 'ASC') : $stmt->orderBy('id', 'ASC');

        $out = [];
        foreach (self::rows($stmt) as $row) {
            $id    = (int) ($row['id'] ?? 0);
            $title = $titleCol ? (string) ($row[$titleCol] ?? '') : '';

            // The admin API prints "title" on the list and "name" on the
            // detail; both keys are carried so either consumer shape works.
            $out[$id] = [
                'id'    => $id,
                'name'  => $title,
                'title' => $title,
            ] + ($slugCol ? ['slug' => (string) ($row[$slugCol] ?? '')] : []);
        }

        return $out;
    }

    /**
     * Per-product language content, for installations that keep it in a
     * separate table instead of a JSON column on the product row.
     *
     * @return array productId => langCode => decoded row
     */
    public static function product_langs(): array
    {
        $table = self::table(self::LANG_TABLES);
        if (!$table) return [];

        $cols    = self::columns($table);
        $pidCol  = self::pick($cols, ['product_id', 'pid', 'productid', 'rel_id', 'relid', 'product']);
        $langCol = self::pick($cols, ['lang', 'language', 'locale', 'code']);

        if (!$pidCol) return [];

        $stmt = \WDB::select('*')->from($table);

        $out = [];
        foreach (self::rows($stmt) as $row) {
            $pid  = (int) ($row[$pidCol] ?? 0);
            $code = $langCol ? (string) ($row[$langCol] ?? '') : 'en';
            if ($pid <= 0) continue;

            $entry = [];
            foreach ($row as $key => $value)
                if (!in_array($key, [$pidCol, $langCol], true))
                    $entry[$key] = self::maybe_json($value);

            $out[$pid][$code] = $entry;
        }

        return $out;
    }

    /**
     * Every price row, normalised to productId => currencyId => cycle => amount.
     *
     * @return array [pid][currencyId][cycleKey] => ['price' => float, 'setup_fee' => float]
     */
    public static function prices(): array
    {
        $table = self::table(self::PRICE_TABLES);
        if (!$table) return [];

        $cols   = self::columns($table);
        $pidCol = self::pick($cols, ['product_id', 'pid', 'productid', 'rel_id', 'relid', 'product']);
        $curCol = self::pick($cols, ['currency_id', 'currencyid', 'currency']);
        $cycCol = self::pick($cols, ['cycle', 'period', 'billing_cycle', 'term', 'duration', 'time']);
        $amtCol = self::pick($cols, ['price', 'amount', 'total', 'value', 'cost']);
        $setCol = self::pick($cols, ['setup_fee', 'setupfee', 'setup', 'init_fee', 'installation']);

        if (!$pidCol || !$amtCol) return [];

        $select = ['`' . $pidCol . '`', '`' . $amtCol . '`'];
        foreach ([$curCol, $cycCol, $setCol] as $col)
            if ($col) $select[] = '`' . $col . '`';

        $stmt = \WDB::select(implode(', ', $select))->from($table);

        $out = [];
        foreach (self::rows($stmt) as $row) {
            $pid   = (int) ($row[$pidCol] ?? 0);
            $price = (float) ($row[$amtCol] ?? 0);
            if ($pid <= 0) continue;

            $curId = $curCol ? (int) ($row[$curCol] ?? 0) : 0;
            $cycle = $cycCol ? self::cycle_key((string) ($row[$cycCol] ?? '')) : 'onetime';

            $out[$pid][$curId][$cycle] = [
                'price'     => $price,
                'setup_fee' => $setCol ? (float) ($row[$setCol] ?? 0) : 0.0,
            ];
        }

        return $out;
    }

    /** Raw cycle spelling to canonical key. */
    public static function cycle_key(string $raw): string
    {
        $key = strtolower(trim($raw));

        if (isset(self::CYCLE_ALIASES[$key])) return self::CYCLE_ALIASES[$key];

        // Numeric month counts: 1, 3, 6, 12, 24, 36...
        if (preg_match('/^(\d+)$/', $key, $m)) {
            $months = (int) $m[1];
            $byMonths = [1 => 'monthly', 3 => 'quarterly', 6 => 'semiannually', 12 => 'annually', 24 => 'biennially', 36 => 'triennially'];
            return $byMonths[$months] ?? $key;
        }

        return $key !== '' ? $key : 'onetime';
    }

    // ------------------------------------------------------------------
    // Products
    // ------------------------------------------------------------------

    /**
     * Raw product rows after the configured filters.
     * Filters: status, visibility, category_id, type, search.
     */
    public static function product_rows(array $filters = []): array
    {
        $table = self::table(self::PRODUCT_TABLES);
        if (!$table)
            throw new \RuntimeException('The product table could not be located in this installation. Check the status endpoint for what was found.');

        $cols = self::columns($table);
        $stmt = \WDB::select('*')->from($table);

        if (!empty($filters['status']) && in_array('status', $cols, true))
            $stmt->where('status', '=', (string) $filters['status']);

        if (!empty($filters['visibility']) && in_array('visibility', $cols, true))
            $stmt->where('visibility', '=', (string) $filters['visibility']);

        if (!empty($filters['category_id']) && in_array('category_id', $cols, true))
            $stmt->where('category_id', '=', (int) $filters['category_id']);
        elseif (!empty($filters['category_id']) && in_array('category', $cols, true))
            $stmt->where('category', '=', (int) $filters['category_id']);

        if (!empty($filters['type']) && in_array('type', $cols, true))
            $stmt->where('type', '=', (string) $filters['type']);

        if (!empty($filters['search']) && in_array('title', $cols, true))
            $stmt->where('title', 'LIKE', '%' . (string) $filters['search'] . '%');

        $order = in_array('rank', $cols, true) ? 'rank' : 'id';
        $stmt->orderBy($order, 'ASC')->orderBy('id', 'ASC');

        return self::rows($stmt);
    }

    /**
     * Full product records: the whole row (JSON columns decoded) plus the
     * category, the language pack, prices for the selected currency, prices
     * for every currency, a summary price block and order links.
     *
     * @param array $context context() output
     */
    public static function assemble(array $row, array $context): array
    {
        $out = [];

        foreach ($row as $key => $value)
            $out[$key] = in_array($key, self::JSON_FIELDS, true) ? self::maybe_json($value) : $value;

        $pid = (int) ($row['id'] ?? 0);

        // Language content: prefer the row's own pack, fall back to the
        // separate language table when the installation keeps one.
        if ((!isset($out['langs']) || !is_array($out['langs']) || $out['langs'] === []) && isset($context['langs'][$pid]))
            $out['langs'] = $context['langs'][$pid];

        $catId = 0;
        foreach (['category_id', 'category', 'catid'] as $key)
            if (!empty($row[$key])) { $catId = (int) $row[$key]; break; }

        $out['category'] = $catId > 0 && isset($context['categories'][$catId])
            ? $context['categories'][$catId]
            : null;

        // ---- prices -------------------------------------------------
        $all        = $context['prices'][$pid] ?? [];
        $selected   = $all[(int) $context['currency_id']] ?? [];

        $out['prices'] = self::order_cycles($selected);

        $perCurrency = [];
        foreach ($all as $currencyId => $cycles) {
            $code = (string) ($context['currencies'][(int) $currencyId]['code'] ?? 'currency-' . $currencyId);
            $perCurrency[$code] = self::order_cycles($cycles);
        }
        $out['prices_all_currencies'] = $perCurrency;

        // ---- summary price ------------------------------------------
        $out['price'] = self::summary($out['prices'], (string) $context['currency_code']);

        // ---- stock and order links ----------------------------------
        $stock = $row['stock'] ?? null;
        $out['in_stock'] = ($stock === null || $stock === '') ? true : ((int) $stock > 0);

        $type = (string) ($row['type'] ?? 'hosting');
        $out['order_path'] = 'configure/' . $type . '/' . $pid;

        $siteUrl = trim((string) ($context['site_url'] ?? ''), " /");
        $out['order_url'] = $siteUrl !== ''
            ? $siteUrl . '/' . $out['order_path']
            : null;

        return $out;
    }

    /** context() carries the reference data one read instead of per product. */
    public static function context(array $options = []): array
    {
        $currencies = self::currencies();

        $currencyId = 0;
        $wantCode   = strtoupper(trim((string) ($options['currency'] ?? '')));

        if ($wantCode !== '') {
            foreach ($currencies as $currency)
                if ($currency['code'] === $wantCode) { $currencyId = $currency['id']; break; }
        }

        if ($currencyId === 0) {
            foreach ($currencies as $currency)
                if ($currency['is_default']) { $currencyId = $currency['id']; break; }
        }

        if ($currencyId === 0 && $currencies !== []) {
            $first      = reset($currencies);
            $currencyId = (int) $first['id'];
        }

        $currencyCode = (string) ($currencies[$currencyId]['code'] ?? ($wantCode !== '' ? $wantCode : ''));

        return [
            'currencies'    => $currencies,
            'currency_id'   => $currencyId,
            'currency_code' => $currencyCode,
            'categories'    => self::categories(),
            'langs'         => self::product_langs(),
            'prices'        => self::prices(),
            'site_url'      => (string) ($options['site_url'] ?? ''),
        ];
    }

    /** Cycles in canonical order, unknown spellings appended behind them. */
    private static function order_cycles(array $cycles): array
    {
        $ordered = [];

        foreach (self::CYCLE_ORDER as $key)
            if (isset($cycles[$key])) $ordered[$key] = $cycles[$key];

        foreach ($cycles as $key => $value)
            if (!isset($ordered[$key])) $ordered[$key] = $value;

        return $ordered;
    }

    /** The single "from" price a price tag prints, plus formatted strings. */
    private static function summary(array $prices, string $currencyCode): ?array
    {
        if ($prices === []) return null;

        $cycle = null;
        foreach (self::CYCLE_ORDER as $key)
            if (isset($prices[$key])) { $cycle = $key; break; }

        if ($cycle === null) {
            $keys  = array_keys($prices);
            $cycle = (string) reset($keys);
        }

        $amount   = (float) ($prices[$cycle]['price'] ?? 0);
        $setupFee = (float) ($prices[$cycle]['setup_fee'] ?? 0);
        $months   = self::CYCLE_MONTHS[$cycle] ?? null;
        $suffix   = self::CYCLE_SUFFIX[$cycle] ?? '';

        $formatted = number_format($amount, 2, '.', '');
        $label     = self::CYCLE_LABELS[$cycle] ?? ucfirst($cycle);

        return [
            'amount'             => $amount,
            'setup_fee'          => $setupFee,
            'cycle'              => $cycle,
            'cycle_label'        => $label,
            'currency'           => $currencyCode,
            'formatted'          => trim($currencyCode . ' ' . $formatted),
            'formatted_cycle'    => trim($currencyCode . ' ' . $formatted) . $suffix,
            'monthly_equivalent' => $months ? round($amount / $months, 2) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Endpoint payloads
    // ------------------------------------------------------------------

    /**
     * The catalog list endpoint: every product, fully assembled.
     * Mirrors the core list envelope: page/limit in, meta.total/page/limit/
     * next_page out. A limit of 0 returns everything (the point of this API).
     */
    public static function catalog(array $filters = []): array
    {
        $context = self::context($filters);
        $rows    = self::product_rows($filters);

        $products = [];
        foreach ($rows as $row)
            $products[] = self::assemble((array) $row, $context);

        $page    = max(1, (int) ($filters['page'] ?? 1));
        $limit   = (int) ($filters['limit'] ?? 0);
        $total   = count($products);

        if ($limit > 0)
            $products = array_slice($products, ($page - 1) * $limit, $limit);

        $nextPage = ($limit > 0 && $page * $limit < $total) ? $page + 1 : 0;

        $present = [];
        foreach ($products as $product)
            foreach (array_keys($product['prices'] ?? []) as $cycle)
                $present[$cycle] = true;

        $cycles = array_values(array_intersect(self::CYCLE_ORDER, array_keys($present)));
        foreach (array_keys($present) as $cycle)
            if (!in_array($cycle, $cycles, true)) $cycles[] = $cycle;

        return [
            'data' => $products,
            'meta' => [
                'total'            => $total,
                'page'             => $page,
                'limit'            => $limit > 0 ? $limit : $total,
                'next_page'        => $nextPage,
                'currency'         => $context['currency_code'],
                'currency_id'      => $context['currency_id'],
                'cycles_available' => $cycles,
                'generated_at'     => date('Y-m-d H:i:s'),
            ],
        ];
    }

    /** One assembled product, or null. */
    public static function product(int $id, array $filters = []): ?array
    {
        $context = self::context($filters);
        $rows    = self::product_rows($filters);

        foreach ($rows as $row)
            if ((int) ($row['id'] ?? 0) === $id)
                return self::assemble((array) $row, $context);

        return null;
    }

    /** Categories with their active product counts. */
    public static function categories_detailed(array $filters = []): array
    {
        $context = self::context($filters);
        $rows    = self::product_rows($filters);

        $counts = [];
        foreach ($rows as $row) {
            $catId = 0;
            foreach (['category_id', 'category', 'catid'] as $key)
                if (!empty($row[$key])) { $catId = (int) $row[$key]; break; }
            $counts[$catId] = ($counts[$catId] ?? 0) + 1;
        }

        $list = [];
        foreach ($context['categories'] as $category)
            $list[] = $category + ['products_count' => $counts[$category['id']] ?? 0];

        return ['data' => $list, 'meta' => ['total' => count($list)]];
    }

    /**
     * What schema discovery resolved on this installation. Printed only by
     * the token gated status endpoint.
     */
    public static function describe(): array
    {
        $productTable = self::table(self::PRODUCT_TABLES);
        $priceTable   = self::table(self::PRICE_TABLES);
        $currencyTab  = self::table(self::CURRENCY_TABLES);
        $categoryTab  = self::table(self::CATEGORY_TABLES);
        $langTable    = self::table(self::LANG_TABLES);

        $describe = [
            'php'            => PHP_VERSION,
            'generated_at'   => date('Y-m-d H:i:s'),
            'tables'         => [
                'products'  => $productTable,
                'prices'    => $priceTable,
                'currencies'=> $currencyTab,
                'categories'=> $categoryTab,
                'languages' => $langTable,
            ],
            'product_columns'=> $productTable ? self::columns($productTable) : [],
        ];

        if ($priceTable) {
            $cols        = self::columns($priceTable);
            $describe['price_columns'] = [
                'table'        => $priceTable,
                'product'      => self::pick($cols, ['product_id', 'pid', 'productid', 'rel_id', 'relid', 'product']),
                'currency'     => self::pick($cols, ['currency_id', 'currencyid', 'currency']),
                'cycle'        => self::pick($cols, ['cycle', 'period', 'billing_cycle', 'term', 'duration', 'time']),
                'amount'       => self::pick($cols, ['price', 'amount', 'total', 'value', 'cost']),
                'setup_fee'    => self::pick($cols, ['setup_fee', 'setupfee', 'setup', 'init_fee', 'installation']),
            ];
        }

        $context = self::context();
        $describe['currency'] = $context['currency_code'];
        $describe['counts']   = [
            'products'   => count(self::product_rows()),
            'currencies' => count($context['currencies']),
            'categories' => count($context['categories']),
        ];

        return $describe;
    }

    /** Decode a JSON string column; anything else passes through untouched. */
    private static function maybe_json($value)
    {
        if (is_string($value)) {
            $trimmed = ltrim($value);
            if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $decoded = \Utility::jdecode($value, true);
                if (is_array($decoded)) return $decoded;
            }
        }

        return $value;
    }
}
