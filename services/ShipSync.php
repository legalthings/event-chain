<?php

declare(strict_types=1);

use LTO\Account;
use LTO\Event;
use LTO\EventChain;

/**
 * Class BlackBoxSync
 */
class ShipSync
{
    protected PDO $mysql;
    protected string $prefix;
    protected string $node;

    public function __construct(PDO $mysql, string $prefix, string $node)
    {
        $this->mysql = $mysql;
        $this->prefix = $prefix;
    }

    /**
     * Fetch the ship codes
     *
     * @return string[]
     */
    public function fetchShipCodes(): array
    {
        return $this->mysql->query("CALL IP_sel_LastShipStatus('0000-01-01', @err)")
            ->fetchColumn('ShipCode');
    }

    /**
     * Get the event chain id.
     *
     * @param Account $account
     * @param string $shipCode
     * @return string
     */
    public function getEventChainId(Account $account, string $shipCode): string
    {
        return $account->createEventChain("{$this->prefix}:{$shipCode}")->id;
    }

    /**
     * Create a new
     *
     * @param Account $account
     * @param string  $node
     * @param string  $shipCode
     * @return EventChain
     */
    public function createEventChain(Account $account, string $shipCode): EventChain
    {
        $chain = $account->createEventChain("{$this->prefix}:{$shipCode}");
        $chain->add($this->createEventForIdentity($account, $this->node))->signWith($account);
        $chain->add($this->createEventForShip($shipCode))->signWith($account);

        return $chain;
    }

    /**
     * Create an Identity for an account and wrap it in an Event.
     */
    public function createEventForIdentity(Account $account, string $node)
    {
        $identity = new Identity();
        $identity->id = $account->getAddress();
        $identity->node = $node;
        $identity->signkeys['default'] = $account->getPublicSignKey();

        return new Event(
            ['$schema' => 'https://specs.livecontracts.io/v0.2.0/identity/schema.json#']
            + $identity->getValues()
        );
    }

    /**
     * Fetch ship info and wrap it in an Event.
     */
    public function createEventForShip(string $shipCode)
    {
        $stmt = $this->mysql->prepare("CALL IP_sel_LastShipStatus(?, '0000-01-01', @err)");
        $stmt->execute([$shipCode]);
        $ship = $stmt->fetch(PDO::FETCH_ASSOC);

        return new Event(['$schema' => 'https://dekimo.lto.network/ship', 'id' => $shipCode] + $ship);
    }

    /**
     * Create a new event for a ship.
     */
    public function createEvent(string $shipCode, DateTimeInterface $lastRequested): ?Event
    {
        $data = [
            'registration' => $this->fetchData('Registration', $shipCode, $lastRequested),
            'deviation' => $this->fetchData('Deviation', $shipCode, $lastRequested),
            'status_time' => $this->fetchData('StatusTime', $shipCode, $lastRequested),
            'status_time_n200' => $this->fetchData('N200StatusTime', $shipCode, $lastRequested),
            'status_time_sea' => $this->fetchData('SeaStatusTime', $shipCode, $lastRequested),
            'status_time_fish_activity' => $this->fetchData('FishActivityStatusTime', $shipCode, $lastRequested),
        ];

        $history = [
            'registration' => $this->fetchData('RegistrationHistory', $shipCode, $lastRequested),
            'deviation' => $this->fetchData('DeviationHistory', $shipCode, $lastRequested),
            'status_time' => $this->fetchData('StatusTimeHistory', $shipCode, $lastRequested),
            'status_time_n200' => $this->fetchData('N200StatusTimeHistory', $shipCode, $lastRequested),
            'status_time_sea' => $this->fetchData('SeaStatusTimeHistory', $shipCode, $lastRequested),
            'status_time_fish_activity' => $this->fetchData('FishActivityStatusTimeHistory', $shipCode, $lastRequested),
        ];

        $keys = array_keys($data);

        if ($data === array_fill_keys($keys, []) && $history === array_fill_keys($keys, [])) {
            return null;
        }

        return new Event([
            '$schema' => 'https://dekimo.lto.network/shipevent',
            'id' => $shipCode,
            'data' => $data,
            'history' => $history
        ]);
    }

    /**
     * Fetch ship data.
     */
    protected function fetchData(string $proc, string $shipCode, DateTimeInterface $lastRequested)
    {
        $stmt = $this->mysql->prepare("CALL IP_sel_Last{$proc}(?, '?', @err)");
        $stmt->execute([$shipCode, $lastRequested->format('c')]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
