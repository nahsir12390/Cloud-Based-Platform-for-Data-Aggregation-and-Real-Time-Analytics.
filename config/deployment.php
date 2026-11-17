<?php

return [
    'force_https' => (bool) env('FORCE_HTTPS', false),
    'trust_proxies' => (bool) env('TRUST_PROXIES', false),
    'owner_name' => env('OWNER_NAME', 'Business Owner'),
    'owner_email' => env('OWNER_EMAIL'),
    'owner_password' => env('OWNER_PASSWORD'),
    'ephemeral_sqlite' => (bool) env('DEPLOY_EPHEMERAL_SQLITE', false),
    'seed_demo' => (bool) env('DEPLOY_SEED_DEMO', false),
    'demo_staff_password' => env('DEPLOY_DEMO_STAFF_PASSWORD'),
];
