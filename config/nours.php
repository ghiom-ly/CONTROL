<?php

return [
    'company_name' => 'شركة النورس',
    'system_name' => 'NOURS STORE MONITORING',

    'initial_admin' => [
        'name' => env('INITIAL_ADMIN_NAME', 'المدير العام'),
        'username' => env('INITIAL_ADMIN_USERNAME', 'admin'),
        'password' => env('INITIAL_ADMIN_PASSWORD'),
    ],

    'uploads' => [
        'max_size_kb' => 20480,
        'allowed' => ['xlsx', 'xls', 'pdf'],
        'attachment_mimes' => ['xlsx', 'xls', 'pdf', 'jpg', 'jpeg', 'png'],
    ],

    'ocr' => [
        'tesseract' => env('OCR_TESSERACT_PATH', '/usr/bin/tesseract'),
        'pdftoppm' => env('OCR_PDFTOPPM_PATH', '/usr/bin/pdftoppm'),
        'languages' => env('OCR_LANGUAGES', 'ara+eng'),
    ],

    'per_page' => 20,
];
