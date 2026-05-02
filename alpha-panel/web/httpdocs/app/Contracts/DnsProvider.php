<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Domain;

interface DnsProvider
{
    /**
     * @return array<int, mixed>
     */
    public function getRecords(Domain $domain, ?string $search = null): array;

    public function createRecord(Domain $domain, array $data): mixed;

    public function deleteRecordById(Domain $domain, int|string $recordId): void;

    /**
     * @param  array<int, int|string>  $recordIds
     */
    public function bulkDeleteRecords(Domain $domain, array $recordIds): int;
}
