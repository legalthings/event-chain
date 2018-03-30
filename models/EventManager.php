<?php

use Jasny\ValidationResult;

/**
 * Handle new events
 */
class EventManager
{
    /**
     * @var string
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
     * Class constructor
     * 
     * @param EventChain $chain
     */
    public function __construct(EventChain $chain, ResourceFactory $resourceFactory, ResourceStorage $resourceStorage)
    {
        if ($chain->isPartial()) {
            throw new UnexpectedValueException("Event chain doesn't contain the genesis event");
        }
        
        $this->chain = $chain;
        $this->resourceFactory = $resourceFactory;
        $this->resourceStorage = $resourceStorage;
    }
    
    /**
     * Add new events
     * 
     * @param EventChain $newEvents
     * @return ValidationResult
     */
    public function add(EventChain $newEvents)
    {
        if ($this->chain->id !== $newEvents->id) {
            throw new UnexpectedValueException("Can't add events of a different chain");
        }
        
        $validation = $newEvents->validate();
        
        if (!$validation->isSuccess()) {
            return $validation;
        }
        
        $previous = $newEvents->getFirstEvent()->previous;
        
        try {
            $following = $this->chain->getEventsAfter($previous);
        } catch (OutOfBoundsException $e) {
            return ValidationResult::error("events don't fit on chain, '%s' not found", $previous);
        }
        
        $next = reset($following);
        
        foreach ($newEvents->events as $event) {
            if ($next === false) {
                $handled = $this->handleNewEvent($event);
                $validation->add($handled, "event '$event->hash';");
            } elseif ($event->hash !== $next->hash) {
                $validation->addError("fork detected; conflict on '%s' and '%s'", $event->hash, $next->hash);
            }
            
            $next = next($following);
            
            if ($validation->failed()) {
                break;
            }
            
            $this->chain->save();
        }
        
        return $validation;
    }
    
    /**
     * Add an event to the event chain.
     * 
     * @param Event $event
     * @return ValidationResult
     */
    public function handleNewEvent(Event $event)
    {
        $validation = $event->validate();
        
        if ($event->previous !== $this->chain->getLatestHash()) {
            $validation->addError("event '%s' doesn't fit on chain", $event->hash);
        }
        
        if ($validation->failed()) {
            return $validation;
        }
        
        $resource = $this->resourceFactory->extractFrom($event);
        
        $auth = $this->applyPrivilegeToResource($resource, $event);
        $validation->add($auth);
        
        if ($validation->failed()) {
            return $validation;
        }
        
        $this->resourceStorage->store($resource);
        $this->chain->registerResource($resource);
        
        $this->chain->events->add($event);
        
        return $validation;
    }
    

    /**
     * Apply privilege to a resource.
     * Returns false if identity has no privileges to resource.
     * 
     * @param Resource $resource
     * @param Event    $event
     * @return boolean
     */
    public function applyPrivilegeToResource(Resource $resource, Event $event)
    {
        if ($this->chain->isEmpty()) {
            return $resource instanceof Identity ?
                ValidationResult::success() :
                ValidationResult::error("initial resource must be an identity");
        }
        
        $identities = $this->chain->identities->filterOnSignkey($event->signkey);
        $privileges = $identities->getPrivileges($resource);

        if (empty($privileges)) {
            return ValidationResult::error("no privileges for event");
        }

        $resource->applyPrivilege($this->consolidatedPrivilege($resource, $privileges));
        $resource->setIdentity($identities[0]);
        
        return ValidationResult::success();
    }
    
    /**
     * Create a consolidated privilege from an array of privileges
     * 
     * @param Resource    $resource
     * @param Privilege[] $privileges
     * @return Privilege
     */
    protected function consolidatedPrivilege(Resource $resource, array $privileges)
    {
        return Privilege::create($resource)->consolidate($privileges);
    }
}