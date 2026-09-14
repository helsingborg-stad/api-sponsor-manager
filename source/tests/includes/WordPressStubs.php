<?php

/**
 * Minimal WordPress class stubs for the standalone (unit) PHPUnit suite.
 *
 * Every definition is guarded, so the WordPress integration suite - which
 * boots real WordPress core - always uses the real implementations.
 */

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, string> */
        private array $errors = [];

        /** @var array<string, mixed> */
        private array $error_data = [];

        public function __construct($code = '', $message = '', $data = [])
        {
            if (is_string($code) && $code !== '') {
                $this->errors[$code] = $message;
            }

            if (is_array($data)) {
                $this->error_data[(string) $code] = $data;
            }
        }

        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_code(): string
        {
            return (string) (array_key_first($this->errors) ?? '');
        }

        public function get_error_message($code = ''): string
        {
            $code = $code === '' ? $this->get_error_code() : (string) $code;

            return $this->errors[$code] ?? '';
        }

        public function get_error_data($code = ''): mixed
        {
            $code = $code === '' ? $this->get_error_code() : (string) $code;

            return $this->error_data[$code] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private mixed $data;

        private int $status;

        /** @var array<string, string> */
        private array $headers = [];

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        public function header(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $method;

        private string $route;

        /** @var array<string, mixed> */
        private array $headers = [];

        /** @var array<array-key, mixed> */
        private array $params = [];

        /** @var array<array-key, mixed> */
        private array $body_params = [];

        /** @var array<array-key, mixed> */
        private array $file_params = [];

        public function __construct($method = '', $route = '')
        {
            $this->method = strtoupper((string) $method);
            $this->route = (string) $route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[$key] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? $this->body_params[$key] ?? null;
        }

        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_params(): array
        {
            return [...$this->body_params, ...$this->params];
        }

        public function get_body_params(): array
        {
            return $this->body_params;
        }

        public function set_body_params(array $params): void
        {
            $this->body_params = $params;
        }

        public function get_file_params(): array
        {
            return $this->file_params;
        }

        public function set_file_params(array $params): void
        {
            $this->file_params = $params;
        }
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;

        public string $post_type = '';

        public string $post_status = '';

        public function __construct($post = [])
        {
            foreach ((array) $post as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}
