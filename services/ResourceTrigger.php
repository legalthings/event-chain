<?php declare(strict_types=1);

use LTO\Account;
use Improved\IteratorPipeline\Pipeline;
use GuzzleHttp\Promise;
use GuzzleHttp\ClientInterface as HttpClient;
use GuzzleHttp\Psr7\Response as HttpResponse;

/**
 * Class to trigger on an external resource change.
 * In contrary to storing resources, triggers are executed when all events are processed.
 */
class ResourceTrigger
{
    /**
     * @var array
     */
    protected $triggers;

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
     * @param string[]         $triggers
     * @param HttpClient       $httpClient
     * @param HttpErrorWarning $errorWarning
     * @param Account          $node
     */
    public function __construct(
        array $triggers,
        HttpClient $httpClient,
        HttpErrorWarning $errorWarning,
        Account $node
    )
    {
        $this->endpoints = $triggers;
        $this->httpClient = $httpClient;
        $this->errorWarning = $errorWarning;
        $this->node = $node;
    }

    /**
     * Message resources that the event chain has been processed.
     *
     * @param iterable $resources
     * @param EventChain $chain
     * @return EventChain|null    Events created after triggering some workflow actions
     */
    public function trigger(iterable $resources, EventChain $chain): ?EventChain
    {
        $promises = [];

        if ($resources instanceof Traversable) {
            $resources = iterator_to_array($resources);
        }

        foreach ($this->endpoints as $endpoint) {
            foreach ($endpoint->resources as $groupOpts) {
                $groupPromises = Pipeline::with($resources)
                    ->filter(static function(ResourceInterface $resource) use ($groupOpts) {
                        return $groupOpts->schema === null || $resource->getSchema() === $groupOpts->schema;
                    })
                    ->group(static function(ResourceInterface $resource) use ($groupOpts) {
                        $field = $groupOpts->group->process;
                        $value = $resource->{$field} ?? null;

                        return is_scalar($value) ? $value : $value->id ?? null;
                    })
                    ->cleanup()
                    ->keys()
                    ->map(function($value) use ($endpoint, $groupOpts, $chain) {
                        return $this->sendRequest($value, $endpoint, $groupOpts, $chain);
                    })
                    ->toArray();                

                $promises = array_merge($promises, $groupPromises);
            }
        }

        $responses = Promise\unwrap($promises);

        return $this->getEventsFromResponses($responses, $chain);
    }

    /**
     * Send request
     *
     * @param string $value
     * @param stdClass $endpoint 
     * @param stdClass $groupOpts 
     * @param EventChain $chain 
     * @return GuzzleHttp\Psr7\Response
     */
    protected function sendRequest(string $value, stdClass $endpoint, stdClass $groupOpts, EventChain $chain)
    {
        $field = $groupOpts->group->process;
        $data = (object)[$field => $value];
        $data = $this->injectEventChain($data, $endpoint, $chain);
        $url = $this->expandUrl($endpoint->url, $value);

        $options = [
            'json' => $data, 
            'http_errors' => true,
            'signature_key_id' => base58_encode($this->node->sign->publickey)
        ];

        return $this->httpClient->requestAsync('POST', $url, $options);
    }

    /**
     * Get events from http queries responses
     *
     * @param array $responses
     * @param EventChain $chain 
     * @return EventChain|null
     */
    protected function getEventsFromResponses(array $responses, EventChain $chain): ?EventChain
    {
        $events = Pipeline::with($responses)
            ->filter(function(HttpResponse $response): bool {
                $contentType = $response->getHeaderLine('Content-Type');

                return strpos($contentType, 'application/json') !== false;
            })
            ->map(function(HttpResponse $response) {
                $data = (string)$response->getBody();

                return json_decode($data, true);
            })
            ->filter(function($data): bool {
                return is_array($data) && count($data) > 0;
            })
            ->map(function(array $data): Event {
                return (new Event)->setValues($data);
            })
            ->toArray();

        $newChain = count($events) > 0 ? 
            $chain->withEvents($events) : 
            null;

        return $newChain;
    }

    /**
     * Inject event chain into query data
     *
     * @param object $resource
     * @param object $endpoint
     * @param EventChain $chain
     * @return stdClass
     */
    protected function injectEventChain(object $data, object $endpoint, EventChain $chain): stdClass
    {
        if (!isset($endpoint->inject_chain) || !$endpoint->inject_chain) {
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

    /**
     * Insert parameter value into endpoint url
     *
     * @param string $url
     * @param string $parameter 
     * @return string
     */
    protected function expandUrl(string $url, string $parameter): string
    {
        return preg_replace('~/-(/|$)~', "/$parameter$1", $url);
    }
}