<?php

namespace Blessing\Flarum\Providers;

use Blessing\Flarum\Events\ParsingResourceOwner;
use Blessing\Flarum\ResourceOwner;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class PassportProvider extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * Scopes requested from Blessing Skin.
     *
     * - `profile` and `email` are used by the Blessing Skin auth-service
     *   plugin (OIDC style userinfo).
     * - `User.Read` is the scope of Blessing Skin's own Passport powered API.
     *
     * Both servers silently drop the scopes they do not know, so requesting
     * all of them keeps the same client working with both implementations.
     */
    protected const SCOPES = ['profile', 'email', 'User.Read'];

    /**
     * Endpoints that may expose the resource owner, in order of preference.
     *
     * The auth-service plugin exposes `/oauth/userinfo`; Blessing Skin's
     * Passport API exposes the current user at `/api/user`.
     */
    protected const RESOURCE_OWNER_PATHS = ['oauth/userinfo', 'api/user'];

    /**
     * The settings repository used to read the Blessing Skin configuration.
     */
    protected SettingsRepositoryInterface $settings;

    /**
     * The event dispatcher used to allow listeners to modify the resource owner.
     */
    protected Dispatcher $events;

    public function __construct(array $options = [], array $collaborators = [])
    {
        $this->settings = $options['settings'];
        $this->events = $options['events'];

        unset($options['settings'], $options['events']);

        parent::__construct($options, $collaborators);
    }

    /**
     * Returns the base URL for authorizing a client.
     *
     * Eg. https://oauth.service.com/authorize
     */
    public function getBaseAuthorizationUrl(): string
    {
        return $this->baseUrl().'oauth/authorize';
    }

    /**
     * Returns the base URL for requesting an access token.
     *
     * Eg. https://oauth.service.com/token
     */
    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->baseUrl().'oauth/token';
    }

    /**
     * Returns the URL for requesting the resource owner's details.
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return $this->baseUrl().self::RESOURCE_OWNER_PATHS[0];
    }

    /**
     * Fetch the resource owner from the first endpoint that returns a user.
     *
     * This allows the same client to work with the auth-service plugin's
     * `/oauth/userinfo` endpoint and with Blessing Skin's Passport powered
     * `/api/user` endpoint.
     */
    public function getResourceOwner(AccessToken $token): ResourceOwnerInterface
    {
        $errors = [];

        foreach (self::RESOURCE_OWNER_PATHS as $path) {
            try {
                $request = $this->getAuthenticatedRequest(
                    self::METHOD_GET,
                    $this->baseUrl().$path,
                    $token
                );

                // Some Apache/PHP-FPM setups strip the Authorization header
                // before it reaches Laravel. Send the token in a custom header
                // as well; the auth-service plugin accepts either one.
                $request = $request->withHeader(
                    'X-Blessing-Token',
                    'Bearer '.$token->getToken()
                );

                $response = $this->getParsedResponse($request);

                if (! is_array($response)) {
                    $errors[] = $path.': unexpected response body';

                    continue;
                }

                $owner = $this->createResourceOwner($response, $token);

                if ($owner->getId() !== null) {
                    return $owner;
                }

                $errors[] = $path.': response does not contain a user identifier';
            } catch (Throwable $e) {
                $errors[] = $path.': '.$e->getMessage();
            }
        }

        throw new IdentityProviderException(
            'Could not fetch the Blessing Skin user profile ('.implode('; ', $errors).')',
            0,
            []
        );
    }

    /**
     * Returns the default scopes used by this provider.
     *
     * This should only be the scopes that are required to request the details
     * of the resource owner, rather than all the available scopes.
     */
    protected function getDefaultScopes(): array
    {
        return self::SCOPES;
    }

    /**
     * Blessing Skin expects the scope parameter to be space separated.
     */
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    /**
     * Checks a provider response for errors.
     *
     * @throws IdentityProviderException
     *
     * @param array|string $data Parsed response data
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (! is_array($data) || empty($data['error'])) {
            return;
        }

        $message = (string) $data['error'];

        if (! empty($data['error_description'])) {
            $message .= ': '.$data['error_description'];
        }

        throw new IdentityProviderException($message, $response->getStatusCode(), $data);
    }

    /**
     * Generates a resource owner object from a successful resource owner
     * details request.
     */
    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        $this->events->dispatch(new ParsingResourceOwner($response));

        return new ResourceOwner($response);
    }

    /**
     * Build the base URL of the Blessing Skin installation, with a trailing slash.
     */
    protected function baseUrl(): string
    {
        return Str::finish((string) $this->settings->get('blessing-oauth-client.base_url'), '/');
    }
}
