<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

use AcfService\Implementations\NativeAcfService;
use ApiSponsorManager\UploadProvider;
use WpService\Implementations\NativeWpService;
use WP_REST_Request;

final class NativeUploadProviderTest extends NativeTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        // Replace the default provider in this fixture, not in the plugin or shared bootstrap.
        foreach ($GLOBALS['wp_filter'] as $hook => $filter) {
            foreach ($filter->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $entry) {
                    $callback = $entry['function'];
                    if (is_array($callback) && is_object($callback[0])
                        && str_starts_with(get_class($callback[0]), 'ApiSponsorManager\\AcfRestUpload\\')) {
                        remove_filter($hook, $callback, $priority);
                    }
                }
            }
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $GLOBALS['wp_rest_server'] = null;
    }

    public function testExternalCompletionReachesTheSponsorConsumer(): void
    {
        $wp = new NativeWpService();
        $acf = new NativeAcfService();
        $boots = 0;
        $routes = [];
        $policy = [];
        $request = new WP_REST_Request('POST', '/wp/v2/sponsor-offerings');
        $request->set_header('X-ACF-Rest-Upload-Version', '2');
        add_filter('AcfRestUpload/provider', static function () use ($wp, $acf, &$boots, &$routes, &$policy, $request): array { return [
            'api_version' => 1, 'protocol_versions' => [2],
            'boot' => static function ($actualWp, $actualAcf) use ($wp, $acf, &$boots, &$routes, &$policy, $request): void {
                self::assertSame($wp, $actualWp);
                self::assertSame($acf, $actualAcf);
                $boots++;
                $routes = $actualWp->applyFilters('AcfRestUpload/destinations', []);
                $policy = $actualWp->applyFilters('AcfRestUpload/imagePolicy', ['max_size' => 2], ['type' => 'image'], $request);
                $actualWp->addAction('fzsw/test-completed', static function ($id, $actualRequest) use ($actualWp): void {
                    $actualWp->doAction('AcfRestUpload/created', $id, null, $actualRequest);
                }, 10, 2);
            },
        ]; });
        $provider = new UploadProvider($wp, $acf);
        $provider->select();
        $provider->select();
        self::assertSame(1, $boots);
        self::assertSame([
            '/wp/v2/sponsor-assignments' => ['post_type' => 'assignment', 'image_field' => 'image'],
            '/wp/v2/sponsor-offerings' => ['post_type' => 'offering', 'image_field' => 'image'],
        ], $routes);
        self::assertSame(['max_size' => 2], $policy);
        update_field('field_69bbaf272549d', [[
            'trigger' => 'submit', 'post_type' => 'offering', 'recipients' => 'external@example.test',
            'subject' => 'External {ID}', 'message' => 'Completed',
        ]], 'options');
        $mail = [];
        add_filter('pre_wp_mail', static function ($return, $attributes) use (&$mail) { $mail[] = $attributes; return true; }, 10, 2);
        apply_filters('rest_request_before_callbacks', null, [], $request);
        $id = self::factory()->post->create(['post_type' => 'offering', 'post_status' => 'draft']);
        self::assertSame([], $mail);
        do_action('fzsw/test-completed', $id, clone $request);
        self::assertSame([], $mail, 'Completion must carry the exact request.');
        do_action('fzsw/test-completed', $id, $request);
        do_action('fzsw/test-completed', $id, $request);
        self::assertCount(1, $mail);
        self::assertSame(['external@example.test'], $mail[0]['to']);
        self::assertSame('External ' . $id, $mail[0]['subject']);
        apply_filters('rest_request_after_callbacks', null, [], $request);
    }

    public function testUnavailableProviderRejectsOnlyVersionTwoDestinationFamilies(): void
    {
        add_filter('AcfRestUpload/provider', static fn () => ['api_version' => 99]);
        $log = tempnam(sys_get_temp_dir(), 'fzsw-native-log-');
        $previous = ini_set('error_log', $log);
        try {
            (new UploadProvider(new NativeWpService(), new NativeAcfService()))->select();
            self::assertStringContainsString('Incompatible provider', file_get_contents($log));
        } finally {
            ini_set('error_log', $previous);
            unlink($log);
        }
        foreach (['/wp/v2/sponsor-offerings', '/wp/v2/sponsor-assignments', '/wp/v2/sponsor-offerings/42', '/wp/v2/sponsor-assignments/42'] as $route) {
            $request = new WP_REST_Request('POST', $route);
            $request->set_header('X-ACF-Rest-Upload-Version', '2');
            $response = rest_get_server()->dispatch($request);
            self::assertSame(503, $response->get_status());
            self::assertSame('acf_rest_upload_unavailable', $response->get_data()['code']);
        }
        foreach (['/wp/v2/posts', '/wp/v2/sponsor-offerings', '/wp/v2/sponsor-assignments'] as $route) {
            $request = new WP_REST_Request('GET', $route);
            if ($route === '/wp/v2/posts') { $request->set_header('X-ACF-Rest-Upload-Version', '2'); }
            self::assertSame(200, rest_get_server()->dispatch($request)->get_status());
        }
    }
}
