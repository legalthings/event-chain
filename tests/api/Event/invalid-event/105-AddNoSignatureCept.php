<?php

/**
 * Try adding event with no signature
 */

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$I = new ApiTester($scenario);
$I->wantTo('Try adding event with no signature');

$I->amSignatureAuthenticated("LtI60OqaM/gZbaeN8tWBJqOy7yiPwxSMZDo/aQvsPFzbJiGUQZ2iyDtBkL/+GJseJnUweTabuOn8RtR4V3MOKw==");

// body of first event
$body0 = [
    '$schema' => 'https://specs.livecontracts.io/v0.2.0/identity/schema.json#',
    'id' => '0c1d7eac-18ec-496a-8713-8e6e5f098686',
    'node' => 'localhost',
    'signkeys' => [
        'default' => 'FkU1XyfrCftc4pQKXCrrDyRLSnifX1SMvmx1CYiiyB3Y', 
        'system' => 'FkU1XyfrCftc4pQKXCrrDyRLSnifX1SMvmx1CYiiyB3Y'
    ],
    'encryptkey' => 'BVv1ZuE3gKFa6krwWJQwEmrLYUESuUabNCXgYTmCoBt6',
    'timestamp' => '2019-03-30T19:10:43+0000' // is not present in encoded body, taken from event timestamp
];

$data = $I->getEntityDump('event-chains', 'only-identities');
$chainId = $data['id'];
unset($data['events'][1]['signature']);

// Save identity to workflow
$I->expectHttpRequest(function (Request $request) use ($I, $body0) {
    $json = json_encode($body0);

    $I->assertEquals('http://legalflow/identities/', (string)$request->getUri());
    $I->assertEquals('application/json', $request->getHeaderLine('Content-Type'));
    $I->assertJsonStringEqualsJsonString($json, (string)$request->getBody());
    
    return new Response(200);
});

$I->haveHttpHeader('Content-Type', 'application/json');
$I->haveHttpHeader('Digest', $I->calculateDigest($data));
$I->sendPOST('/event-chains', $data);

$I->expectTo('see error message');

$I->seeResponseCodeIs(400);
$I->seeResponseIsJson();
$I->seeResponseContains("event 'BuizdWTtk7A6Xrt71i8Fy1npwE8x5KUVP4Q82xFGFFHy': signature is required");

$I->expectTo('see that error event was added to event chain');

$I->sendGET('/event-chains/' . $chainId);
$I->seeResponseCodeIs(200);
$I->seeResponseIsJson();
$I->seeResponseContainsJson(['events' => ['hash' => $data['events'][0]['hash']]]);
$I->dontSeeResponseContainsJson(['events' => ['hash' => $data['events'][1]['hash']]]);
$I->dontSeeResponseContainsJson(['events' => ['hash' => $data['events'][2]['hash']]]);

$data['events'][1]['signature'] = null;

$I->seeValidErrorEventInResponse(
    ["event 'BuizdWTtk7A6Xrt71i8Fy1npwE8x5KUVP4Q82xFGFFHy': signature is required"], 
    [$data['events'][1], $data['events'][2]]
);
