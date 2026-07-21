<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Data source for the panel
    |--------------------------------------------------------------------------
    |
    | local:     use this Laravel database schema.
    | wordpress: read live JetEngine/CCT data through the "wordpress" connection.
    |
    */
    'properties' => env('PROPERTIES_SOURCE', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Authentication source
    |--------------------------------------------------------------------------
    |
    | local:                  validate against this Laravel users table.
    | wordpress_funcionarios: validate against wp_jet_cct_funcionarios using
    |                         user_others_apss and pass_others_apss.
    |
    */
    'auth' => env('AUTH_SOURCE', 'local'),

    'demo_data' => env('SEED_DEMO_DATA', true),

    'branding' => [],
];
