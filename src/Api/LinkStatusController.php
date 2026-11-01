<?php

namespace Blessing\Flarum\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\LoginProvider;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reports whether the current user has a Blessing Skin account linked.
 */
class LinkStatusController implements RequestHandlerInterface
{
    public const PROVIDER = 'blessing';

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        /** @var LoginProvider|null $provider */
        $provider = $actor->loginProviders()
            ->where('provider', self::PROVIDER)
            ->first();

        return new JsonResponse([
            'linked' => $provider !== null,
            'identifier' => $provider === null ? null : self::displayIdentifier((string) $provider->identifier),
            'createdAt' => $provider?->created_at?->toIso8601String(),
            'lastLoginAt' => $provider?->last_login_at?->toIso8601String(),
        ]);
    }

    /**
     * Blessing Skin user IDs are short numbers; long OIDC subjects are masked.
     */
    private static function displayIdentifier(string $identifier): string
    {
        if (strlen($identifier) <= 12) {
            return $identifier;
        }

        return substr($identifier, 0, 4).'****'.substr($identifier, -4);
    }
}
