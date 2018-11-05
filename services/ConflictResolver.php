<?php declare(strict_types=1);

use Improved\IteratorPipeline\Pipeline;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Resolve a conflict when a fork is detected.
 *
 * Get the anchor transaction of the two events where the chains fork. If our event was anchored earlier, do nothing.
 * If the other event was anchored first, our state is no good. We need to rebase our fork onto the other chain. Then
 * we delete and rebuild all projected data (like the process).
 */
class ConflictResolver
{
    /**
     * @var AnchorClient
     */
    protected $anchor;

    /**
     * @var EventChainRebase
     */
    protected $rebaser;

    /**
     * @var ResourceStorage
     */
    protected $resourceStorage;


    /**
     * Class constructor.
     *
     * @param AnchorClient     $anchor
     * @parma EventChainRebase $rebaser
     * @param ResourceStorage  $resourceStorage
     */
    public function __construct(AnchorClient $anchor, EventChainRebase $rebaser, ResourceStorage $resourceStorage)
    {
        $this->anchor = $anchor;
        $this->rebaser = $rebaser;
        $this->resourceStorage = $resourceStorage;
    }

    /**
     * Invoke the resolver
     *
     * @param EventChain $ourChain
     * @param EventChain $theirChain
     * @return EventChain
     * @throws UnresolvableConflictException
     */
    public function handleFork(EventChain $ourChain, EventChain $theirChain): EventChain
    {
        $ourEvent = $ourChain->getFirstEvent();
        $theirEvent = $theirChain->getFirstEvent();

        if ($this->getEarliestEvent($ourEvent, $theirEvent) === $ourEvent) {
            return $ourChain->withEvents([]);
        }

        $fullChain = $this->rebaser->rebase($theirChain, $ourChain);

        $this->resourceStorage->deleteProjected($fullChain->resources);

        return $fullChain;
    }

    /**
     * Get the event that was anchored first.
     * First compare block heights and both are in the same block, the transaction position within a block.
     *
     * @param Event ...$events
     * @return Event
     * @throws UnresolvableConflictException
     */
    protected function getEarliestEvent(Event ...$events): Event
    {
        try {
            return Pipeline::with($events)
                ->flip()
                ->map(function ($_, Event $event) {
                    return $event->getHash();
                })
                ->then([$this->anchor, 'fetchMultiple']) // Loops through all events and returns a new iterator.
                ->sort(function (stdClass $info1, stdClass $info2) {
                    return (int)version_compare(
                        "{$info1->block->height}.{$info1->transaction->position}",
                        "{$info2->block->height}.{$info2->transaction->position}"
                    );
                })
                ->flip()
                ->first(true);
        } catch (RangeException $e) {
            throw $this->notAnchoredException($events);
        } catch (Exception $exception) {
            throw new UnresolvableConflictException("Failed to fetch from anchoring service", 0, $exception);
        }
    }

    /**
     * Create an unresolvable conflict exception when both chains are not anchored.
     *
     * @param Event[] $events
     * @return UnresolvableConflictException
     */
    protected function notAnchoredException(array $events)
    {
        $hashes = Pipeline::with($events)
            ->map(function (Event $event) { return $event->getHash(); })
            ->concat("', '");

        return new UnresolvableConflictException(
            sprintf("Events '%s' are not anchored yet", $hashes),
            UnresolvableConflictException::NOT_ANCHORED
        );
    }
}
