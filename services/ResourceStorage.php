<?php declare(strict_types=1);

use Improved as i;
use const Improved\FUNCTION_ARGUMENT_PLACEHOLDER as __;
use Improved\IteratorPipeline\Pipeline;
use GuzzleHttp\ClientInterface as HttpClient;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Promise\PromiseInterface as GuzzlePromise;
use Jasny\HttpDigest\HttpDigest;
use LTO\Account;

/**
 * Class to store an external resource.
 */
class ResourceStorage
{
    /**
     * @var array
     */
    protected $endpoints;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var HttpErrorWarning
     */
    protected $errorWarning;

    /**
     * @var Account
     **/
    protected $node;

    /**
     * @var Digest
     **/
    protected $digest;

    /**
     * Class constructor
     *
     * @param array            $endpoints
     * @param HttpClient       $httpClient
     * @param HttpErrorWarning $errorWarning
     * @param Account          $node
     * @param HttpDigest       $digest
     */
    public function __construct(
        array $endpoints, 
        HttpClient $httpClient, 
        HttpErrorWarning $errorWarning, 
        Account $node, 
        HttpDigest $digest
    )
    {
        $this->endpoints = $endpoints;
        $this->httpClient = $httpClient;
        $this->errorWarning = $errorWarning;
        $this->node = $node;
        $this->digest = $digest;
    }

    /**
     * Store a resource
     *
     * @param ResourceInterface $resource
     * @param EventChain|null $chain 
     */
    public function store(ResourceInterface $resource, ?EventChain $chain = null): void
    {
        $promises = Pipeline::with($this->endpoints)
            ->filter(static function($endpoint) use ($resource) {
                return $endpoint->schema === null || $resource->getSchema() === $endpoint->schema;
            })
            ->filter(static function($endpoint) {
                return !isset($endpoint->grouped);
            })
            ->map(function($endpoint) use ($resource, $chain) {
                $resource = $this->injectEventChain($resource, $endpoint, $chain);

                return $this->sendRequest($resource, $endpoint);
            })
            ->toArray();

        $results = Promise\unwrap($promises);
    }

    /**
     * Send request
     *
     * @param ResourceInterface $resource
     * @param stdClass          $endpoint 
     * @return GuzzleHttp\Promise\PromiseInterface
     */
    protected function sendRequest(ResourceInterface $resource, stdClass $endpoint): GuzzlePromise
    {
        $options = [
            'json' => $resource,
            'http_errors' => true, 
            'signature_key_id' => base58_encode($this->node->sign->publickey),
            'headers' => [
                'X-Original-Key-Id' => $resource->original_key,
                'Digest' => $this->digest->create(json_encode($resource)),
                'Content-Type' => 'application/json',
                'date' => date(DATE_RFC1123)
            ]
        ];

        return $this->httpClient->requestAsync('POST', $endpoint->url, $options);
    }

    /**
     * Message resources that the event chain has been processed.
     *
     * @param iterable $resources
     * @param EventChain|null $chain
     */
    public function storeGrouped(iterable $resources, ?EventChain $chain = null): void
    {
        $promises = [];

        foreach ($this->endpoints as $endpoint) {
            if (!isset($endpoint->grouped)) {
                continue;
            }

            $endpointPromises = Pipeline::with($resources)
                ->filter(static function(ResourceInterface $resource) use ($endpoint) {
                    return $endpoint->schema === null || $resource->getSchema() === $endpoint->schema;
                })
                ->group(static function(ResourceInterface $resource) use ($endpoint) {
                    $field = $endpoint->grouped;
                    $value = $resource->{$field} ?? null;

                    return is_scalar($value) ? $value : $value->id ?? null;
                })
                ->cleanup()
                ->keys()
                ->map(function($value) use ($endpoint, $chain) {
                    $field = $endpoint->grouped;
                    $data = (object)[$field => $value];
                    $data = $this->injectEventChain($data, $endpoint, $chain);

                    $options = ['json' => $data, 'http_errors' => true];

                    return $this->httpClient->requestAsync('POST', $endpoint->url, $options);
                })
                ->toArray();

            $promises = array_merge($promises, $endpointPromises);
        }

        Promise\unwrap($promises);
    }

    /**
     * Delete all resources.
     *
     * @param iterable $resources
     * @return void
     */
    public function deleteResources(iterable $resources): void
    {
        //temp
        throw new Exception('deleteResources method is disabled');

        $promises = Pipeline::with($resources)
            ->filter(function (ResourceInterface $resource) {
                return $resource instanceof ExternalResource && $this->mapping->hasDoneUrl($resource->getId());
            })
            ->map(function (ExternalResource $resource) {
                return $this->mapping->getDoneUrl($resource->getId());
            })
            ->map(function (string $url) {
                return $this->httpClient->requestAsync('DELETE', $url, ['http_errors' => false])
                    ->then(i\function_partial($this->errorWarning, __, $url));
            })
            ->toArray();

        Promise\unwrap($promises);
    }

    /**
     * Inject event chain into query data
     *
     * @param object $resource
     * @param object $endpoint 
     * @param EventChain|null $chain 
     * @return ResourceInterface
     */
    protected function injectEventChain(object $data, object $endpoint, ?EventChain $chain)
    {
        if (!isset($chain) || !isset($endpoint->inject_chain) || !$endpoint->inject_chain) {
            return $data;
        }

        $data = clone $data;

        if ($endpoint->inject_chain === 'empty') {
            $latestHash = $chain->getLatestHash();
            $chain = $chain->withoutEvents();
            $chain->latest_hash = $latestHash;
        }

        $data->chain = $chain;

        return $data;
    }
}
