<?php

declare(strict_types=1);

namespace App\Services\Acme;

use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use Rogierw\RwAcme\Enums\AuthorizationChallengeEnum;

/**
 * Runs the DNS-01 ACME challenge flow.
 *
 * Provider-agnostic: the caller passes `createTxtRecord` / `deleteTxtRecord`
 * callbacks that drive whichever DNS backend is in use (Cloudflare API,
 * local PowerDNS, etc.). This runner owns the order lifecycle, the
 * propagation wait, polling, and the deferred cleanup wiring.
 *
 * Cleanup is intentionally deferred via AcmeResult::withCleanup so that
 * the TXT records remain in place until the cert is written and Caddy
 * reloaded — protecting against any retries during finalization.
 */
class Dns01ChallengeRunner
{
    public function __construct(
        private AcmeClientFactory $clientFactory,
        private OrderFinalizer $orderFinalizer,
    ) {}

    /**
     * Perform a DNS-01 ACME challenge.
     *
     * @param  string[]  $domains
     * @param  callable(string $recordName, string $recordValue): array  $createTxtRecord
     * @param  callable(array $context): void  $deleteTxtRecord
     */
    public function run(
        Domain $domain,
        array $domains,
        callable $createTxtRecord,
        callable $deleteTxtRecord,
        int $propagationWait,
        ?callable $onProgress = null,
        int $pollTimeout = 300,
    ): AcmeResult {
        $fqdn = $domain->fqdn;
        $createdRecords = [];

        try {
            $client = $this->clientFactory->getClient();
            $accountData = $this->clientFactory->ensureAccount();

            if ($onProgress) {
                $onProgress(20, __('Creating certificate order...'));
            }

            $order = $client->order()->new($accountData, $domains);

            if ($onProgress) {
                $onProgress(30, __('Setting DNS challenge records...'));
            }

            $validations = $client->domainValidation()->status($order);
            $dnsAuths = $client->domainValidation()->getValidationData($validations, AuthorizationChallengeEnum::DNS);

            foreach ($dnsAuths as $auth) {
                $recordName = $auth['name'].'.'.$auth['identifier'];
                $recordValue = $auth['value'];

                $context = $createTxtRecord($recordName, $recordValue);
                $createdRecords[] = ['context' => $context, 'callback' => $deleteTxtRecord];
            }

            // Wait for DNS propagation
            if ($onProgress) {
                $onProgress(40, __('Waiting for DNS propagation (:seconds seconds)...', ['seconds' => $propagationWait]));
            }
            sleep($propagationWait);

            // Start validation for each domain
            if ($onProgress) {
                $onProgress(55, __('Validating domain ownership...'));
            }
            foreach ($validations as $validation) {
                if (! empty($validation->dns)) {
                    $client->domainValidation()->start($accountData, $validation, AuthorizationChallengeEnum::DNS, false);
                }
            }

            // Poll for all challenges to pass, respecting poll_timeout setting.
            // The library's allChallengesPassed() is hardcoded to 4 attempts;
            // we implement our own loop so poll_timeout is actually honoured.
            if (! $this->orderFinalizer->pollUntilChallengesPassed($client, $order, $pollTimeout)) {
                $this->cleanupDnsRecords($createdRecords);

                return AcmeResult::failure('DNS-01 domain validation failed. Check DNS records and propagation.');
            }

            // Refresh order status
            $order = $client->order()->get($order->id);

            // Finalize
            if ($onProgress) {
                $onProgress(70, __('Finalizing certificate...'));
            }
            $result = $this->orderFinalizer->finalize($order, $domains);

            // Defer TXT record cleanup: the job will call runCleanup() after the
            // certificate is written to disk and Caddy reloaded, so LE's resolvers
            // can still see the records if anything retries during finalization.
            $captured = $createdRecords;
            $result->withCleanup(function () use ($captured): void {
                $this->cleanupDnsRecords($captured);
            });

            Log::info("DNS-01 certificate obtained for {$fqdn}.");

            return $result;
        } catch (\Throwable $e) {
            $this->cleanupDnsRecords($createdRecords);
            Log::error("DNS-01 certificate request failed for {$fqdn}: {$e->getMessage()}", [
                'fqdn' => $fqdn,
                'exception' => $e,
            ]);

            return AcmeResult::failure($e->getMessage());
        }
    }

    /**
     * @param  array<int, array{context: mixed, callback: callable}>  $records
     */
    private function cleanupDnsRecords(array $records): void
    {
        foreach ($records as $record) {
            try {
                ($record['callback'])($record['context']);
            } catch (\Throwable $e) {
                Log::warning("Failed to cleanup DNS record: {$e->getMessage()}");
            }
        }
    }
}
