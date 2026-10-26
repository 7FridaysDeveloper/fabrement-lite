<?php

/** Public field/content contracts called by themes and integrations outside this repository. */
return [
    'protectedPrefixes' => [],
    'prefixes' => [
        'Fabrement\\Api\\Content\\',
        'Fabrement\\Api\\Fields\\',
    ],
    'classes' => [
        'Fabrement\\Api\\Fabrement',
        'Fabrement\\Api\\Media\\Image',
        'Fabrement\\Api\\Media\\File',
        'Fabrement\\Api\\Markup\\Link',
        'Fabrement\\Api\\Support\\Format',
        'Fabrement\\Modules\\Menu\\MenuTree',
    ],
    'interfaces' => [
        // MCP adapter creates the error handler by class name, then calls its contract.
        'WP\\MCP\\Infrastructure\\ErrorHandling\\Contracts\\McpErrorHandlerInterface' => ['log'],
        'Fabrement\\Modules\\Mcp\\Ability\\AbilityInterface' => [
            '__construct', 'name', 'label', 'description', 'input_schema', 'output_schema',
            'annotations', 'check_permission', 'execute',
        ],
    ],
    'methods' => [
        // WP_REST_Posts_Controller dispatches these overrides for the registered internal CPTs.
        'Fabrement\\Route\\InternalPostsRestController' => [
            'get_items_permissions_check', 'get_item_permissions_check', 'check_read_permission',
        ],
        // Yoast calls graph pieces returned by wpseo_schema_graph_pieces.
        'Fabrement\\Modules\\Seo\\Integration\\YoastSchemaPiece' => ['is_needed', 'generate'],
    ],
];
