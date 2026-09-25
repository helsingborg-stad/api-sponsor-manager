<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

final class TestRestRequest extends \WP_REST_Request
{
    public function __construct(string $method = '', string $route = '')
    {
        $this->method = $method;
        $this->route = $route;
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_route(): string
    {
        return $this->route;
    }

    public function set_header($key, $value): void
    {
        $this->headers[$this->headerKey((string) $key)] = (string) $value;
    }

    public function get_header($key): ?string
    {
        return $this->headers[$this->headerKey((string) $key)] ?? null;
    }

    public function get_content_type(): ?array
    {
        $header = $this->get_header('Content-Type');
        if ($header === null) {
            return null;
        }
        return ['value' => strtolower(trim(explode(';', $header, 2)[0])), 'parameters' => []];
    }

    private function headerKey(string $key): string
    {
        return strtolower(str_replace('_', '-', $key));
    }
}

final class TestRestResponse extends \WP_REST_Response
{
    public function __construct(private array $testData, private int $testStatus)
    {
    }

    public function get_status(): int
    {
        return $this->testStatus;
    }

    public function get_data(): array
    {
        return $this->testData;
    }
}
