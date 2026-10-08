<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class SheetRowData
{
    /**
     * @param  array<string, mixed>  $customData
     */
    public function __construct(
        public int $rowIndex,
        public string $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $fullName = null,
        public ?string $company = null,
        public ?string $jobTitle = null,
        public ?string $phone = null,
        public ?string $externalId = null,
        public array $customData = [],
        public bool $isValidEmail = true,
        public ?string $validationError = null
    ) {}
}
