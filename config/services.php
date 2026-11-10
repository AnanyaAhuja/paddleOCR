<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // PaddleOCR HTTP wrapper (docker/ocr), reached over the compose network.
    'paddle_ocr' => [
        'url' => env('PADDLE_OCR_URL', 'http://ocr:8000'),
        'timeout' => (int) env('PADDLE_OCR_TIMEOUT', 120),
    ],

    // Qwen VLM on Docker Model Runner (OpenAI-compatible). Compose injects
    // QWEN_VL_URL and QWEN_VL_MODEL from the `models:` block.
    'qwen_vl' => [
        'url' => env('QWEN_VL_URL', 'http://model-runner.docker.internal/engines/v1/'),
        'model' => env('QWEN_VL_MODEL', 'ai/qwen3-vl:4B-UD-Q4_K_XL'),
        'timeout' => (int) env('QWEN_VL_TIMEOUT', 240),
        // Longest image side sent to the model. Bigger = more image tokens = slower.
        'max_image_side' => (int) env('QWEN_VL_MAX_IMAGE_SIDE', 1280),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
