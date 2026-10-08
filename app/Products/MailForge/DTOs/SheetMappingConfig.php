<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class SheetMappingConfig
{
    /**
     * @param  array<string, string>  $customMappings  Extra column mappings
     */
    public function __construct(
        public string $emailColumn = 'email',
        public ?string $firstNameColumn = 'first_name',
        public ?string $lastNameColumn = 'last_name',
        public ?string $fullNameColumn = 'full_name',
        public ?string $companyColumn = 'company',
        public ?string $jobTitleColumn = 'job_title',
        public ?string $phoneColumn = 'phone',
        public ?string $externalIdColumn = 'id',
        public array $customMappings = []
    ) {}

    /**
     * Build from array/JSON storage.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            emailColumn: (string) ($data['email'] ?? $data['email_column'] ?? 'email'),
            firstNameColumn: isset($data['first_name']) ? (string) $data['first_name'] : ($data['firstName'] ?? null),
            lastNameColumn: isset($data['last_name']) ? (string) $data['last_name'] : ($data['lastName'] ?? null),
            fullNameColumn: isset($data['full_name']) ? (string) $data['full_name'] : ($data['fullName'] ?? null),
            companyColumn: isset($data['company']) ? (string) $data['company'] : ($data['companyColumn'] ?? null),
            jobTitleColumn: isset($data['job_title']) ? (string) $data['job_title'] : ($data['jobTitle'] ?? null),
            phoneColumn: isset($data['phone']) ? (string) $data['phone'] : ($data['phoneColumn'] ?? null),
            externalIdColumn: isset($data['external_id']) ? (string) $data['external_id'] : ($data['id'] ?? null),
            customMappings: (array) ($data['custom_mappings'] ?? $data['custom'] ?? [])
        );
    }

    /**
     * Convert to array representation for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'email' => $this->emailColumn,
            'first_name' => $this->firstNameColumn,
            'last_name' => $this->lastNameColumn,
            'full_name' => $this->fullNameColumn,
            'company' => $this->companyColumn,
            'job_title' => $this->jobTitleColumn,
            'phone' => $this->phoneColumn,
            'external_id' => $this->externalIdColumn,
            'custom_mappings' => $this->customMappings,
        ], fn ($val) => $val !== null && $val !== []);
    }
}
