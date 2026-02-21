<?php

namespace App\Enums;

enum CatalogImportStatus: string
{
    case CREATED = 'CREATED';
    case UPLOADED = 'UPLOADED';
    case VALIDATED = 'VALIDATED';
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
