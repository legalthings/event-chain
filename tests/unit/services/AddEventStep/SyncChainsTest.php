<?php declare(strict_types=1);

namespace AddEventStep;

use ArrayObject;
use Improved as i;
use Event;
use EventChain;
use EventFactory;
use AnchorClient;
use Jasny\DB\EntitySet;
use Improved\IteratorPipeline\Pipeline;
use Jasny\ValidationResult;
use LTO\Account;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \AddEventStep\SyncChains
 */
class SyncChainsTest extends \Codeception\Test\Unit
{
    /**
     * @var SyncChains
     */
    protected $step;    

    /**
     * @var EventChain|MockObject
     */
    protected $chain;

    public function setUp()
    {
        $this->chain = $this->createMock(EventChain::class);
        $this->step = new SyncChains($this->chain);
    }

    public function provider()
    {
        $followingEvents = [
            $this->createMock(Event::class),
            $this->createMock(Event::class),
            $this->createMock(Event::class)
        ];

        $followingEvents[0]->hash = 'f';
        $followingEvents[1]->hash = 'g';
        $followingEvents[2]->hash = 'h';

        $expectedKeys = [$followingEvents[0], $followingEvents[1], $followingEvents[2], null, null];

        return [
            [$followingEvents, $expectedKeys],
            [[], [null, null, null, null, null]]
        ];
    }

    /**
     * @dataProvider provider
     */
    public function test($followingEvents, $expectedKeys)
    {
        $previous = 'foo_hash';

        $events = [
            $this->createMock(Event::class),
            $this->createMock(Event::class),
            $this->createMock(Event::class),
            $this->createMock(Event::class),
            $this->createMock(Event::class)
        ];

        $events[0]->previous = $previous;
        $events[0]->hash = 'a';
        $events[1]->hash = 'b';
        $events[2]->hash = 'c';
        $events[3]->hash = 'd';
        $events[4]->hash = 'e';

        $newEvents = new ArrayObject($events);

        $this->chain->expects($this->once())->method('getEventsAfter')->with($previous)->willReturn($followingEvents);

        $validation = $this->createMock(ValidationResult::class);
        $validation->expects($this->never())->method('addError');
       
        $ret = i\function_call($this->step, $newEvents, $validation);
        $this->assertInstanceOf(Pipeline::class, $ret);

        $retClone = clone $ret;

        $resultKeys = $ret->keys()->toArray();
        $resultValues = $retClone->values()->toArray();

        $this->assertEquals($expectedKeys, $resultKeys);
        $this->assertEquals($events, $resultValues);
    }

    public function testException()
    {
        $previous = 'foo_hash';

        $events = [
            $this->createMock(Event::class),
            $this->createMock(Event::class),
        ];

        $events[0]->previous = $previous;
        $events[0]->hash = 'a';
        $events[1]->hash = 'b';

        $newEvents = new ArrayObject($events);

        $this->chain->expects($this->once())->method('getEventsAfter')->with($previous)
            ->will($this->returnCallback(function($prev) {
                throw new \OutOfBoundsException('Test no events after');
            }));

        $validation = $this->createMock(ValidationResult::class);
        $validation->expects($this->once())->method('addError')->with("events don't fit on chain, '%s' not found", $previous);
        
        $expectedKeys = [null, null];
        $expectedValues = $events;
       
        $ret = i\function_call($this->step, $newEvents, $validation);
        $this->assertInstanceOf(Pipeline::class, $ret);

        $retClone = clone $ret;

        $resultKeys = $ret->keys()->toArray();
        $resultValues = $retClone->values()->toArray();

        $this->assertEquals($expectedKeys, $resultKeys);
        $this->assertEquals($expectedValues, $resultValues);
    }
}
