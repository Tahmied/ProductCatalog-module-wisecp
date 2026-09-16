<?php
/**
 * WISECP · Product Catalog API — an Addon module.
 *
 * Publishes the product catalog — every product with its full detail record
 * and PRICES — as public JSON endpoints on the free API surface
 * (/api/v1/...), for rendering the packages on another website.
 *
 * The class file, the directory and the class carry the same name, and the
 * class resolves as WISECP\Modules\Addons\ProductCatalog — the exact FQCN the
 * module registry builds ("WISECP\Modules\" . ucfirst($type) . "\" . $name).
 */

namespace WISECP\Modules\Addons;

use WISECP\Api\Core\Request;
use WISECP\Api\Core\Response;
use WISECP\Modules\Addons\ProductCatalog\Src\Catalog;

include_once __DIR__ . DS . 'src' . DS . 'Catalog.php';

class ProductCatalog extends \AddonModule
{
    // ------------------------------------------------------------------
    // Settings screen
    // ------------------------------------------------------------------

    /**
     * The addon settings form. Posted values land in config['settings'] under
     * the same keys through the base save_settings().
     */
    public function fields(): array
    {
        $settings = $this->config['settings'] ?? [];

        return [
            'access_token' => [
                'name'        => 'Access Token',
                'description' => 'Optional extra protection. When set, every request must present this value as ?token=..., an "Authorization: Bearer" header or an "X-Api-Token" header. Leave empty for a fully public endpoint.',
                'type'        => 'password',
                'value'       => (string) ($settings['access_token'] ?? ''),
            ],

            'site_url' => [
                'name'        => 'Site URL',
                'description' => 'Base address of this WiseCP installation (e.g. https://app.ogahost.com). When set, the returned order_url becomes an absolute link; otherwise it stays a relative path.',
                'type'        => 'text',
                'value'       => (string) ($settings['site_url'] ?? ''),
                'placeholder' => 'https://app.ogahost.com',
            ],

            'default_currency' => [
                'name'        => 'Default Currency',
                'description' => 'Currency code used when the request does not pass ?currency= (e.g. USD). Empty uses the installation default currency.',
                'type'        => 'text',
                'value'       => (string) ($settings['default_currency'] ?? ''),
                'placeholder' => 'USD',
            ],

            'include_inactive' => [
                'name'        => 'Include inactive products',
                'description' => 'Off = only active products are returned.',
                'type'        => 'approval',
                'checked'     => (bool) ($settings['include_inactive'] ?? false),
            ],

            'include_hidden' => [
                'name'        => 'Include hidden products',
                'description' => 'Off = only visible products are returned.',
                'type'        => 'approval',
                'checked'     => (bool) ($settings['include_hidden'] ?? false),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Endpoints — dispatched by the Kernel as api_{action}
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/products/catalog
     * Every product, fully detailed, with prices.
     * Filters: ?currency= &category_id= &type= &search= &page= &limit=
     */
    public function api_catalog(Request $request, array $match): Response
    {
        if (($response = $this->guard($request)) !== null) return $response;

        try {
            $result = Catalog::catalog($this->filters($request->query ?: []));
            return Response::success($result['data'], 200, $result['meta']);
        }
        catch (\Throwable $e) {
            return $this->failure($e);
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
            $id = (int) ($match['params'][0] ?? ($match['params']['id'] ?? 0));
            if ($id <= 0)
                return Response::error('bad_request', 'A numeric product id is required in the path.', 400);

            $product = Catalog::product($id, $this->filters($request->query ?: []));

            if ($product === null)
                return Response::error('not_found', 'Product not found.', 404);

            return Response::success($product, 200, ['generated_at' => date('Y-m-d H:i:s')]);
        }
        catch (\Throwable $e) {
            return $this->failure($e);
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
            return Response::success($result['data'], 200, $result['meta']);
        }
        catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * GET /api/v1/products/catalog/status
     * Diagnostics: row counts and the resolved currency. Only answers when an
     * access token is configured, so it can never probe a public install.
     */
    public function api_status(Request $request, array $match): Response
    {
        if (($response = $this->guard($request)) !== null) return $response;

        $token = trim((string) ($this->config['settings']['access_token'] ?? ''));
        if ($token === '')
            return Response::error('not_found', 'This endpoint is only available when an access token is configured.', 404);

        try {
            return Response::success(Catalog::describe($this->filters($request->query ?: [])), 200,
                ['generated_at' => date('Y-m-d H:i:s')]);
        }
        catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    // ------------------------------------------------------------------
    // Shared request plumbing
    // ------------------------------------------------------------------

    /**
     * Checks every endpoint runs before its body: the module enable flag and
     * the optional access token. Returns a ready error Response, or null.
     *
     * (CORS and OPTIONS preflight are not handled here: the API Kernel answers
     * both globally, driven by the platform option api-cors-origins.)
     */
    private function guard(Request $request): ?Response
    {
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
            'status'           => empty($settings['include_inactive']) ? 'active' : '',
            'visibility'       => empty($settings['include_hidden']) ? 'visible' : '',
            'category_id'      => (int) ($query['category_id'] ?? 0),
            'type'             => (string) ($query['type'] ?? ''),
            'search'           => (string) ($query['search'] ?? ''),
            'currency'         => (string) ($query['currency'] ?? ($settings['default_currency'] ?? '')),
            'page'             => max(1, (int) ($query['page'] ?? 1)),
            'limit'            => (int) ($query['limit'] ?? 0),
            'site_url'         => (string) ($settings['site_url'] ?? ''),
        ];
    }

    /** One catch for every endpoint body. */
    private function failure(\Throwable $e): Response
    {
        $debug = (defined('ERROR_DEBUG') && ERROR_DEBUG) || (defined('DEVELOPMENT') && DEVELOPMENT);
        return Response::error('server_error', $debug ? $e->getMessage() : 'Internal server error.', 500);
    }
}
