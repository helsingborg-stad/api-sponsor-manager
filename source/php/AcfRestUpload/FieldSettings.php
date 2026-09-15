<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use ApiSponsorManager\Helper\HooksRegistrar\Hookable;

/**
 * Registers the "Allow multipart REST upload" setting on ACF file fields.
 *
 * The setting is available on image, file and gallery fields and defaults to
 * false, so existing field groups keep their current behaviour until the
 * setting is explicitly enabled.
 */
class FieldSettings implements Hookable
{
    /**
     * ACF field types that may accept a multipart REST upload.
     */
    private const FIELD_TYPES = ['image', 'file', 'gallery'];

    /**
     * Key used to store the setting on the field.
     */
    public const SETTING_NAME = 'allow_multipart_rest_upload';

    /**
     * Register the field settings hooks.
     */
    public function addHooks(): void
    {
        foreach (self::FIELD_TYPES as $type) {
            add_action("acf/render_field_settings/type={$type}", [$this, 'renderSetting']);
            add_filter("acf/load_field/type={$type}", [$this, 'defaultSetting']);
        }
    }

    /**
     * Render the checkbox field setting.
     *
     * Known non-REST groups do not render the control. Runtime authorization
     * remains independent of the stored opt-in value.
     *
     * @param array $field
     */
    public function renderSetting(array $field): void
    {
        if (!$this->isRestEnabledGroup($field)) {
            return;
        }

        acf_render_field_setting($field, [
            'label' => __('Allow multipart REST upload', 'api-sponsor-manager'),
            'instructions' => '',
            'name' => self::SETTING_NAME,
            'type' => 'true_false',
            'ui' => 1,
            'default_value' => 0,
        ]);
    }

    /**
     * Default the setting to false when it has not been configured.
     *
     * Preserve stored intent. The receiver independently checks current REST exposure.
     *
     * @param array $field
     * @return array
     */
    public function defaultSetting(array $field): array
    {
        $field[self::SETTING_NAME] ??= false;

        return $field;
    }

    /**
     * Whether the field's containing group is exposed through the REST API.
     *
     * The field group lookup is guarded because ACF may not be loaded, or the
     * field may not be attached to a stored group yet. In those cases the group
     * is treated as REST enabled so the setting is not hidden unexpectedly.
     *
     * @param array $field
     */
    private function isRestEnabledGroup(array $field): bool
    {
        if (!function_exists('acf_get_field_group')) {
            return true;
        }

        $parent = $field['parent'] ?? '';

        if ((!is_string($parent) && !is_int($parent)) || $parent === '' || $parent === 0) {
            return true;
        }

        $group = acf_get_field_group($parent);

        if (!is_array($group)) {
            return true;
        }

        return !empty($group['show_in_rest']);
    }
}
