<?php

return [

    // Our Service Provider identity, shared across every SAML connection.
    // Individual IdP details (entity ID, SSO URL, certificate) live per-row
    // in supplier_identity_providers, not here.
    'saml' => [
        'sp_entity_id' => env('SSO_SAML_SP_ENTITY_ID', rtrim((string) env('APP_URL'), '/').'/metadata/saml'),

        // Optional: only needed if you want to sign outgoing AuthnRequests
        // or support encrypted assertions. Most IdPs only require the
        // IdP -> SP assertion to be signed, not the request, so it's safe
        // to leave these empty to start.
        'sp_x509_cert' => env('SSO_SAML_SP_CERT'),
        'sp_private_key' => env('SSO_SAML_SP_PRIVATE_KEY'),

        'strict' => (bool) env('SSO_SAML_STRICT', true),
    ],

    'oidc' => [
        'discovery_cache_seconds' => (int) env('SSO_OIDC_DISCOVERY_CACHE_SECONDS', 3600),
    ],

];
