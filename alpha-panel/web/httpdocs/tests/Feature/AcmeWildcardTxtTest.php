<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Domain;
use App\Services\Acme\AcmeClientFactory;
use App\Services\Acme\AcmeResult;
use App\Services\Acme\AcmeService;
use App\Services\Acme\Dns01ChallengeRunner;
use App\Services\Acme\Http01ChallengeRunner;
use App\Services\CloudflareDnsService;
use App\Services\LocalDnsService;
use App\Services\PortainerService;
use Mockery;
use Tests\TestCase;

/**
 * Guards the wildcard DNS-01 regression: an apex certificate order produces two
 * authorizations that share the TXT name `_acme-challenge.<apex>` but carry
 * different values, and BOTH values must stay live during validation. The
 * per-record stale-cleanup must never delete a sibling value created in the
 * same run.
 */
class AcmeWildcardTxtTest extends TestCase
{
    /**
     * @test
     */
    public function it_preserves_sibling_txt_value_for_wildcard_orders(): void
    {
        $apex = 'alphamessage.net';
        $recordName = "_acme-challenge.{$apex}";
        $value1 = 'FIRST-authorization-value';
        $value2 = 'SECOND-wildcard-value';

        $deletedIds = [];
        $addedContents = [];

        $cloudflare = Mockery::mock(CloudflareDnsService::class);
        $cloudflare->shouldReceive('getZoneId')->with($apex)->andReturn('zone-1');

        // First callback: no records exist yet.
        // Second callback: the first TXT (value1) is now live and must survive.
        $cloudflare->shouldReceive('listRecords')
            ->with('zone-1', $recordName)
            ->andReturnUsing(function () use (&$addedContents, $recordName): array {
                return array_map(function (string $content) use ($recordName): \stdClass {
                    return (object) [
                        'id' => 'rec-'.$content,
                        'type' => 'TXT',
                        'name' => $recordName,
                        'content' => $content,
                    ];
                }, $addedContents);
            });

        $cloudflare->shouldReceive('deleteRecord')
            ->andReturnUsing(function (string $zoneId, string $recordId) use (&$deletedIds, &$addedContents): bool {
                $deletedIds[] = $recordId;
                $addedContents = array_values(array_filter(
                    $addedContents,
                    fn (string $c): bool => 'rec-'.$c !== $recordId,
                ));

                return true;
            });

        $cloudflare->shouldReceive('addRecord')
            ->andReturnUsing(function (string $zoneId, array $data) use (&$addedContents): bool {
                $addedContents[] = $data['content'];

                return true;
            });

        // Capture the createTxtRecord callback the service hands to the runner.
        $capturedCreate = null;
        $runner = Mockery::mock(Dns01ChallengeRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (...$args) use (&$capturedCreate): AcmeResult {
                $capturedCreate = $args[2]; // createTxtRecord (named 3rd param)

                return AcmeResult::failure('stubbed — not run end to end');
            });

        $factory = Mockery::mock(AcmeClientFactory::class);
        $factory->shouldReceive('resetClient')->andReturnNull();
        $factory->shouldReceive('getSettings')->andReturn([
            'dns_propagation_wait' => 0,
            'poll_timeout' => 1,
        ]);

        $service = new AcmeService(
            $factory,
            Mockery::mock(Http01ChallengeRunner::class),
            $runner,
            $cloudflare,
            Mockery::mock(LocalDnsService::class),
            Mockery::mock(PortainerService::class),
        );

        $domain = new Domain(['fqdn' => $apex]);
        $service->requestCertificateDnsCloudflare($domain);

        $this->assertIsCallable($capturedCreate);

        // Simulate the two authorizations of a wildcard order, in order.
        $capturedCreate($recordName, $value1);
        $capturedCreate($recordName, $value2);

        // The sibling value1 must NOT have been deleted; both values live now.
        $this->assertContains($value1, $addedContents, 'First TXT value was wiped by the second call.');
        $this->assertContains($value2, $addedContents);
        $this->assertEmpty($deletedIds, 'No sibling TXT should be deleted during a clean wildcard run.');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
