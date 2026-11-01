<?php

namespace Blessing\Flarum;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Tool\ArrayAccessorTrait;

class ResourceOwner implements ResourceOwnerInterface
{
    use ArrayAccessorTrait;

    public function __construct(
        private array $response = []
    ) {
    }

    /**
     * Returns the identifier of the authorized resource owner.
     *
     * Blessing Skin's Passport based API exposes `uid`, while the auth-service
     * plugin (OIDC) follows the standard and exposes `sub`.
     */
    public function getId(): int|string|null
    {
        foreach (['sub', 'uid', 'id'] as $key) {
            $id = $this->getValueByKey($this->response, $key);

            if (is_int($id) || (is_string($id) && $id !== '')) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Get resource owner email.
     */
    public function getEmail(): ?string
    {
        return $this->firstString(['email']);
    }

    /**
     * Get resource owner name.
     *
     * The auth-service plugin returns `preferred_username` (OIDC) and
     * `display_name`; the Passport API returns `nickname`.
     */
    public function getName(): ?string
    {
        return $this->firstString(['preferred_username', 'display_name', 'nickname', 'username', 'name']);
    }

    /**
     * Get the URL of the resource owner avatar, if the provider exposes one.
     */
    public function getAvatar(): ?string
    {
        return $this->firstString(['avatar_url', 'picture']);
    }

    /**
     * Whether the provider reports the resource owner's email as verified.
     */
    public function isEmailVerified(): bool
    {
        return (bool) $this->getValueByKey($this->response, 'email_verified', false);
    }

    /**
     * Return all of the owner details available as an array.
     */
    public function toArray(): array
    {
        return $this->response;
    }

    /**
     * Return the first non-empty string value for one of the given keys.
     */
    private function firstString(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->getValueByKey($this->response, $key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
