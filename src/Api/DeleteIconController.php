<?php

namespace Blessing\Flarum\Api;

use Flarum\Api\Controller\DeleteLogoController;

/**
 * Removes the uploaded login-button icon and clears the icon URL setting.
 */
class DeleteIconController extends DeleteLogoController
{
    protected string $filePathSettingKey = 'blessing-oauth-client.button_icon_upload';

    protected function afterDelete(): void
    {
        $this->settings->set('blessing-oauth-client.button_icon_upload_url', null);
    }
}
