<?php

namespace Blessing\Flarum;

use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('forum'))
        // Use a dedicated path: other OAuth extensions register /auth/{provider},
        // which shadows a static /auth/blessing route in FastRoute.
        ->get('/blessing-oauth/callback', 'auth.blessing', Controllers\PassportController::class),

    (new Extend\Routes('api'))
        ->post('/blessing-oauth/icon', 'blessing-oauth.icon.upload', Api\UploadIconController::class)
        ->delete('/blessing-oauth/icon', 'blessing-oauth.icon.delete', Api\DeleteIconController::class)
        ->get('/blessing-oauth/link', 'blessing-oauth.link.show', Api\LinkStatusController::class)
        ->delete('/blessing-oauth/link', 'blessing-oauth.link.delete', Api\UnlinkController::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\AddForumAttributes::class),
];
