<?php

use Jasny\ApplicationEnv;
use Psr\Container\ContainerInterface;
use LTO\AccountFactory;
use function Jasny\arrayify;

return [
    'node.account' => function(ContainerInterface $container) {
        /** @var $factory AccountFactory */
        $factory = $container->get(AccountFactory::class);

        $accountSeed = getenv('LTO_ACCOUNT_SEED_BASE58');

        if ((string)$accountSeed === '' && $container->get(ApplicationEnv::class)->is('prod')) {
            throw new RuntimeException("LTO account seed missing; set LTO_ACCOUNT_SEED_BASE58 env var");
        }

        return (string)$accountSeed !== ''
            ? $factory->seed(base58_decode($accountSeed))
            : $factory->create(arrayify(new Config('config/node.yml')));
    }
];
