<?php

use Jasny\DB\Entity\Identifiable;
use Jasny\ValidationResult;
use kornrunner\Keccak;
use LTO\Account;

/**
 * EventChain entity
 */
class EventChain extends MongoDocument
{
    const ADDRESS_VERSION = 0x40;
    
    /**
     * Unique identifier
     * @var string
     */
    public $id;
    
    /**
     * List of event
     * @var Event[]|Jasny\DB\EntitySet
     * @snapshot
     */
    public $events = [];
    
    /**
     * Projected identities
     * @var Identity[]|IdentitySet
     * @snapshot
     */
    public $identities = [];

    /**
     * Projected comments
     * @var Comment[]|Jasny\DB\EntitySet
     * @snapshot
     */
    public $comments = [];
    
    /**
     * Resources that are part of this chain
     * @var array
     */
    public $resources = [];
    
    
    /**
     * Get the initial hash which is based on the event chain id.
     * 
     * @return string
     */
    public function getInitialHash(): string
    {
        $rawId = base58_decode($this->id);
        
        return base58_encode(hash('sha256', $rawId, true));
    }
    
    /**
     * Get the latest hash.
     * Expecting a new event to use this as previous property.
     * 
     * @return string
     */
    public function getLatestHash(): string
    {
        return count($this->events) > 0 ? $this->getLastEvent()->hash : $this->getInitialHash();
    }
    
    /**
     * Get the first event of the chain.
     * 
     * @return Event
     * @throws UnderflowException
     */
    public function getFirstEvent(): Event
    {
        if (count($this->events) === 0) {
            throw new UnderflowException("chain has no events");
        }
        
        return $this->events[0];
    }
    
    /**
     * Get the last event of the chain.
     * 
     * @return Event
     * @throws UnderflowException
     */
    public function getLastEvent(): Event
    {
        if (count($this->events) === 0) {
            throw new UnderflowException("chain has no events");
        }
        
        return $this->events[count($this->events) - 1];
    }
    
    
    /**
     * Get the nodes of the identities
     * 
     * @return string[]
     */
    public function getNodes(): array
    {
        return $this->identities ? $this->identities->node : [];
    }
    
    /**
     * Get the nodes of the identities matching system sign key
     * 
     * @param $signKey
     * @return string[]
     */
    public function getNodesForSystem(string $signKey): array
    {
        $nodes = [];
        
        foreach($this->identities as $identity) {
            if (isset($identity->signkeys['system']) && $identity->signkeys['system'] == $signKey) {
                $nodes[] = $identity->node;
            }
        }
        
        return array_unique($nodes);
    }

    /**
     * Get the nodes of the identities matching user sign key
     * 
     * @param string $signKey
     * @return string[]
     */
    public function getNodesForUser(string $signKey): array
    {
        $nodes = [];
        
        foreach($this->identities as $identity) {
            if (isset($identity->signkeys['user']) && $identity->signkeys['user'] == $signKey) {
                $nodes[] = $identity->node;
            }
        }
        
        return array_unique($nodes);
    }

    /**
     * Check if the gives node corresponds with the sign key.
     *
     * @param string $signKey
     * @param string $node
     * @return bool
     */
    public function hasNodesForUserAndSystem($signKey, $node): bool
    {
        $nodes = array_merge($this->getNodesForUser($signKey), $this->getNodesForSystem($signKey));

        return in_array($node, $nodes);
    }

