<?php

return [
    // A 256-bit hex key used only to sign transaction-local tenant context.
    // Keep this secret outside version control and rotate it through operations.
    'context_key_hex' => env('TENANT_CONTEXT_KEY'),
    // Identity memberships live in the control-plane store; G2 CI overrides this with its isolated proof fixture.
    'membership_table' => env('TENANT_MEMBERSHIP_TABLE', 'identity.account_memberships'),
];
