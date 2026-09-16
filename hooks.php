<?php
/**
 * WISECP · Product Catalog API — hook registrations.
 *
 * This file is included on EVERY request for every module on disk, so it
 * stays a registration list. The routes are only registered while the addon
 * is enabled — the same pattern the shipped WChat addon uses — so a disabled
 * module leaves no endpoints on the router at all.
 */

Modules::Load('Addons', 'ProductCatalog', true);
$pc_config = Modules::Config('Addons', 'ProductCatalog') ?: [];

/*
 * Cache invalidation: the endpoint payloads are cached for 24 hours; these
 * listeners flush that cache the moment the catalog data changes in the
 * panel, so edits show up immediately without waiting out the TTL.
 */
$pc_clear_cache = function () {
    include_once __DIR__ . DS . 'src' . DS . 'Catalog.php';
    WISECP\Modules\Addons\ProductCatalog\Src\Catalog::clear_cache();
};

Hook::add('action:product.created',           1, $pc_clear_cache);
Hook::add('action:product.updated',           1, $pc_clear_cache);
Hook::add('action:product.deleted',           1, $pc_clear_cache);
Hook::add('action:product.status_changed',    1, $pc_clear_cache);
Hook::add('action:product.bulk_action_applied', 1, $pc_clear_cache);
Hook::add('action:product.group_saved',       1, $pc_clear_cache);

if (($pc_config['status'] ?? false)) {

    Hook::add('filter:api.routes', 1, function (&$routes, &$audience) {
        if ($audience !== 'module') return;

        // Literal paths before their parametric twin: the router takes the
        // first match at a given segment count, so {id} must come last.
        $routes[] = ['GET', 'products/catalog/categories', 'Module:Addons/ProductCatalog', 'categories',     true];
        $routes[] = ['GET', 'products/catalog/status',     'Module:Addons/ProductCatalog', 'status',         true];
        $routes[] = ['GET', 'products/catalog/{id}',       'Module:Addons/ProductCatalog', 'product_detail', true];
        $routes[] = ['GET', 'products/catalog',            'Module:Addons/ProductCatalog', 'catalog',        true];
    });
}
