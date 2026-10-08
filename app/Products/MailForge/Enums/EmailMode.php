<?php

declare(strict_types=1);

namespace App\Products\MailForge\Enums;

enum EmailMode: string
{
    case DIRECT = 'direct';
    case GENERATE = 'generate';
    case POLISH = 'polish';

    public function requiresAi(): bool
    {
        return in_array($this, [self::GENERATE, self::POLISH], true);
    }
}
