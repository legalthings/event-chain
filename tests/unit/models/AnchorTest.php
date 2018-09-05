<?php

/**
 * @covers Anchor
 */
class AnchorTest extends \Codeception\Test\Unit
{
    use Jasny\TestHelper;
    
    public $config = [
        'url' => 'http://anchor.example.com'
    ];

    
    public function testHash()
    {
        $hash = 'foo';
        $encoding = 'base58';
        
        $mock = new GuzzleHttp\Handler\MockHandler([
            new GuzzleHttp\Psr7\Response(200)
        ]);
        $handler = GuzzleHttp\HandlerStack::create($mock);
        $container = [];
        $history = GuzzleHttp\Middleware::history($container);
        $handler->push($history);
        $httpClient = new GuzzleHttp\Client(['handler' => $handler]);
        
        $anchor = new Anchor($this->config, $httpClient);
        $anchor->hash($hash, $encoding);
        
        $this->assertCount(1, $container);
        
        $request = $container[0]['request'];
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals("{$this->config['url']}/hash", (string)$request->getUri());
        $this->assertEquals(['Content-Type' => ['application/json']],
            jasny\array_only($request->getHeaders(), ['Content-Type']));
        $this->assertJsonStringEqualsJsonString(json_encode(compact('hash', 'encoding')), (string)$request->getBody());
    }
    
    /**
     * @expectedException GuzzleHttp\Exception\ServerException
     */
    public function testHashError()
    {
        $hash = 'foo';
        $encoding = 'base58';
        
        $mock = new GuzzleHttp\Handler\MockHandler([
            new GuzzleHttp\Psr7\Response(500)
        ]);
        $handler = GuzzleHttp\HandlerStack::create($mock);
        $container = [];
        $history = GuzzleHttp\Middleware::history($container);
        $handler->push($history);
        $httpClient = new GuzzleHttp\Client(['handler' => $handler]);
        
        $anchor = new Anchor($this->config, $httpClient);
        $anchor->hash($hash, $encoding);
    }
}