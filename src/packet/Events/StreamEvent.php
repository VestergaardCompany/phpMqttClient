<?php
namespace oliverlorenz\reactphpmqtt\packet\Events;

/**
 * Stream Event
 *
 * @author Alin Eugen Deac <ade@vestergaardcompany.com>
 * @package oliverlorenz\reactphpmqtt\packet\Events
 */
interface StreamEvent
{
    /**
     * TODO: desc...
     */
    const CONNECTION_ACK = 'CONNECTION_ACK';

    /**
     * TODO: desc...
     */
    const PING_RESPONSE = 'PING_RESPONSE';

    /**
     * TODO: desc...
     */
    const PUBLISH = 'PUBLISH';

    /**
     * TODO: desc...
     */
    const PUBLISH_RECEIVED = 'PUBLISH_RECEIVED';

    /**
     * TODO: desc...
     */
    const PUBLISH_RELEASE = 'PUBLISH_RELEASE';

    /**
     * TODO: desc...
     */
    const UNSUBSCRIBE_ACK = 'UNSUBSCRIBE_ACK';

    /**
     * TODO: desc...
     */
    const SUBSCRIBE_ACK = 'SUBSCRIBE_ACK';
}