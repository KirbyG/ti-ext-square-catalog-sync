<?php

/**
 * Field definitions for the Square Catalog Sync settings form.
 *
 * Loaded by the SettingsModel action via Settings::$settingsFieldsConfig = 'settings'.
 *
 * Note: access_token and webhook_signature_key are handled as plaintext inputs
 * here but are encrypted before storage by Settings::storeAccessToken() and
 * Settings::storeWebhookSignatureKey(). The displayed value is always masked.
 */
return [
    'tabs' => [
        'defaultTab' => 'Connection',

        'fields' => [

            // ------------------------------------------------------------------
            // Connection tab
            // ------------------------------------------------------------------

            'access_token' => [
                'label'   => 'Square Access Token',
                'type'    => 'text',
                'tab'     => 'Connection',
                'span'    => 'full',
                'comment' => 'Your Square application\'s access token. Stored encrypted. Leave blank to keep the existing value.',
            ],

            'location_id' => [
                'label'       => 'Location ID',
                'type'        => 'text',
                'tab'         => 'Connection',
                'span'        => 'left',
                'placeholder' => 'LXXXXXXXXXXXXXX',
                'comment'     => 'The Square Location ID to sync from.',
                'required'    => true,
            ],

            'environment' => [
                'label'   => 'Environment',
                'type'    => 'select',
                'tab'     => 'Connection',
                'span'    => 'right',
                'options' => [
                    'sandbox'    => 'Sandbox (testing)',
                    'production' => 'Production',
                ],
                'default' => 'sandbox',
                'comment' => 'Use Sandbox until you\'ve verified the sync end-to-end.',
            ],

            // ------------------------------------------------------------------
            // Webhook tab
            // ------------------------------------------------------------------

            'webhook_signature_key' => [
                'label'   => 'Webhook Signature Key',
                'type'    => 'text',
                'tab'     => 'Webhook',
                'span'    => 'full',
                'comment' => 'From Square Developer Dashboard → Webhooks → your subscription → Signature key. Stored encrypted.',
            ],

        ],
    ],
];
