<?php

namespace Blessing\Flarum\Api;

use Flarum\Api\Controller\UploadImageController;
use Flarum\Foundation\ValidationException;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * Stores an uploaded login-button icon in Flarum's asset store and exposes it
 * through the same setting the manual "icon image URL" field uses.
 */
class UploadIconController extends UploadImageController
{
    protected string $fileExtension = 'png';

    /**
     * Internal setting used to remember the stored filename so a later upload
     * or delete can remove the previous file.
     */
    protected string $filePathSettingKey = 'blessing-oauth-client.button_icon_upload';

    protected string $filenamePrefix = 'icon';

    protected function makeImage(UploadedFileInterface $file): EncodedImageInterface
    {
        if ($file->getSize() > 2 * 1024 * 1024) {
            throw new ValidationException([
                'icon' => 'The icon may not be larger than 2 MB.',
            ]);
        }

        try {
            return $this->imageManager
                ->read($file->getStream()->getMetadata('uri'))
                ->scaleDown(width: 128, height: 128)
                ->toPng();
        } catch (Throwable $e) {
            throw new ValidationException([
                'icon' => 'The icon must be a valid PNG, JPEG or WebP image.',
            ]);
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = parent::handle($request);

        $filename = $this->settings->get($this->filePathSettingKey);

        if ($filename) {
            $this->settings->set(
                'blessing-oauth-client.button_icon_upload_url',
                $this->uploadDir->url($filename)
            );
        }

        return $response;
    }
}
