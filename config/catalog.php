<?php

return [
    'sample_rows' => (int) env('CATALOG_SAMPLE_ROWS', 25),
    'max_rows' => (int) env('CATALOG_MAX_ROWS', 100000),
    'max_bytes' => (int) env('CATALOG_MAX_BYTES', 10485760),
    'import_disk' => env('CATALOG_IMPORT_DISK', 'local'),
];
