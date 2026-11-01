<?php

namespace Blessing\Flarum\Api;

use Flarum\Api\Schema;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Exposes the login button configuration to the forum frontend.
 *
 * In Flarum 2.x the forum payload is described by API resources instead of
 * serializers, so these attributes are registered as fields of
 * {@see \Flarum\Api\Resource\ForumResource}.
 */
class AddForumAttributes
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * The display name for the provider, configurable in the admin settings.
     */
    private function providerName(): string
    {
        $name = trim((string) $this->settings->get('blessing-oauth-client.provider_name'));

        return $name !== ''
            ? $name
            : (string) $this->translator->trans('blessing-oauth-client.api.default-provider-name');
    }

    /**
     * @return array<int, \Tobyz\JsonApiServer\Schema\Field\Field>
     */
    public function __invoke(): array
    {
        $fields = [
            Schema\Boolean::make('blessing-oauth-client.enabled')
                ->get(fn (): bool => trim((string) $this->settings->get('blessing-oauth-client.base_url')) !== ''
                    && trim((string) $this->settings->get('blessing-oauth-client.app_id')) !== ''
                    && trim((string) $this->settings->get('blessing-oauth-client.app_secret')) !== ''),

            // Name used inside the account-linking panel, e.g. "Blessing Skin"
            // or the name of another skin site.
            Schema\Str::make('blessing-oauth-client.providerName')
                ->get(fn (): string => $this->providerName()),

            Schema\Str::make('blessing-oauth-client.loginTitle')
                ->get(fn (): string => (string) ($this->settings->get('blessing-oauth-client.button_title')
                    ?: $this->translator->trans('blessing-oauth-client.api.default-login-button-title', [
                        'provider' => $this->providerName(),
                    ]))),

            Schema\Str::make('blessing-oauth-client.loginIcon')
                ->get(fn (): string => (string) ($this->settings->get('blessing-oauth-client.button_icon')
                    ?: 'far fa-id-card')),

            Schema\Str::make('blessing-oauth-client.loginIconImage')
                ->get(function (): string {
                    // An uploaded icon takes precedence over the manual URL.
                    $uploaded = trim((string) $this->settings->get('blessing-oauth-client.button_icon_upload_url'));

                    if ($uploaded !== '' && preg_match('#^(https?://|/)#i', $uploaded)) {
                        return $uploaded;
                    }

                    $url = trim((string) $this->settings->get('blessing-oauth-client.button_icon_image'));

                    // Only allow absolute http(s) URLs or root-relative paths.
                    if ($url === '' || ! preg_match('#^(https?://|/)#i', $url)) {
                        return '';
                    }

                    return $url;
                }),
        ];

        // Custom alert texts. An empty setting means "use the translation".
        $messages = [
            'messageLinked' => 'message_linked',
            'messageError' => 'message_error',
            'messageErrorState' => 'message_error_state',
            'messageErrorProvider' => 'message_error_provider',
            'messageErrorToken' => 'message_error_token',
            'messageErrorUserinfo' => 'message_error_userinfo',
            'messageErrorIdentifier' => 'message_error_identifier',
        ];

        foreach ($messages as $attribute => $setting) {
            $fields[] = Schema\Str::make('blessing-oauth-client.'.$attribute)
                ->get(fn (): string => trim((string) $this->settings->get(
                    'blessing-oauth-client.'.$setting,
                    ''
                )));
        }

        return $fields;
    }
}
