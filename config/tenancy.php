<?php

return [
    // A 256-bit hex key used only to sign transaction-local tenant context.
    // Keep this secret outside version control and rotate it through operations.
    'context_key_hex' => env('TENANT_CONTEXT_KEY'),
];
