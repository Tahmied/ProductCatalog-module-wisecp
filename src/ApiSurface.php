<?php
namespace WISECP\Modules\Addons\ProductCatalog\Src;

use Config;

/**
 * The single declaration of the module's API surface.
 *
 * Every endpoint answers on the FREE surface (audience 'module'), which the
 * module guide maps to /api/v1/{pattern} with no credential required, and is
 * flagged public so no API key is ever demanded. The 'status' endpoint is a
 * schema self-check that refuses to answer unless an access token has been
 * configured, so it can not be used to probe a public installation.
 */
final class ApiSurface
{
    public const GROUP = 'Module:Addons/ProductCatalog';

    /**
     * [method, path, action, target].
     *
     * Literal paths are declared BEFORE their parametric twin: the router
     * takes the first match at a given segment count, so products/catalog/{id}
     * declared first would swallow products/catalog/categories and answer the
     * detail handler with id = "categories".
     */
    public static function map(): array
    {
        return [
            ['GET',     'products/catalog/categories', 'categories',     'read:categories'],
            ['GET',     'products/catalog/status',     'status',         'read:status'],
            ['GET',     'products/catalog/{id}',       'product_detail', 'read:product_detail'],
            ['GET',     'products/catalog',            'catalog',        'read:catalog'],

            // Preflight twins for browser calls from another origin. The
            // handlers answer OPTIONS with an empty body + CORS headers.
            ['OPTIONS', 'products/catalog',            'catalog',        'read:catalog'],
            ['OPTIONS', 'products/catalog/categories', 'categories',     'read:categories'],
            ['OPTIONS', 'products/catalog/status',     'status',         'read:status'],
            ['OPTIONS', 'products/catalog/{id}',       'product_detail', 'read:product_detail'],
        ];
    }

    /**
     * Route tuples for filter:api.routes.
     * [0] method, [1] pattern, [2] group, [3] action,
     * [4] public = true  (no credential required),
     * [5] authOnly = true (the free surface default).
     */
    public static function routes(): array
    {
        $routes = [];

        foreach (self::map() as [$method, $path, $action])
            $routes[] = [$method, $path, self::GROUP, $action, true, true];

        return $routes;
    }

    /**
     * Publishes the endpoints into the in-memory permission catalogue, so the
     * API credentials screen lists them. Called from the FILE BODY of
     * hooks.php, never from inside the listener: the settings screen reads
     * the catalogue before the route hook fires.
     */
    public static function publish_permission_catalog(): void
    {
        $actions = array_values(array_unique(array_map(
            static fn (array $entry): string => $entry[2],
            self::map()
        )));

        if (!$actions) return;

        Config::set('api-actions', [self::GROUP => $actions], true);
    }
}
