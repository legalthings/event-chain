<?php

/**
 * Class to interact with event dispatcher service.
 */
class Dispatcher
{
    /**
     * @var object
     */
    protected $config;

    /**
     * @var GuzzleHttp\ClientInterface
     */
    protected $httpClient;
    
    
    /**
     * Class constructor
     * 
     * @param object|array      $config
     * @param GuzzleHttp\Client $httpClient
     */
    public function __construct($config, GuzzleHttp\ClientInterface $httpClient)
    {
        $this->config = (object)$config;
        $this->httpClient = $httpClient;
    }
    
    
    /**
     * Get info about the dispatcher
     * 
     * @return stdClass
     */
    public function info(): stdClass
    {
        $endpoint = $this->config->url;
        $url = "{$endpoint}/";
        
        $options = [
            'http_errors' => true
        ];

        $response = $this->httpClient->get($url, $options);
        return json_decode($response->getBody());
    }

    /**
     * Get the node url that the dispatcher is running on
     * 
     * @return string
     */
    public function getNode(): string
    {
        $response = $this->info();
        return $response->node;
    }
    
    /**
     * Add the event to the queue of the node
     * 
     * @param EventChain $chain
     * @param string[]   $to     If specified will send the event to the nodes in this array
     */
    public function queue(EventChain $chain, $to = null): void
    {
        $endpoint = $this->config->url;
        $url = "{$endpoint}/queue";
        
        $options = [
            'json' => $chain,
            'http_errors' => true,
            'query' => []
        ];
        
        if (isset($to) && !empty($to)) {
            $options['query']['to'] = $to;
        }

        $this->httpClient->post($url, $options);
    }
}
