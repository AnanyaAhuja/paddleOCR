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

        // Italian electronic identity card (CIE 3.0). Labels are printed in
        // Italian and English; the MRZ on the back is checked separately.
        'it_id_card' => [
            'label' => 'Italian identity card (CIE)',
            'hint' => 'Italian labels: COGNOME = surname, NOME = given names, LUOGO E DATA DI NASCITA = place and date of birth, '
                .'SESSO = sex, STATURA = height, CITTADINANZA = nationality, EMISSIONE = issue date, SCADENZA = expiry date, '
                .'COMUNE DI = issuing municipality, CODICE FISCALE = fiscal code, INDIRIZZO DI RESIDENZA = address. '
                .'The document number is printed in the top-right corner of the front (e.g. CA00000AA). '
                .'Front and back may be rotated or on one scanned page.',
            'fields' => [
                'surname' => ['type' => 'string', 'description' => 'COGNOME / SURNAME'],
                'given_names' => ['type' => 'string', 'description' => 'NOME / NAME'],
                'date_of_birth' => ['type' => 'date', 'description' => 'Date of birth'],
                'place_of_birth' => ['type' => 'string', 'description' => 'Place of birth as printed, e.g. ROMA (RM)'],
                'sex' => ['type' => 'string', 'description' => 'SESSO: M or F'],
                'height_cm' => ['type' => 'number', 'description' => 'STATURA in centimetres'],
                'nationality' => ['type' => 'string', 'description' => 'CITTADINANZA as printed, e.g. ITA'],
                'document_number' => ['type' => 'string', 'description' => 'Card number at the top right of the front, e.g. CA00000AA'],
                'issue_date' => ['type' => 'date', 'description' => 'EMISSIONE / date of issue'],
                'expiry_date' => ['type' => 'date', 'description' => 'SCADENZA / date of expiry'],
                'issuing_municipality' => ['type' => 'string', 'description' => 'COMUNE DI / municipality that issued the card'],
                'fiscal_code' => ['type' => 'string', 'side' => 'back', 'description' => 'CODICE FISCALE, 16 characters, on the back'],
                'address' => ['type' => 'string', 'side' => 'back', 'description' => 'INDIRIZZO DI RESIDENZA on the back, one line'],
            ],
            // Privacy mask: printed labels that may stay visible. Every other
            // line of text, faces and barcodes are blacked out.
            'privacy' => [
                'keep' => [
                    'REPUBBLICA ITALIANA', "MINISTERO DELL'INTERNO", 'CARTA DI IDENTITÀ / IDENTITY CARD',
                    'COMUNE DI / MUNICIPALITY', 'COGNOME / SURNAME', 'NOME / NAME',
                    'LUOGO E DATA DI NASCITA', 'PLACE AND DATE OF BIRTH', 'SESSO', 'SEX', 'STATURA', 'HEIGHT',
                    'CITTADINANZA', 'NATIONALITY', 'EMISSIONE / ISSUING', 'SCADENZA / EXPIRY',
                    'FIRMA DEL TITOLARE', "HOLDER'S SIGNATURE",
                    'COGNOME E NOME DEL PADRE E DELLA MADRE O DI CHI NE FA LE VECI', "FATHER AND MOTHER'S / TUTOR'S NAME",
                    'CODICE FISCALE', 'FISCAL CODE', 'ESTREMI ATTO DI NASCITA', 'INDIRIZZO DI RESIDENZA / RESIDENCE',
                ],
            ],
        ],

    ],

    // Store a privacy-masked copy of each page when it is processed
    // (DOCUMENT_PRIVACY=false to skip). Privacy mode on the review pages
    // shows only these copies.
    'privacy_enabled' => (bool) env('DOCUMENT_PRIVACY', true),

    /*
    |--------------------------------------------------------------------------
    | Judge (JEV)
    |--------------------------------------------------------------------------
    |
    | A "System One" model that answers "is this value shown on the card?"
    | with a calibrated probability. Below the threshold a field is flagged
    | for manual review. Tune it on your reviews (/reviews/summary): on 23
    | reviewed Italian ID cards, 0.60 caught 64% of wrong values and 0.75
    | caught 82%, at the cost of more flagged fields.
    |
    */
    'judge' => [
        'enabled' => (bool) env('JEV_ENABLED', true),
        // Served by Docker Model Runner on the Mac GPU, next to Qwen. The model
        // is packaged with its vision projector: see `make jev`.
        'url' => env('JEV_URL', env('QWEN_VL_URL', 'http://model-runner.docker.internal/engines/v1/')),
        'model' => env('JEV_MODEL', 'local/jev-vlm-4b-lite:Q4_K_M'),
        'timeout' => (int) env('JEV_TIMEOUT', 300),
        'threshold' => (float) env('JEV_THRESHOLD', 0.60),
        'max_image_side' => (int) env('JEV_MAX_IMAGE_SIDE', 1024),
    ],

    // Disk and folder for uploaded pages; results are kept in the cache.
    'disk' => 'local',
    'directory' => 'documents',
    'result_ttl' => 60 * 60 * 24,

];
