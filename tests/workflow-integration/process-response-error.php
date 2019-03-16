<?php declare(strict_types=1);

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/start-process.php';

/**
 * Send a response to process, up to successfull ending
 */

$bodies = [
    [ // process goes from state ':initial' to 'second'
        '$schema' => 'https://specs.livecontracts.io/v0.2.0/response/schema.json#',
        'action' => 'step1',
        'key' => 'ok',
        'actor' => 'system',
        'process' => 'j2134901218ja908323434',
        'data' => ['foo' => 'bar']
    ],
    [ // from state 'second' to ':failed'
        '$schema' => 'https://specs.livecontracts.io/v0.2.0/response/schema.json#',
        'action' => 'step2',
        'key' => 'error',
        'actor' => 'system',
        'process' => 'j2134901218ja908323434',
        'data' => ['foo' => 'bar']
    ]
];

echo "Stage 2: Step through the process to failed state...\n";

$tester = new AllWorkflowTester();

$chain = $tester->getExistingChain($globalChainId);
$chain = $tester->addEvents($chain, 2, $bodies);

$data = $tester->castChainToData($chain);
$response = $tester->sendPost('http://localhost:4000/event-chains', $data);
$formated = $tester->formatResponse($response);

echo "Request result: {$formated['code']} - {$formated['reason']}\n\n";
