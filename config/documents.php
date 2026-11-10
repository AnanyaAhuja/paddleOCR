<?php

/*
|--------------------------------------------------------------------------
| Document types
|--------------------------------------------------------------------------
|
| The fields Qwen is asked to return for each document type. `type` is the
| kind of value: string, number, date (YYYY-MM-DD), time (HH:MM, 24h), or list (an array of strings or of
| objects, described by `item`). Dates and times are format-checked by the JSON schema.
|
*/

return [

    'types' => [

        'id_card' => [
            'label' => 'Identity card',
            'fields' => [
                'full_name' => ['type' => 'string', 'description' => 'Holder full name'],
                'date_of_birth' => ['type' => 'date', 'description' => 'Date of birth'],
                'sex' => ['type' => 'string', 'description' => 'M or F'],
                'id_number' => ['type' => 'string', 'description' => 'Document / card number, exactly as printed'],
                'nationality' => ['type' => 'string', 'description' => 'Nationality'],
                'issue_date' => ['type' => 'date', 'description' => 'Date of issue'],
                'expiry_date' => ['type' => 'date', 'description' => 'Date of expiry'],
                'address' => ['type' => 'string', 'description' => 'Full address on one line'],
            ],
        ],

        'driving_licence' => [
            'label' => 'Driving licence',
            'fields' => [
                'full_name' => ['type' => 'string', 'description' => 'Holder full name'],
                'date_of_birth' => ['type' => 'date', 'description' => 'Date of birth'],
                'licence_number' => ['type' => 'string', 'description' => 'Licence number, exactly as printed'],
                'issue_date' => ['type' => 'date', 'description' => 'Date of issue'],
                'expiry_date' => ['type' => 'date', 'description' => 'Date of expiry'],
                'address' => ['type' => 'string', 'description' => 'Full address on one line'],
                'vehicle_classes' => ['type' => 'list', 'item' => 'string', 'description' => 'Vehicle categories the holder may drive, e.g. ["B", "A1"]'],
                'restrictions' => ['type' => 'string', 'description' => 'Restriction codes or text, or null'],
            ],
        ],

        'invoice' => [
            'label' => 'Invoice',
            'fields' => [
                'vendor_name' => ['type' => 'string', 'description' => 'Company issuing the invoice'],
                'invoice_number' => ['type' => 'string', 'description' => 'Invoice number'],
                'invoice_date' => ['type' => 'date', 'description' => 'Invoice date'],
                'due_date' => ['type' => 'date', 'description' => 'Payment due date'],
                'bill_to' => ['type' => 'string', 'description' => 'Customer name being billed'],
                'currency' => ['type' => 'string', 'description' => 'ISO currency code, e.g. USD, INR'],
                'subtotal' => ['type' => 'number', 'description' => 'Subtotal before tax'],
                'tax' => ['type' => 'number', 'description' => 'Total tax amount'],
                'total' => ['type' => 'number', 'description' => 'Grand total'],
                'line_items' => ['type' => 'list', 'item' => ['description' => 'string', 'amount' => 'number'], 'description' => 'One entry per line item: description and line amount'],
            ],
        ],

        'receipt' => [
            'label' => 'Receipt',
            'fields' => [
                'merchant_name' => ['type' => 'string', 'description' => 'Store / merchant name'],
                'date' => ['type' => 'date', 'description' => 'Purchase date'],
                'time' => ['type' => 'time', 'description' => 'Purchase time, 24-hour'],
                'subtotal' => ['type' => 'number', 'description' => 'Subtotal before tax'],
                'tax' => ['type' => 'number', 'description' => 'Tax amount'],
                'total' => ['type' => 'number', 'description' => 'Total paid'],
                'payment_method' => ['type' => 'string', 'description' => 'e.g. CASH, VISA, UPI'],
                'items' => ['type' => 'list', 'item' => ['description' => 'string', 'amount' => 'number'], 'description' => 'One entry per purchased item: description and line amount'],
            ],
        ],

        'business_card' => [
            'label' => 'Business card',
            'fields' => [
                'full_name' => ['type' => 'string', 'description' => 'Person name'],
                'job_title' => ['type' => 'string', 'description' => 'Job title'],
                'company' => ['type' => 'string', 'description' => 'Company name'],
                'phone' => ['type' => 'string', 'description' => 'Phone number as printed'],
                'email' => ['type' => 'string', 'description' => 'Email address'],
                'website' => ['type' => 'string', 'description' => 'Website'],
                'address' => ['type' => 'string', 'description' => 'Office address on one line'],
            ],
        ],

    ],

    // Disk and folder for uploaded pages; results are kept in the cache.
    'disk' => 'local',
    'directory' => 'documents',
    'result_ttl' => 60 * 60 * 24,

];