    /**
     * Check if the chain has identity which belongs to a given node sign key
     *
     * @param string $userSignKey
     * @param string $nodeSignKey
     * @return bool
     */
    public function hasSystemKeyForIdentity(string $userSignKey, string $nodeSignKey): bool
    {
        foreach($this->identities as $identity) {
            if (isset($identity->signkeys['user']) && $identity->signkeys['user'] == $userSignKey &&
                isset($identity->signkeys['system']) && $identity->signkeys['system'] == $nodeSignKey) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Check if the event is signed by the account
     *
     * @param Event   $event
     * @param Account $account
     * @return bool
     */
    public function isEventSignedByAccount(Event $event, Account $account): bool
    {
        $accountKey = $account->getPublicSignKey();
        
        if ($event->signkey === $accountKey) {
            return true;
        }

        if ($this->hasSystemKeyForIdentity($event->signkey, $accountKey)) {
            return true;
        }

        return false;
    }
    
    /**
     * Check if the event is sent from the node of one of the identities
     *
     * @param Event       $event
     * @param string|null $node
     * @return bool
     */
    public function isEventSignedByIdentityNode(Event $event, ?string $node = null): bool
    {
        $node = isset($node) ? $node : $event->origin;

        return isset($node) && $this->hasNodesForUserAndSystem($event->signkey, $node);
    }
    
    
    /**
     * Check if this chain has the genisis event or is empty.
     * 
     * @return bool
     */
    public function isPartial(): bool
    {
        return count($this->events) > 0 && $this->getFirstEvent()->previous !== $this->getInitialHash();
    }
    
    /**
     * Check if the chain has events.
     * 
     * @return bool
     */
    public function isEmpty(): bool
    {
        return count($this->events) === 0;
    }
    
    
    /**
     * Check if id is valid
     * 
     * @return bool
     */
    public function isValidId(): bool
    {
        $decodedId = base58_decode($this->id);
        
        if (strlen($decodedId) !== 45) {
            return false;
        }

        $firstEvent = $this->getFirstEvent();
        
        $signkey = base58_decode($firstEvent->signkey);
        $signkeyHashed = substr(Keccak::hash(sodium_crypto_generichash($signkey, null, 32), 256), 0, 40);
        
        $vars = unpack('Cversion/H40nonce/H40keyhash/H8checksum', $decodedId);
        
        return
            $vars['version'] === static::ADDRESS_VERSION &&
            $vars['keyhash'] === substr($signkeyHashed, 0, 40) &&
            $vars['checksum'] === substr(bin2hex($decodedId), -8);
    }
    
    /**
     * Validate the chain
     * 
     * @return ValidationResult
     */
    public function validate(): ValidationResult
    {
        $validation = parent::validate();
        
        if (count($this->events) === 0) {
            $validation->addError('no events');
        } else if ($this->getFirstEvent()->previous === $this->getInitialHash() && !$this->isValidId()) {
            $validation->addError('invalid id');
        }
        
        $validation->add($this->validateIntegrity());
        
        return $validation;
    }
    
    /**
     * Validate chain integrity
     * 
     * @return ValidationResult
     */
    protected function validateIntegrity(): ValidationResult
    {
        $validation = new ValidationResult();
        $previous = null;
        
        foreach ($this->events as $event) {
            if (isset($previous) && $event->previous !== $previous) {
                $validation->addError(
                    "broken chain; previous of '%s' is '%s', expected '%s'",
                    $event->hash, $event->previous, $previous
                );
            }
            
            $previous = $event->hash;
        }
        
        return $validation;
    }
    
    /**
     * Return an event chain without any events
     * 
     * @return static
     */
    public function withoutEvents(): self
    {
        $emptyChain = new static();
        $emptyChain->id = $this->id;
        
        return $emptyChain;
    }

    /**
     * Return an event chain with the given events
     * 
     * @param Event[] $events
     * @return static
     */
    public function withEvents(array $events): self
    {
        $chain = clone $this;
        $chain->events = $events;
        
        return $chain;
    }
    
    
    /**
     * Get all events that follow the specified event.
     * 
     * @param string $hash
     * @return Event[]
     * @throws OutOfBoundsException if event can't be found
     */
    public function getEventsAfter($hash): array
    {
        if ($this->getInitialHash() === $hash) {
            return $this->events->getArrayCopy();
        }
        
        $events = null;
        
        foreach ($this->events as $event) {
            if (isset($events)) {
                $events[] = $event;
            } elseif ($event->hash === $hash) {
                $events = [];
            }
        }
        
        if (!isset($events)) {
            throw new OutOfBoundsException("Event '$hash' not found");
        }
        
        return $events;
    }
    
    /**
     * Get a partial chain consisting of all events that follow the specified event.
     * 
     * @param string $hash
     * @return EventChain
     * @throws OutOfBoundsException if event can't be found
     */
    public function getPartialAfter(string $hash): EventChain
    {
        $events = $this->getEventsAfter($hash) ?? [];
        return $this->withEvents($events);
    }
    
    
    /**
     * Register that a resource is used in this chain
     * 
     * @param Resource $resource
     */
    public function registerResource(Resource $resource): void
    {
        if ($resource instanceof Identity) {
            $this->identities->set($resource);
            return;
        }

        if ($resource instanceof Comment) {
            $this->comments->add($resource);
            return;
        }
        
        if ($resource instanceof Identifiable) {
            $id = jasny\str_before($resource->getId(), '?'); // No (version) arguments

            if (!in_array($id, $this->resources)) {
                $this->resources[] = $id;
            }
        }
    }
}
