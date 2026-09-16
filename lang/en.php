<?php
/*
 * 'name' and 'description' are what the module list prints; the list falls
 * back to config['meta']['name'] and then the directory name without them.
 */
return [
    'name'        => 'Product Catalog API',
    'description' => 'Publishes a public JSON endpoint (/api/v1/products/catalog) that returns every product with full details and prices, for displaying your packages on another website.',
];
