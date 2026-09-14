<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\UpdateSnapshot;
use Brain\Monkey\Functions;
use PluginTestCase\PluginTestCase;
use WP_Post;

class UpdateSnapshotTest extends PluginTestCase
{
    private array $meta = [];
    private WP_Post $post;
    private bool $failRestore = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->post = new class(['ID' => 77, 'post_status' => 'draft']) extends WP_Post {
            public string $post_title = 'Original \\ title';
        };
        Functions\when('get_post')->alias(fn () => $this->post);
        Functions\when('wp_slash')->alias(static function ($value) {
            $slash = static fn ($item) => is_string($item) ? addslashes($item) : $item;
            return is_array($value) ? array_map($slash, $value) : $slash($value);
        });
        Functions\when('wp_update_post')->alias(function (array $values): int {
            if (!$this->failRestore) {
                foreach ($values as $name => $value) {
                    $this->post->{$name} = is_string($value) ? stripslashes($value) : $value;
                }
            }
            return 77;
        });
        Functions\when('metadata_exists')->alias(fn ($type, $id, $name): bool => array_key_exists($name, $this->meta));
        Functions\when('get_post_meta')->alias(fn ($id, $name) => $this->meta[$name] ?? '');
        Functions\when('update_post_meta')->alias(function ($id, $name, $value): bool {
            $this->meta[$name] = is_string($value) ? stripslashes($value) : $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $name): bool {
            unset($this->meta[$name]);
            return true;
        });
        Functions\when('get_field')->alias(function (string $key, int $id, bool $format = true) {
            self::assertFalse($format, 'Snapshots and readback must use raw ACF values.');
            self::assertSame(77, $id);
            self::assertStringStartsWith('field_', $key);
            $name = substr($key, 6);
            return array_key_exists($name, $this->meta) ? $this->meta[$name] : null;
        });
        Functions\when('update_field')->alias(function (string $key, mixed $value): bool {
            $value = is_string($value) ? stripslashes($value) : $value;
            $name = substr($key, 6);
            if ($this->failRestore) {
                return true; // A claimed success is not proof of restored storage.
            }
            if ($value === null) {
                unset($this->meta[$name]); // ACF treats null as deletion.
                return true;
            }
            $changed = !array_key_exists($name, $this->meta) || $this->meta[$name] !== $value;
            $this->meta[$name] = $value;
            $this->meta['_' . $name] = $key;
            return $changed;
        });
        Functions\when('delete_field')->alias(function (string $key): bool {
            $name = substr($key, 6);
            unset($this->meta[$name], $this->meta['_' . $name]);
            return true;
        });
    }

    public function testRestoresNativeAndAllSubmittedRawAcfValues(): void
    {
        $this->meta = ['description' => 'Before \\ after', '_description' => 'field_description', 'image' => 123, '_image' => 'field_image', 'untouched' => 'Keep'];
        $before = $this->meta;
        $snapshot = UpdateSnapshot::capture($this->post, ['title' => 'After', 'status' => 'publish', 'acf' => ['description' => 'After', 'image' => 901]], $this->resolve(...));
        self::assertInstanceOf(UpdateSnapshot::class, $snapshot);
        $this->post->post_title = 'After';
        $this->post->post_status = 'publish';
        $this->meta['description'] = 'After';
        $this->meta['image'] = 901;
        self::assertTrue($snapshot->restore());
        self::assertSame('Original \\ title', $this->post->post_title);
        self::assertSame('draft', $this->post->post_status);
        self::assertSame($before, $this->meta);
        self::assertTrue($snapshot->restore(), 'Unchanged values are not failed restoration.');
    }

    public function testDistinguishesAbsentFalseAndNullValues(): void
    {
        $this->meta = ['false_value' => false, 'null_value' => null];
        $snapshot = UpdateSnapshot::capture($this->post, ['acf' => ['false_value' => true, 'null_value' => 'new', 'absent' => 'new']], $this->resolve(...));
        $this->meta = ['false_value' => true, 'null_value' => 'new', 'absent' => 'new'];
        self::assertTrue($snapshot->restore());
        self::assertSame(['false_value' => false, 'null_value' => null], $this->meta);
    }

    public function testDetectsFailedRestorationDespiteSuccessfulReturnValues(): void
    {
        $this->meta = ['description' => 'Before'];
        $snapshot = UpdateSnapshot::capture($this->post, ['title' => 'After', 'acf' => ['description' => 'After']], $this->resolve(...));
        $this->post->post_title = 'After';
        $this->meta['description'] = 'After';
        $this->failRestore = true;
        self::assertFalse($snapshot->restore());
    }

    private function resolve(string $name): array
    {
        return ['name' => $name, 'key' => 'field_' . $name];
    }
}
