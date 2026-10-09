<?php

declare(strict_types=1);

namespace App\Products\MailForge\Support;

use App\Products\MailForge\Models\Contact;

class EmailTemplatePersonalizer
{
    /**
     * Personalize a template string (subject or body) with contact data.
     */
    public static function personalize(string $content, ?Contact $contact = null): string
    {
        if (empty($content) || ! $contact) {
            return $content;
        }

        $firstName = (string) ($contact->first_name ?? '');
        $lastName = (string) ($contact->last_name ?? '');
        $fullName = (string) ($contact->full_name ?: (trim("{$firstName} {$lastName}") ?: $contact->email));

        $map = [
            'first_name' => $firstName,
            'firstname' => $firstName,
            'last_name' => $lastName,
            'lastname' => $lastName,
            'full_name' => $fullName,
            'fullname' => $fullName,
            'name' => $firstName ?: $fullName,
            'email' => (string) $contact->email,
            'company' => (string) ($contact->company ?? ''),
            'job_title' => (string) ($contact->job_title ?? ''),
            'jobtitle' => (string) ($contact->job_title ?? ''),
            'phone' => (string) ($contact->phone ?? ''),
        ];

        // Also merge custom_data keys
        if (is_array($contact->custom_data)) {
            foreach ($contact->custom_data as $key => $val) {
                if (is_scalar($val)) {
                    $keyStr = (string) $key;
                    $valStr = (string) $val;
                    $normKey = strtolower(str_replace([' ', '_', '-'], '', $keyStr));
                    $map[$keyStr] = $valStr;
                    $map[strtolower($keyStr)] = $valStr;
                    $map[$normKey] = $valStr;
                    $map["custom_data.{$keyStr}"] = $valStr;
                }
            }
        }

        // Match {{ ... }} with optional spaces and any casing (e.g. {{ firstName}}, {{ first_name }}, {{email}})
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/i', function ($matches) use ($map) {
            $tag = $matches[1];
            $lower = strtolower($tag);
            $normalized = strtolower(str_replace(['_', '-'], '', $tag));

            if (array_key_exists($tag, $map)) {
                return $map[$tag];
            }
            if (array_key_exists($lower, $map)) {
                return $map[$lower];
            }
            if (array_key_exists($normalized, $map)) {
                return $map[$normalized];
            }

            return $matches[0];
        }, $content);
    }
}
