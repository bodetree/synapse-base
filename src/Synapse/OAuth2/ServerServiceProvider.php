<?php

namespace Synapse\OAuth2;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\RequestMatcher;

use Synapse\OAuth2\Storage\ZendDb as OAuth2ZendDb;
use Synapse\OAuth2\ResponseType\AccessToken;

use OAuth2\HttpFoundationBridge\Response as BridgeResponse;
use OAuth2\Server as OAuth2Server;
use OAuth2\GrantType\AuthorizationCode;
use OAuth2\GrantType\RefreshToken;
use OAuth2\GrantType\UserCredentials;
use OAuth2\ResponseType\AuthorizationCode as AuthorizationCodeResponse;

class ServerServiceProvider implements ServiceProviderInterface
{
    /**
     * Register services
     *
     * @param  Application $app
     */
    public function setup(Application $app)
    {
        $app['oauth.storage'] = $app->share(function ($app) {
            // Create the storage object
            $storage = new OAuth2ZendDb($app['db']);
            $storage->setUserMapper($app['user.mapper']);

            return $storage;
        });

        $app['oauth_server'] = $app->share(function ($app) {
            $storage = $app['oauth.storage'];

            $grantTypes = [
                'authorization_code' => new AuthorizationCode($storage),
                'refresh_token'      => new RefreshToken($storage),
                'user_credentials'   => new UserCredentials($storage),
            ];

            $accessTokenResponseType = new AccessToken($storage, $storage);
            $authCodeResponseType = new AuthorizationCodeResponse($storage);

            return new OAuth2Server(
                $storage,
                [
                    'enforce_state'  => false,
                    'allow_implicit' => true,
                ],
                $grantTypes,
                [
                    'token' => $accessTokenResponseType,
                    'code'  => $authCodeResponseType,
                ]
            );
        });

        $app['oauth-access-token.mapper'] = $app->share(function ($app) {
            return new AccessTokenMapper($app['db'], new AccessTokenEntity);
        });

        $app['oauth-refresh-token.mapper'] = $app->share(function ($app) {
            return new RefreshTokenMapper($app['db'], new RefreshTokenEntity);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function register(Application $app)
    {
        $this->setup($app);
    }

    /**
     * {@inheritDoc}
     */
    public function boot(Application $app)
    {
        // Noop
    }
}
