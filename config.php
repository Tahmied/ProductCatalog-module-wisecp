<?php
/**
 * WISECP · Product Catalog API — module configuration.
 *
 * 'status' is the addon enable flag; the panel toggle writes it through the
 * base change_addon_status(). It ships false so the endpoints only answer
 * after the operator has deliberately enabled the module.
 *
 * Every key the code reads is shipped with a safe default, so nothing has to
 * be guarded against a key that only appears after the first save.
 */
return [
    'created_at' => 1789555200,
    'meta' => [
        'name'     => 'Product Catalog API',
        'version'  => '1.1.0',
        'author'   => 'OgaHost',
        'logo'     => 'logo.png',
        'icon'     => 'bi bi-box-seam',
    ],
    'show_on_adminArea'  => true,
    'show_on_clientArea' => false,
    'status'             => false,
    'access_ps'          => [],
    'settings' => [
        // Optional extra protection: when non-empty, every request must
        // present this value as ?token=, an "Authorization: Bearer" header or
        // an "X-Api-Token" header. Empty = fully public endpoint.
        'access_token' => '',

        // Base address of this installation (e.g. https://app.ogahost.com).
        // When set, returned order_url links become absolute.
        'site_url' => '',

        // Currency CODE used when the request does not pass ?currency=.
        // Empty = the installation default currency.
        'default_currency' => '',

        // 1 = also return inactive / hidden products. Off by default.
        'include_inactive' => 0,
        'include_hidden'   => 0,
    ],
];
