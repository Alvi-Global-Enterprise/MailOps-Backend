<?php

declare(strict_types=1);

namespace App\Products\MailForge\Support;

use App\Products\MailForge\Models\Contact;

class EmailTemplatePersonalizer
{
    /**
     * Personalize a template string (subject or body) with contact data.
     */
    public static function personalize(string $content, Contact $contact): string
    {
        $replacements = [
            '{{email}}' => $contact->email,
            '{{first_name}}' => $contact->first_name ?? '',
            '{{last_name}}' => $contact->last_name ?? '',
            '{{full_name}}' => $contact->full_name ?? $contact->email,
            '{{company}}' => $contact->company ?? '',
            '{{job_title}}' => $contact->job_title ?? '',
            '{{phone}}' => $contact->phone ?? '',
        ];

        // Also merge custom_data keys
        if (is_array($contact->custom_data)) {
            foreach ($contact->custom_data as $key => $val) {
                if (is_scalar($val)) {
                    $valStr = (string) $val;
                    $replacements["{{{$key}}}"] = $valStr;
                    $replacements["{{custom_data.{$key}}}"] = $valStr;
                }
            }
        }

        return strtr($content, $replacements);
    }
}
