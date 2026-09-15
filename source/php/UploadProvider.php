<?php

declare(strict_types=1);

namespace ApiSponsorManager;

use AcfService\AcfService;
use WpService\WpService;

/** Host selection. This file must remain available without the embedded receiver. */
final class UploadProvider
{
    private bool $registered = false;
    private bool $selected = false;

    public function __construct(private WpService $wpService, private AcfService $acfService) {}

    public function addHooks(): void
    {
        if ($this->registered) { return; }
        $this->registered = true;
        $this->wpService->addAction('init', [$this, 'select'], 20);
    }

    public function select(): void
    {
        if ($this->selected) { return; }
        $this->selected = true;
        $provider = $this->wpService->applyFilters('AcfRestUpload/provider', null);
        if ($provider === null) {
            $this->bootEmbedded();
            return;
        }
        if (!is_array($provider) || ($provider['api_version'] ?? null) !== 1
            || !is_array($provider['protocol_versions'] ?? null) || !in_array(2, $provider['protocol_versions'], true)
            || !is_callable($provider['boot'] ?? null)) {
            $this->unavailable('Incompatible provider. Expected API 1, HTTP protocol 2, and callable boot.');
            return;
        }
        ($provider['boot'])($this->wpService, $this->acfService);
    }

    private function bootEmbedded(): void
    {
        // Keep the historical version-1 machinery until the separate removal task.
        foreach (['CreateReceiver', 'CreateImage', 'FieldSettings', 'Receiver', 'IdempotencyStore',
            'BracketPath', 'InvalidRequestException', 'ProtocolV1', 'NullInjector', 'FileReferenceCollector',
            'OperationFingerprint', 'FileReference', 'PartKeyValidator', 'UpdateSnapshot'] as $file) {
            if (!is_readable(__DIR__ . '/AcfRestUpload/' . $file . '.php')) {
                $this->unavailable('Embedded provider files are unavailable.');
                return;
            }
        }
        (new AcfRestUpload\FieldSettings())->addHooks();
        (new AcfRestUpload\Receiver())->addHooks();
        (new AcfRestUpload\CreateReceiver($this->wpService, $this->acfService))->addHooks();
    }

    private function unavailable(string $diagnostic): void
    {
        error_log('AcfRestUpload: ' . $diagnostic);
        // ACF initializes at priority 10 and can replace earlier filter responses.
        $this->wpService->addFilter('rest_pre_dispatch', [$this, 'rejectUnavailable'], 20, 3);
    }

    public function rejectUnavailable(mixed $response, mixed $server, \WP_REST_Request $request): mixed
    {
        if ($request->get_header('X-ACF-Rest-Upload-Version') !== '2') { return $response; }
        foreach ($this->wpService->applyFilters('AcfRestUpload/destinations', []) as $route => $destination) {
            if ($request->get_route() === $route || str_starts_with($request->get_route(), $route . '/')) {
                return new \WP_Error('acf_rest_upload_unavailable', 'The image upload provider is unavailable.', ['status' => 503]);
            }
        }
        return $response;
    }
}
