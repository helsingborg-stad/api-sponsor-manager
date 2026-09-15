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
        if (!$this->isValidDescriptor($provider)) {
            $this->unavailable('Incompatible provider. Expected API 1, HTTP protocol 2, and callable boot.');
            return;
        }
        ($provider['boot'])($this->wpService, $this->acfService);
    }

    private function isValidDescriptor(mixed $provider): bool
    {
        return is_array($provider)
            && ($provider['api_version'] ?? null) === 1
            && is_array($provider['protocol_versions'] ?? null)
            && in_array(2, $provider['protocol_versions'], true)
            && is_callable($provider['boot'] ?? null);
    }

    private function bootEmbedded(): void
    {
        if (!$this->hasEmbeddedFiles()) {
            $this->unavailable('Embedded provider files are unavailable.');
            return;
        }
        (new AcfRestUpload\FieldSettings())->addHooks();
        (new AcfRestUpload\CreateReceiver($this->wpService, $this->acfService))->addHooks();
    }

    private function hasEmbeddedFiles(): bool
    {
        $files = ['CreateReceiver', 'CreateImage', 'FieldSettings'];
        return !in_array(false, array_map(
            static fn (string $file): bool => is_readable(__DIR__ . '/AcfRestUpload/' . $file . '.php'),
            $files
        ), true);
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
            // The trailing slash makes an exact match and subroutes a single prefix check.
            if (str_starts_with($request->get_route() . '/', $route . '/')) {
                return new \WP_Error('acf_rest_upload_unavailable', 'The image upload provider is unavailable.', ['status' => 503]);
            }
        }
        return $response;
    }
}
