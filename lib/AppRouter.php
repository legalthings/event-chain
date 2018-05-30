<?php

use Psr\Container\ContainerInterface;
use Jasny\Router;
use LTO\AccountFactory;

/**
 * Application router.
 * Load all the middleware this application wants to use.
 *
 * @codeCoverageIgnore
 */
class AppRouter extends Router
{
    /**
     * Add the middleware this application uses.
     * 
     * @param ContainerInterface $container
     */
    public function withMiddleware(ContainerInterface $container)
    {
        return $this
            ->withHTTPSignatureMiddleware($container)
            ->withDetermineRouteMiddleware();
    }

    /**
     * @param ContainerInterface $container
     * @return $this
     */
    protected function withHTTPSignatureMiddleware(ContainerInterface $container)
    {
        $accountFactory = $container->get(AccountFactory::class);
        $baseRewrite = defined('BASE_REWRITE') ? BASE_REWRITE : null;
        
        return $this->add(new HTTPSignatureMiddleware($accountFactory, $baseRewrite));
    }


    /**
     * Determine the routes at forehand
     * 
     * @return $this
     */
    protected function withDetermineRouteMiddleware()
    {
    }
}
