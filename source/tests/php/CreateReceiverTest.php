<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use AcfService\AcfService;
use ApiSponsorManager\AcfRestUpload\CreateReceiver;
use Mockery;
use PluginTestCase\PluginTestCase;
use WP_Error;
use WpService\WpService;

final class CreateReceiverTest extends PluginTestCase
{
    private CreateReceiver $receiver;

    public function setUp(): void
    {
        parent::setUp();
        $this->receiver = new CreateReceiver(Mockery::mock(WpService::class), Mockery::mock(AcfService::class));
    }

    public function testOnlyTheTrueOptInHeaderActivatesTheReceiver(): void
    {
        $existing = new \stdClass();
        self::assertSame($existing, $this->receiver->prepare($existing, null, $this->request()));
        self::assertNull($this->receiver->prepare(null, null, $this->request()));
        self::assertNull($this->receiver->prepare(null, null, $this->request('false')));
    }

    public function testOptInRequiresMultipartPostAndThenReachesRouteValidation(): void
    {
        self::assertInstanceOf(WP_Error::class, $this->receiver->prepare(null, null, $this->request('true', 'application/json')));
        self::assertInstanceOf(WP_Error::class, $this->receiver->prepare(null, null, $this->request('true', 'multipart/form-data', 'GET')));
        $server = new class {
            public function get_routes(): array
            {
                return [];
            }
        };
        self::assertInstanceOf(WP_Error::class, $this->receiver->prepare(null, $server, $this->request('true')));
    }

    private function request(?string $optIn = null, string $contentType = 'multipart/form-data', string $method = 'POST'): TestRestRequest
    {
        $request = new TestRestRequest($method, '/wp/v2/sponsor-offerings');
        $request->set_header('Content-Type', $contentType);
        if ($optIn !== null) {
            $request->set_header('X-ACF-Rest-Upload', $optIn);
        }
        return $request;
    }
}
