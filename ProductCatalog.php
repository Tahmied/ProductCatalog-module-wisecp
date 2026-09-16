<?php
namespace WISECP\Modules\Addons\ProductCatalog;

/*
 * Product Catalog API.
 *
 * An Addon module that publishes the product catalog — every product with its
 * full detail record and prices — as public JSON endpoints on the free API
 * surface, for rendering the packages on another website.
 *
 * The AddonModule base constructor already filled $config, $lang, $dir and
 * $url; no constructor is declared here.
 */

use WISECP\Api\Core\Request;
use WISECP\Api\Core\Response;
use WISECP\Modules\Addons\ProductCatalog\Src\ApiSurface;
use WISECP\Modules\Addons\ProductCatalog\Src\Catalog;

include_once __DIR__ . DS . 'src' . DS . 'Catalog.php';
include_once __DIR__ . DS . 'src' . DS . 'ApiSurface.php';

class ProductCatalog extends \AddonModule
{
    // ------------------------------------------------------------------
    // Settings screen
    // ------------------------------------------------------------------

    /**
     * The addon settings form. Keys land in config['settings'] under the same
     * names through the base save_settings().
     */
    public function fields(): array
    {
        $settings = $this->config['settings'] ?? [];

        return [
            'access_token' => [
                'name'        => 'Access Token',
                'description' => 'Optional. When set, every request must present this value as ?token=..., an "Authorization: Bearer" header or an "X-Api-Token" header. Leave empty for a fully public endpoint.',
                'type'        => 'password',
                'value'       => (string) ($settings['access_token'] ?? ''),
            ],

            'cors_origins' => [
                'name'        => 'CORS Allowed Origins',
                'description' => 'Origins allowed to call the endpoint from browser JavaScript, comma separated (e.g. https://www.ogahost.com,https://ogahost.com). * allows every origin.',
                'type'        => 'text',
                'value'       => (string) ($settings['cors_origins'] ?? '*'),
            ],

            'site_url' => [
                'name'        => 'Site URL',
                'description' => 'Base address of this WiseCP installation (e.g. https://app.ogahost.com). Used to build absolute order_url links; empty returns relative order paths only.',
                'type'        => 'text',
                'value'       => (string) ($settings['site_url'] ?? ''),
                'placeholder' => 'https://app.ogahost.com',
            ],

            'default_currency' => [
                'name'        => 'Default Currency',
                'description' => 'Currency code used for the summary price block when the request does not pass ?currency= (e.g. USD). Empty uses the platform default currency.',
                'type'        => 'text',
                'value'       => (string) ($settings['default_currency'] ?? ''),
                'placeholder' => 'USD',
            ],

            'include_inactive' => [
                'name'    => 'Include inactive products',
                'type'    => 'approval',
                'checked' => (bool) ($settings['include_inactive'] ?? false),
            ],

            'include_hidden' => [
                'name'    => 'Include hidden products',
                'type'    => 'approval',
                'checked' => (bool) ($settings['include_hidden'] ?? false),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Endpoints
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/products/catalog
     * Every product, fully detailed, with prices. Filters: category_id,
     * type, search, currency, page, limit.
     */
    public function api_catalog(Request $request, array $match): Response
    {
        if (($response = $this->guard($request)) !== null) return $response;

        try {
            $query   = $request->query ?: [];
            $filters = $this->filters($query);

            $result = Catalog::catalog($filters);

            return Response::success($result['data'], 200, $result['meta'])
                ->withHeaders($this->cors_headers($request));
        }
        catch (\Throwable $e) {
            return $this->failure($e, $request);
        }
    }

    /**
     * GET /api/v1/products/catalog/{id}
     * One product, fully detailed, with prices.
     */
    public function api_product_detail(Request $request, array $match): Response
    {
        if (($response = $this->guard($request)) !== null) return $response;

        try {
            $id = (int) ($match['params']['id'] ?? 0);
            if ($id <= 0)
                return Response::error('bad_request', 'A numeric product id is required in the path.', 400)
                    ->withHeaders($this->cors_headers($request));

            $product = Catalog::product($id, $this->filters($request->query ?: []));

            if ($product === null)
                return Response::error('not_found', 'Product not found.', 404)
                    ->withHeaders($this->cors_headers($request));

            return Response::success($product, 200, ['generated_at' => date('Y-m-d H:i:s')])
                ->withHeaders($this->cors_headers($request));
        }
        catch (\Throwable $e) {
            return $this->failure($e, $request);
        }
    }

    /**
     * GET /api/v1/products/catalog/categories
     * The product categories with their product counts.
     */
    public function api_categories(Request $request, array $match): Response
    {
        if (($response = $this->guard($request)) !== null) return $response;

        try {
            $result = Catalog::categories_detailed($this->filters($request->query ?: []));

            return Response::success($result['data'], 200, $result['meta'])
                ->withHeaders($this->cors_headers($request));
        }
        catch (\Throwable $e) {
            return $this->failure($e, $request);
        }
    }

    /**
     * GET /api/v1/products/catalog/status
     * Schema self-check. Only answers when an access token is configured and
     * presented, so it can never be used to probe a public installation.
     */
    public function api_status(Request $request, array $match): Response
    {
        if (($response = $this->guard($request)) !== null) return $response;

        $token = trim((string) ($this->config['settings']['access_token'] ?? ''));
        if ($token === '')
            return Response::error('not_found', 'This endpoint is only available when an access token is configured.', 404)
                ->withHeaders($this->cors_headers($request));

        try {
            return Response::success(Catalog::describe(), 200, ['generated_at' => date('Y-m-d H:i:s')])
                ->withHeaders($this->cors_headers($request));
        }
        catch (\Throwable $e) {
            return $this->failure($e, $request);
        }
    }

    // ------------------------------------------------------------------
    // Shared request plumbing
    // ------------------------------------------------------------------

    /**
     * Checks that apply before any endpoint body runs: the CORS preflight,
     * the module enable flag and the optional access token.
     * Returns a ready error Response, or null to proceed.
     */
    private function guard(Request $request): ?Response
    {
        if (($request->method ?? '') === 'OPTIONS')
            return (new Response(200, []))->withHeaders($this->cors_headers($request));

        if (!$this->isEnabled())
            return Response::error('module_disabled', 'The Product Catalog API module is not enabled.', 503);

        $token = trim((string) ($this->config['settings']['access_token'] ?? ''));
        if ($token === '') return null;

        $given = (string) ($request->query['token'] ?? '');

        if ($given === '' && !empty($request->headers['x-api-token']))
            $given = (string) $request->headers['x-api-token'];

        if ($given === '' && !empty($request->headers['authorization']))
            $given = trim((string) preg_replace('/^(?:Bearer|Token)\s+/i', '', (string) $request->headers['authorization']));

        if ($given !== '' && hash_equals($token, $given)) return null;

        return Response::error('unauthorized', 'A valid access token is required to use this endpoint.', 401);
    }

    /** Query parameters + module settings merged into Catalog filters. */
    private function filters(array $query): array
    {
        $settings = $this->config['settings'] ?? [];

        return [
            'status'     => empty($settings['include_inactive']) ? 'active' : '',
            'visibility' => empty($settings['include_hidden']) ? 'visible' : '',
            'category_id'=> (int) ($query['category_id'] ?? 0),
            'type'       => (string) ($query['type'] ?? ''),
            'search'     => (string) ($query['search'] ?? ''),
            'currency'   => (string) ($query['currency'] ?? ($settings['default_currency'] ?? '')),
            'page'       => max(1, (int) ($query['page'] ?? 1)),
            'limit'      => (int) ($query['limit'] ?? 0),
            'site_url'   => (string) ($settings['site_url'] ?? ''),
        ];
    }

    /** CORS response headers for the configured origin list. */
    private function cors_headers(Request $request): array
    {
        $allowed = array_values(array_filter(array_map('trim',
            explode(',', (string) ($this->config['settings']['cors_origins'] ?? '*'))
        )));

        if ($allowed === []) $allowed = ['*'];

        $origin = (string) ($request->headers['origin'] ?? '');
        $allow  = in_array('*', $allowed, true)
            ? '*'
            : (in_array($origin, $allowed, true) ? $origin : '');

        $headers = [
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Api-Token',
            'Access-Control-Max-Age'       => '86400',
            'Cache-Control'                => 'no-cache',
        ];

        if ($allow !== '') {
            $headers['Access-Control-Allow-Origin'] = $allow;
            $headers['Vary'] = 'Origin';
        }

        return $headers;
    }

    /** One catch for every endpoint body. */
    private function failure(\Throwable $e, Request $request): Response
    {
        return Response::error('server_error', $e->getMessage(), 500)
            ->withHeaders($this->cors_headers($request));
    }
}
