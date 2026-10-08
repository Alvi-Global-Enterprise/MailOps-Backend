<?php

declare(strict_types=1);

namespace App\Products\MailForge\Enums;

enum ContactSourceType: string
{
    case GOOGLE_SHEETS = 'google_sheets';
    case MANUAL = 'manual';
    case CSV = 'csv';
    case API = 'api';
    case EXCEL = 'excel';
}
