<?php

/**
 * Form field definitions for the Square Catalog Sync settings page.
 *
 * Loaded by SettingsModel action via:
 *   $model->loadConfig($model->settingsFieldsConfig, ['form'], 'form')
 *
 * The top-level 'form' key is required by that loader.
 *
 * Secret fields (access_token, webhook_signature_key) are rendered as
 * plain text inputs. The controller intercepts them before the generic
 * SettingsModel::set() call and passes them through encrypt() instead.
 * Existing encrypted values are never echoed back to the browser.
 */
return [
    'form' => [
        'tabs' => [
            'defaultTab' => 'Connection',

            'fields' => [

                // ── Connection ────────────────────────────────────────────

                'access_token' => [
                    'label'   => 'Access Token',
                    'type'    => 'text',
                    'tab'     => 'Connection',
                    'span'    => 'full',
                    'comment' => 'Your Square application access token. Stored encrypted. Leave blank to keep the existing value.',
                ],

                'location_id' => [
                    'label'       => 'Location ID',
                    'type'        => 'text',
                    'tab'         => 'Connection',
                    'span'        => 'left',
                    'placeholder' => 'LXXXXXXXXXXXXXX',
                    'comment'     => 'The Square Location ID to sync from. Found in Square Dashboard → Locations.',
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
                    'comment' => 'Use Sandbox until the sync is verified end-to-end.',
                ],

                // ── Sync Options ─────────────────────────────────────────

                'ordering_channel_id' => [
                    'label'       => 'Ordering Channel ID',
                    'type'        => 'text',
                    'tab'         => 'Connection',
                    'span'        => 'full',
                    'placeholder' => 'CH_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                    'comment'     => 'Square Ordering Profile channel ID. Items that lack this channel are set to menu_status = 0 (hidden from online ordering). Leave blank to show all non-archived items.',
                ],

                // ── Webhook ───────────────────────────────────────────────

                'webhook_signature_key' => [
                    'label'   => 'Webhook Signature Key',
                    'type'    => 'text',
                    'tab'     => 'Webhook',
                    'span'    => 'full',
                    'comment' => 'Square Dashboard → Webhooks → your subscription → Signature key. Stored encrypted.',
                ],

            ],
        ],
    ],
];
