<?php declare(strict_types=1);

use LTO\Account;
use Jasny\ValidationResult;
use Jasny\DB\Entity\Identifiable;
use function Jasny\object_get_properties;
use function Jasny\object_set_properties;

/**
 * Event entity
 */
class Event extends MongoSubDocument implements Identifiable
{
    /**
     * The node that sent the event
     *
     * @var string|null
     */
    public $origin;

    /**
     * Base58 encoded JSON string with the body of the event.
     *
     * @var string
     * @required
     */
    public $body;

    /**
     * The extracted body (cached) as associated array
     *
     * @var array|boolean
     */
    protected $cachedBody = false;

    /**
     * Time when the event was signed.
     *
     * @var int
     * @required
     */
    public $timestamp;

    /**
     * Hash to the previous event
     *
     * @var string
     * @required
     */
    public $previous;

    /**
     * URI of the public key used to sign the event
     *
     * @var string
     * @required
     */
    public $signkey;

    /**
     * Base58 encoded signature of the event
     *
     * @var string
     * @required
     */
    public $signature;

    /**
     * SHA256 hash of the event
     *
     * @var string
     * @id
     * @required
     */
    public $hash;

    /**
     * Receipt for anchoring on public blockchain
     *
     * @var null
     * @immutable
     */
    public $receipt;

    /**
     * Original event if event is stitched
     *
     * @var Event|null
     * @immutable
     */
    public $original;

    /**
     * Cast all properties
     *
     * @return $this
     */
    public function cast()
    {
        if (is_a($this->timestamp, MongoDB\BSON\Int64::class)) {
            $this->timestamp = (string)$this->timestamp;
        }

        return parent::cast();
    }

    /**
     * Set values
     *
     * @param array|object $values
     * @return $this
     */
    public function setValues($values): self
    {
        if (!$this->isNew()) {
            throw new BadMethodCallException("Event is immutable");
        }

        $this->cachedBody = false; // Clear cached body

        parent::setValues($values);

        return $this;
    }

    /**
     * Sign this event
     *
     * @param Account $account
     * @return $this
     */
    public function signWith(Account $account): self
    {
        $ltoEvent = $this->castToLtoEvent();
        $ltoEvent = $account->signEvent($ltoEvent);

        $this->setValues([
            'signkey' => $ltoEvent->signkey,
            'signature' => $ltoEvent->signature,
            'hash' => $ltoEvent->hash,
        ]);

        return $this;
    }

    /**
     * Cast to LTO\Event
     *
     * @return LTO\Event
     */
    public function castToLtoEvent(): \LTO\Event
    {
        $values = $this->getValues();

        if ($this->original !== null) {
            $values['original'] = $this->original->castToLtoEvent();
        }

        $ltoEvent = new LTO\Event();
        object_set_properties($ltoEvent, $values);

        return $ltoEvent;
    }

    /**
     * Get the message used for hash and signature
     *
     * @return string
     */
    public function getMessage(): string
    {
        $baseParts = [
            $this->body,
            $this->timestamp,
            $this->previous,
            $this->signkey
        ];
        $parts = array_merge($baseParts, $this->original !== null ? [$this->original->hash] : []);

        return join("\n", $parts);
    }

    /**
     * Get the base58 encoded hash of the event
     *
     * @return string
     */
    public function getHash(): string
    {
        $hash = hash('sha256', $this->getMessage(), true);

        return base58_encode($hash);
    }

    /**
     * Get the decoded body
     *
     * @return array|null
     */
    public function getBody(): ?array
    {
        if (is_array($this->cachedBody)) {
            return $this->cachedBody;
        }

        if (!isset($this->body)) {
            return null;
        }

        $json = base58_decode($this->body);

        $this->cachedBody = $json ? json_decode($json, true) : null;

        return $this->cachedBody;
    }

    /**
     * Verify that the signature is valid
     *
     * @return bool
     */
    public function verifySignature(): bool
    {
        if (!isset($this->signature) || !isset($this->signkey)) {
            return false;
        }

        $signature = base58_decode($this->signature);
        $signkey = base58_decode($this->signkey);

        return strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES &&
            strlen($signkey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES &&
            sodium_crypto_sign_verify_detached($signature, $this->getMessage(), $signkey);
    }

    /**
     * Validate the event
     *
     * @return ValidationResult
     */
    public function validate(): ValidationResult
    {
        $validation = parent::validate();

        $body = $this->getBody();
        if (isset($this->body) && $body === null) {
            $validation->addError('body is not base58 encoded json');
        }

        if (isset($body) && !isset($body['$schema'])) {
            $validation->addError('body is does not contain the $schema property');
        }

        if (isset($this->signature) && !$this->verifySignature()) {
            $validation->addError('invalid signature');
        }

        if (isset($this->hash) && $this->getHash() !== $this->hash) {
            $validation->addError('invalid hash');
        }

        if (isset($this->original)) {
            $validation->add($this->original->validate(), "original event;");
        }

        if (isset($this->receipt)) {
            $validation->add($this->receipt->validate(), "invalid receipt;");

            if ($this->receipt->targetHash !== $this->hash) {
                $validation->add(ValidationResult::error("hash doesn't match"), "invalid receipt;");
            }
        }

        return $validation;
    }


    /**
     * Turn entity into data to be stored in the DB.
     *
     * @return array
     */
    public function toData()
    {
        $data = parent::toData();

        if (isset($data['original'])) {
            unset($data['original']['origin'], $data['original']['body']);
        }

        return $data;
    }

    /**
     * Turn entity into data to be stored in the DB.
     *
     * @param array $data
     * @return Event
     */
    public static function fromData($data)
    {
        $data = (array)$data;

        if (isset($data['original'])) {
            $data['original']['body'] = $data['body'];
        }

        return parent::fromData($data);
    }
}
