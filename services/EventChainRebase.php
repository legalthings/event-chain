<?php declare(strict_types=1);

use LTO\Account;
use EventChainRebase\EventStitch;
use Carbon\Carbon;

/**
 * Service to rebase a fork of an event chain upon the leading chain.
 */
class EventChainRebase
{
    /**
     * @var Account
     **/
    protected $node;

    /**
     * EventChainRebase constructor.
     *
     * @param Account $node
     */
    public function __construct(Account $node)
    {
        $this->node = $node;
    }

    /**
     * Rebase chain with later starting event onto chain with earlier starting event
     *
     * @throws BadMethodCallException
     * @param EventChain $leadChain
     * @param EventChain $laterChain
     * @return EventChain
     */
    public function rebase(EventChain $leadChain, EventChain $laterChain): EventChain
    {
        if ($leadChain->isEmpty() || $laterChain->isEmpty()) {
            throw new BadMethodCallException('Rebasing chains should not be empty');
        }

        $events = [];
        foreach ($leadChain->events as $event) {
            $events[] = clone $event;
        }

        $mergedChain = (new EventChain())->withEvents($events);

        foreach ($laterChain->events as $key => $event) {
            $mergedChain->events[] = $this->rebaseEvent($event, $mergedChain);
        }

        return $mergedChain;
    }

    /**
     * Alias of `rebase()`
     *
     * @param EventChain $leadChain
     * @param EventChain $laterChain
     * @return EventChain
     */
    final public function __invoke(EventChain $leadChain, EventChain $laterChain): EventChain
    {
        return $this->rebase($leadChain, $laterChain);
    }

    /**
     * Rebase event to new chain
     *
     * @param Event $event
     * @param EventChain $mergedChain
     * @return Event
     */
    protected function rebaseEvent(Event $event, EventChain $mergedChain): Event
    {
        $stitched = new Event();

        $stitched->origin = $event->origin;
        $stitched->body = $event->body;
        $stitched->timestamp = Carbon::now()->getTimestamp();
        $stitched->previous = $mergedChain->getLatestHash();

        $stitched->original = $event->original ?? $event;

        return $stitched->signWith($this->node);
    }
}
