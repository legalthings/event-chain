<?php declare(strict_types=1);

namespace AddEventStep;

use Event;
use EventChain;
use Identity;
use Improved\IteratorPipeline\Pipeline;
use Jasny\ValidationResult;
use ResourceFactory;
use ResourceStorage;
use Jasny\DB\Entity\Identifiable;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * Extract and store the resource from the event. A resource might be a workflow action, a comment, an identity or
 * some asset.
 *
 * These assets may be stored at an external service. Comments and identities are embedded in the event chain.
 */
class StoreResource
{
    /**
     * @var EventChain
     */
    protected $chain;

    /**
     * @var ResourceFactory
     */
    protected $resourceFactory;

    /**
     * @var ResourceStorage
     */
    protected $resourceStorage;


    /**
     * StoreResource constructor.
     *
     * @param EventChain      $chain
     * @param ResourceFactory $factory
     * @param ResourceStorage $storage
     */
    public function __construct(EventChain $chain, ResourceFactory $factory, ResourceStorage $storage)
    {
        $this->chain = $chain;
        $this->resourceFactory = $factory;
        $this->resourceStorage = $storage;
    }

    /**
     * @param Pipeline         $pipeline
     * @param ValidationResult $validation
     * @return Pipeline
     */
    public function __invoke(Pipeline $pipeline, ValidationResult $validation): Pipeline
    {
        return $pipeline->apply(function(Event $event) use ($validation): void {
            if ($validation->failed()) {
                return;
            }

            $resource = $this->resourceFactory->extractFrom($event);
            $auth = $this->applyPrivilegeToResource($resource, $event);

            $validation->add($auth, "event '$event->hash': ");
            $validation->add($resource->validate(), "event '$event->hash': ");

            if ($validation->failed()) {
                return;
            }

            $stored = $this->storeResource($resource);
            $validation->add($stored, "event '$event->hash': ");
        });
    }


    /**
     * Store a new event and add it to the chain
     *
     * @param ResourceInterface $resource
     * @return ValidationResult
     */
    protected function storeResource(ResourceInterface $resource): ValidationResult
    {
        try {
            $this->resourceStorage->store($resource);
        } catch (RequestException $e) {
            $id = 'ResourceInterface' . ($resource instanceof Identifiable ? ' ' . $resource->getId() : '');
            $reason = $e instanceof ClientException ? $e->getMessage() : 'Server error';

            trigger_error($e->getMessage(), E_USER_WARNING);

            return ValidationResult::error("Failed to store %s: %s", $id, $reason);
        }

        $this->chain->registerResource($resource);

        return ValidationResult::success();
    }

    /**
     * Apply privilege to a resource.
     * Returns false if identity has no privileges to resource.
     *
     * @param ResourceInterface $resource
     * @param Event             $event
     * @return ValidationResult
     */
    public function applyPrivilegeToResource(ResourceInterface $resource, Event $event): ValidationResult
    {
        if ($this->chain->isEmpty()) {
            return $resource instanceof Identity ?
                ValidationResult::success() :
                ValidationResult::error("initial resource must be an identity");
        }

        $identities = $this->chain->identities->filterOnSignkey($event->signkey);
        $privileges = $identities->getPrivileges($resource);

        if ($privileges === []) {
            return ValidationResult::error("no privileges for event");
        }

        $resource->applyPrivilege($this->consolidatedPrivilege($resource, $privileges));
        $resource->setIdentity($identities[0]);

        return ValidationResult::success();
    }

    /**
     * Create a consolidated privilege from an array of privileges
     *
     * @param ResourceInterface $resource
     * @param Privilege[]       $privileges
     * @return Privilege
     */
    protected function consolidatedPrivilege(ResourceInterface $resource, array $privileges): Privilege
    {
        return Privilege::create($resource)->consolidate($privileges);
    }
}
