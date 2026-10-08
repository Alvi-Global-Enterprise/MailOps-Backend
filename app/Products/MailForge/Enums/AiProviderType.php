<?php

declare(strict_types=1);

namespace App\Products\MailForge\Enums;

enum AiProviderType: string
{
    case OPENAI = 'openai';
    case GEMINI = 'gemini';
    case MOCK = 'mock';
}
