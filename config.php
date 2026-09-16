<?php
/*
 * Product Catalog API — module configuration.
 *
 * 'status' is the addon enable flag; the panel toggle writes it through the
 * base change_addon_status(). Ship it false so the endpoints only answer
 * after the operator has deliberately enabled the module.
 *
 * Every key the code reads is shipped with a safe default, so nothing has to
 * be guarded against a key that only appears after the first save.
 */
return [
    'meta' => [
        'name'        => 'Product Catalog API',
        'version'     => '1.0.0',
        'description' => 'Public JSON API endpoint that returns every product with full details and prices.',
        'logo'        => 'logo.png',
    ],

    'status'             => false,
    'show_on_adminArea'  => true,
    'show_on_clientArea' => false,

    'settings' => [
        // When non-empty, every request must present this value as ?token=,
        // an "Authorization: Bearer <token>" header or an "X-Api-Token" header.
        // Empty means the endpoint is fully public.
        'access_token' => '',

        // Comma separated list of origins allowed to call the endpoint from a
        // browser (CORS). '*' allows every origin.
        'cors_origins' => '*',

        // Base address of THIS WiseCP installation, e.g. https://app.ogahost.com.
        // Used to build absolute order links; empty falls back to relative paths.
        'site_url' => '',

        // Currency CODE used for the summary price block when the request does
        // not ask for one (?currency=USD). Empty = the platform default currency.
        'default_currency' => '',

        // 1 = also return inactive / hidden products. Off by default.
        'include_inactive' => 0,
        'include_hidden'   => 0,
    ],
];
