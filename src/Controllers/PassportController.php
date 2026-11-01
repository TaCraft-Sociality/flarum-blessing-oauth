<?php

namespace Blessing\Flarum\Controllers;

use Blessing\Flarum\Providers\PassportProvider;
use Dflydev\FigCookies\FigResponseCookies;
use Dflydev\FigCookies\Modifier\SameSite;
use Dflydev\FigCookies\SetCookie;
use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\RedirectResponse;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class PassportController implements RequestHandlerInterface
{
    /**
     * Session key holding the OAuth2 state parameter.
     */
    private const SESSION_STATE = 'blessing-oauth-client.oauth2state';

    /**
     * Session key holding the path to redirect back to after authentication.
     */
    private const SESSION_RETURN_TO = 'blessing-oauth-client.returnTo';

    /**
     * Set when a logged-in user starts the account-linking flow.
     */
    private const SESSION_LINK = 'blessing-oauth-client.link';

    /**
     * The user the provider account should be linked to.
     */
    private const SESSION_LINK_USER = 'blessing-oauth-client.linkUser';

    /**
     * Dedicated cookie holding the OAuth2 state.
     *
     * The Flarum session cookie may be configured with `SameSite=Strict`, in
     * which case the browser does not send it on the cross-site redirect back
     * from Blessing Skin. This cookie is always set with `SameSite=Lax`, so the
     * OAuth callback keeps working in that case.
     */
    private const STATE_COOKIE = 'blessing_oauth_state';

    /**
     * Lifetime of the state cookie in seconds.
     */
    private const STATE_COOKIE_TTL = 600;

    /**
     * Query parameter used to tell the forum that logging in failed.
     */
    private const ERROR_PARAM = 'blessing_oauth_error';

    /**
     * Query parameter carrying a short technical hint for the failure.
     */
    private const ERROR_HINT_PARAM = 'blessing_oauth_hint';

    /**
     * Marker added to the return path so the forum frontend can tell this
     * extension's registration token apart from those of other OAuth clients.
     */
    private const SOURCE_PARAM = 'blessing_oauth';

    private const SOURCE_VALUE = 'blessing';

    public function __construct(
        protected ResponseFactory $response,
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected Dispatcher $events,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Session $session */
        $session = $request->getAttribute('session');
        $queryParams = $request->getQueryParams();
        $actor = RequestUtil::getActor($request);
        $linkRequested = $this->isLinkRequest($queryParams);

        // Account linking: the user is already logged in and is adding Blessing
        // Skin to their existing account instead of logging in with it.
        if ($linkRequested && ! $actor->exists) {
            return $this->linkFailure($session, $this->getReturnTo($request), 'login_required');
        }

        $linkInProgress = $session->get(self::SESSION_LINK) === true;
        $linkUserId = $session->get(self::SESSION_LINK_USER);

        $provider = $this->getProvider(
            $this->url->to('forum')->route('auth.blessing')
        );

        // The user cancelled the authorization, or Blessing Skin rejected it.
        if ($error = Arr::get($queryParams, 'error')) {
            $this->logger->warning('[blessing-oauth-client] Authorization failed at the provider', [
                'error' => $error,
                'error_description' => Arr::get($queryParams, 'error_description'),
            ]);

            return $this->fail($session, 'provider');
        }

        $code = Arr::get($queryParams, 'code');

        // "0" is a valid authorization code value, but falsy in PHP.
        if (! is_string($code) || $code === '') {
            // Generate the state ourselves: league/oauth2-client 2.9 only sets
            // the provider state while building the authorization URL, so
            // reading getState() before getAuthorizationUrl() returns null.
            $state = bin2hex(random_bytes(16));
            $authUrl = $provider->getAuthorizationUrl(['state' => $state]);

            // A fresh authorization request supersedes an abandoned link attempt.
            $session->forget(self::SESSION_LINK);
            $session->forget(self::SESSION_LINK_USER);

            if ($linkRequested) {
                $session->put(self::SESSION_LINK, true);
                $session->put(self::SESSION_LINK_USER, (int) $actor->id);
            }

            $session->put(self::SESSION_STATE, $state);
            $session->put(self::SESSION_RETURN_TO, $this->getReturnTo($request));

            return $this->withStateCookie(
                new RedirectResponse($authUrl),
                $state
            );
        }

        $state = Arr::get($queryParams, 'state');
        $sessionState = $session->get(self::SESSION_STATE);
        $cookieState = $request->getCookieParams()[self::STATE_COOKIE] ?? null;

        $session->forget(self::SESSION_STATE);

        $expectedState = is_string($sessionState) && $sessionState !== ''
            ? $sessionState
            : (is_string($cookieState) && $cookieState !== '' ? $cookieState : null);

        if (! is_string($state) || $expectedState === null || ! hash_equals($expectedState, $state)) {
            $this->logger->warning('[blessing-oauth-client] Rejected OAuth callback with an invalid state', [
                'query_keys' => array_keys($queryParams),
                'state_present' => is_string($state) && $state !== '',
                'session_state_present' => is_string($sessionState) && $sessionState !== '',
                'cookie_state_present' => is_string($cookieState) && $cookieState !== '',
            ]);

            return $this->fail($session, 'state');
        }

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
        } catch (Throwable $e) {
            $this->logger->error('[blessing-oauth-client] Token exchange failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->fail($session, 'token', $this->errorHint($e));
        }

        try {
            /** @var \Blessing\Flarum\ResourceOwner $resourceOwner */
            $resourceOwner = $provider->getResourceOwner($token);
        } catch (Throwable $e) {
            $this->logger->error('[blessing-oauth-client] User profile request failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->fail($session, 'userinfo', $this->errorHint($e));
        }

        $identifier = $resourceOwner->getId();

        if ($identifier === null || $identifier === '') {
            $this->logger->warning('[blessing-oauth-client] Resource owner has no identifier', [
                'keys' => array_keys($resourceOwner->toArray()),
            ]);

            return $this->fail($session, 'identifier');
        }

        if ($linkInProgress) {
            $returnTo = $this->consumeReturnTo($session);

            if (! $actor->exists) {
                return $this->linkFailure($session, $returnTo, 'login_required');
            }

            if ($linkUserId === null || (int) $linkUserId === 0 || (int) $linkUserId !== (int) $actor->id) {
                return $this->linkFailure($session, $returnTo, 'state');
            }

            return $this->linkAccount($actor, (string) $identifier, $session, $returnTo);
        }

        $returnTo = $this->withSourceMarker($this->consumeReturnTo($session));

        $response = $this->response->make(
            'blessing',
            (string) $identifier,
            function (Registration $registration) use ($resourceOwner) {
                if ($email = $resourceOwner->getEmail()) {
                    $registration->provideTrustedEmail($email);
                }

                if ($name = $resourceOwner->getName()) {
                    $registration->suggestUsername($name);
                }

                if ($avatar = $resourceOwner->getAvatar()) {
                    $registration->provideAvatar($avatar);
                }

                $registration->setPayload($resourceOwner->toArray());
            },
            $returnTo
        );

        return $this->withoutStateCookie($response);
    }

    protected function getProvider(string $redirectUri): PassportProvider
    {
        // Blessing Skin installations that use a self-signed certificate would
        // otherwise fail with "self-signed certificate in certificate chain".
        // Verification stays enabled unless the admin explicitly disables it.
        $verifySsl = $this->settings->get('blessing-oauth-client.verify_ssl', true);

        $httpClient = new Client([
            'verify' => filter_var($verifySsl, FILTER_VALIDATE_BOOLEAN),
        ]);

        return new PassportProvider([
            'clientId' => $this->settings->get('blessing-oauth-client.app_id'),
            'clientSecret' => $this->settings->get('blessing-oauth-client.app_secret'),
            'redirectUri' => $redirectUri,
            'settings' => $this->settings,
            'events' => $this->events,
        ], [
            'httpClient' => $httpClient,
        ]);
    }

    /**
     * Attach the Blessing Skin account to the logged-in user.
     *
     * Linking an account that already belongs to somebody else is refused, and
     * linking a different provider account replaces the user's previous one.
     */
    protected function linkAccount(User $actor, string $identifier, Session $session, string $returnTo): RedirectResponse
    {
        $existing = LoginProvider::query()
            ->where('provider', 'blessing')
            ->where('identifier', $identifier)
            ->first();

        if ($existing !== null && (int) $existing->user_id !== (int) $actor->id) {
            return $this->linkFailure($session, $returnTo, 'already_linked');
        }

        LoginProvider::query()
            ->where('provider', 'blessing')
            ->where('user_id', $actor->id)
            ->where('identifier', '!=', $identifier)
            ->delete();

        $actor->loginProviders()->firstOrCreate([
            'provider' => 'blessing',
            'identifier' => $identifier,
        ]);

        $session->forget(self::SESSION_LINK);
        $session->forget(self::SESSION_LINK_USER);
        $session->forget(self::SESSION_RETURN_TO);

        // The frontend already reacts to these two flags: `blessing_oauth` marks
        // the flow as ours, `_flarum_linked` shows the "account linked" alert.
        $separator = str_contains($returnTo, '?') ? '&' : '?';

        return $this->withoutStateCookie(
            new RedirectResponse($returnTo.$separator.'blessing_oauth=blessing&_flarum_linked=blessing')
        );
    }

    /**
     * Abort a link flow, returning to the page the user started from.
     */
    protected function linkFailure(Session $session, string $returnTo, string $reason, ?string $hint = null): RedirectResponse
    {
        $session->forget(self::SESSION_STATE);
        $session->forget(self::SESSION_RETURN_TO);
        $session->forget(self::SESSION_LINK);
        $session->forget(self::SESSION_LINK_USER);

        $separator = str_contains($returnTo, '?') ? '&' : '?';
        $query = 'blessing_oauth=blessing&'.self::ERROR_PARAM.'='.urlencode($reason);

        if (is_string($hint) && $hint !== '') {
            $query .= '&'.self::ERROR_HINT_PARAM.'='.urlencode($hint);
        }

        return $this->withoutStateCookie(
            new RedirectResponse($returnTo.$separator.$query)
        );
    }

    /**
     * Whether the request asks to link Blessing Skin to the current account.
     *
     * The target is always the logged-in user; `linkTo` is accepted as an alias
     * so URLs built for other OAuth extensions keep working.
     */
    protected function isLinkRequest(array $query): bool
    {
        foreach (['link', 'linkTo'] as $key) {
            $value = $query[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;

            if ($value === '' || $value === '0') {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Redirect back to the forum with a flag the frontend turns into an alert.
     * Keeps failed logins out of Flarum's generic error page.
     */
    protected function fail(Session $session, string $reason = 'unknown', ?string $hint = null): RedirectResponse
    {
        // A failed link attempt returns to where the user started, not the index.
        if ($session->get(self::SESSION_LINK) === true) {
            return $this->linkFailure($session, $this->consumeReturnTo($session), $reason, $hint);
        }

        $session->forget(self::SESSION_STATE);
        $session->forget(self::SESSION_RETURN_TO);

        $base = rtrim($this->url->to('forum')->base(), '/');

        $query = self::ERROR_PARAM.'='.urlencode($reason);

        if (is_string($hint) && $hint !== '') {
            $query .= '&'.self::ERROR_HINT_PARAM.'='.urlencode($hint);
        }

        return $this->withoutStateCookie(
            new RedirectResponse($base.'/?'.$query)
        );
    }

    /**
     * Build a short, safe description of an exception for the alert.
     *
     * Only OAuth provider errors are shown verbatim; other exceptions are
     * reduced to their class name so internal details never reach the browser.
     */
    protected function errorHint(Throwable $e): string
    {
        if ($e instanceof IdentityProviderException) {
            $message = strip_tags($e->getMessage());
        } elseif ($e instanceof RequestException) {
            // Transport errors (DNS, TLS, timeout) carry a cURL message that
            // explains exactly why Flarum could not reach Blessing Skin.
            $message = $e->getHandlerContext()['error']
                ?? ($e->getResponse() ? 'HTTP '.$e->getResponse()->getStatusCode() : 'request failed');
        } else {
            $message = class_basename($e);
        }

        $message = trim(preg_replace('/\s+/', ' ', (string) $message) ?? '');

        return mb_substr($message, 0, 160);
    }

    /**
     * Add the OAuth2 state cookie to a response.
     */
    protected function withStateCookie(ResponseInterface $response, string $state): ResponseInterface
    {
        return FigResponseCookies::set($response, SetCookie::create(self::STATE_COOKIE, $state)
            ->withPath($this->stateCookiePath())
            ->withHttpOnly(true)
            ->withSameSite(SameSite::lax())
            ->withSecure($this->isSecureForum())
            ->withMaxAge(self::STATE_COOKIE_TTL));
    }

    /**
     * Expire the OAuth2 state cookie.
     */
    protected function withoutStateCookie(ResponseInterface $response): ResponseInterface
    {
        return FigResponseCookies::set($response, SetCookie::create(self::STATE_COOKIE)
            ->withPath($this->stateCookiePath())
            ->withExpires(1)
            ->withMaxAge(-1));
    }

    /**
     * Scope the state cookie to the forum's base path.
     */
    protected function stateCookiePath(): string
    {
        $path = parse_url($this->url->to('forum')->base(), PHP_URL_PATH) ?: '/';

        return rtrim($path, '/') ?: '/';
    }

    /**
     * Whether the forum is served over HTTPS, based on the configured URL.
     */
    protected function isSecureForum(): bool
    {
        return str_starts_with(strtolower($this->url->to('forum')->base()), 'https://');
    }

    /**
     * Read the path the user should return to after logging in.
     *
     * Only same-origin paths are accepted, to prevent open redirects. Anything
     * else falls back to the forum index.
     */
    protected function getReturnTo(ServerRequestInterface $request): string
    {
        return self::normalizeReturnPath(Arr::get($request->getQueryParams(), 'returnTo'));
    }

    /**
     * Read and clear the stored return path.
     */
    protected function consumeReturnTo(Session $session): string
    {
        $returnTo = $session->get(self::SESSION_RETURN_TO);
        $session->forget(self::SESSION_RETURN_TO);

        return self::normalizeReturnPath($returnTo);
    }

    /**
     * Turn a user supplied value into a path that is safe to redirect to.
     *
     * Fragments are removed because Flarum appends its query parameters to the
     * end of the path, where they would otherwise end up inside the fragment.
     */
    protected static function normalizeReturnPath(mixed $returnTo): string
    {
        if (! is_string($returnTo)) {
            return '/';
        }

        $returnTo = explode('#', $returnTo, 2)[0];

        return self::isSafeReturnPath($returnTo) ? $returnTo : '/';
    }

    /**
     * Mark the return path as belonging to this extension's OAuth flow.
     */
    protected function withSourceMarker(string $returnTo): string
    {
        $separator = str_contains($returnTo, '?') ? '&' : '?';

        return $returnTo.$separator.self::SOURCE_PARAM.'='.self::SOURCE_VALUE;
    }

    /**
     * Whether a value is a path on this forum that is safe to redirect to.
     *
     * Rejects anything that looks like an absolute URL (a scheme or `//host`).
     * Browsers read `\` as `/` and strip tab/CR/LF characters, so those are
     * rejected as well.
     */
    protected static function isSafeReturnPath(string $returnTo): bool
    {
        return str_starts_with($returnTo, '/')
            && ! str_starts_with($returnTo, '//')
            && ! str_contains($returnTo, '://')
            && ! str_contains($returnTo, '\\')
            && ! preg_match('/[\x00-\x1F\x7F]/', $returnTo);
    }
}
