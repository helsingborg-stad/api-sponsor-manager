<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\FieldSettings;
use Brain\Monkey\Functions;
use PluginTestCase\PluginTestCase;

class FieldSettingsTest extends PluginTestCase
{
    public function testHiddenDatabaseGroupDoesNotRenderTheUploadControl(): void
    {
        Functions\expect('acf_get_field_group')->once()->with(5501)->andReturn(['show_in_rest' => false]);
        Functions\expect('acf_render_field_setting')->never();
        (new FieldSettings())->renderSetting(['parent' => 5501]);
    }

    public function testLoadingAFieldPreservesItsStoredOptIn(): void
    {
        Functions\when('acf_get_field_group')->justReturn(['show_in_rest' => false]);
        $field = ['parent' => 'group_hidden', FieldSettings::SETTING_NAME => true];
        self::assertSame($field, (new FieldSettings())->defaultSetting($field));
        self::assertFalse((new FieldSettings())->defaultSetting(['parent' => 5501])[FieldSettings::SETTING_NAME]);
    }
}
