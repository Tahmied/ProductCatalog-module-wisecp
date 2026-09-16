<?php
/*
 * Product Catalog API — hook registrations.
 *
 * This file is included on EVERY request for every module on disk, enabled or
 * not, so it stays a registration list: no work beyond the includes, the route
 * listener and the catalogue publish.
 */

use WISECP\Modules\Addons\ProductCatalog\Src\ApiSurface;

include_once __DIR__ . DS . 'src' . DS . 'ApiSurface.php';

/*
 * The addresses. The hook fires once per audience with the route list by
 * reference; this module only appends on the free surface, which answers on
 * /api/v1/{pattern} with no credential.
 */
Hook::add('filter:api.routes', 20, function (&$routes, &$audience) {
    if ($audience !== 'module') return;

    foreach (ApiSurface::routes() as $route)
        $routes[] = $route;
});

// The API credentials screen checkboxes. In the file body, never inside the
// listener above — the settings screen reads the catalogue before routes fire.
ApiSurface::publish_permission_catalog();
