<?php

/**
 * FormController config for the Settings admin page.
 *
 * Defines the form layout for editing Square Catalog Sync credentials
 * and configuration. Referenced by Http\Controllers\Settings::$formConfig.
 */
return [
    'name'  => 'Square Catalog Sync Settings',
    'model' => \Kirbygo\SquareCatalogSync\Models\Settings::class,

    'toolbar' => [
        'buttons' => [
            'save' => [
                'label'   => 'lang:igniter::admin.button_save',
                'class'   => 'btn btn-primary',
                'data-request' => 'onSave',
            ],
            'syncNow' => [
                'label'        => 'Sync Now',
                'class'        => 'btn btn-default',
                'data-request' => 'onSyncNow',
                'data-request-confirm' => 'Queue a full sync from Square? This may take a moment.',
            ],
        ],
    ],

    'fields' => [

        'access_token' => [
            'label'    => 'Square Access Token',
            'type'     => 'text',
            'span'     => 'full',
            'comment'  => 'Stored encrypted. Leave blank to keep the existing value.',
        ],

        'location_id' => [
            'label'       => 'Location ID',
            'type'        => 'text',
            'span'        => 'left',
            'placeholder' => 'LXXXXXXXXXXXXXX',
        ],

        'environment' => [
            'label'   => 'Environment',
            'type'    => 'select',
            'span'    => 'right',
            'options' => [
                'sandbox'    => 'Sandbox (testing)',
                'production' => 'Production',
            ],
            'default' => 'sandbox',
        ],

        'webhook_signature_key' => [
            'label'   => 'Webhook Signature Key',
            'type'    => 'text',
            'span'    => 'full',
            'comment' => 'Square Developer Dashboard → Webhooks → Signature key. Stored encrypted.',
        ],

    ],
];
