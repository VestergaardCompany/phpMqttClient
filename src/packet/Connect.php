<?php
/**
 * @author Oliver Lorenz
 * @since 2015-04-24
 * Time: 00:58
 */

namespace oliverlorenz\reactphpmqtt\packet;

use oliverlorenz\reactphpmqtt\protocol\Version;

class Connect extends ControlPacket {

    /** @var bool */
    protected $useVariableHeader = true;
    
    /**
     * @var ConnectionOptions
     */
    protected $options = null;

    /**
     * @param Version $version
     * @param ConnectionOptions $options
     */
    public function __construct(Version $version, ConnectionOptions $options)
    {
        parent::__construct($version);

        $this->options = clone $options;

        $this->buildPayload();
    }

    protected function buildPayload()
    {
        $this->addLengthPrefixedField($this->getClientId());
        if (!is_null($this->options->willTopic) && !is_null($this->options->willMessage)) {
            $this->addLengthPrefixedField($this->options->willTopic);
            $this->addLengthPrefixedField($this->options->willMessage);
        }
        if (!empty($this->options->username)) {
            $this->addLengthPrefixedField($this->options->username);
        }
        if (!empty($this->options->password)) {
            $this->addLengthPrefixedField($this->options->password);
        }
    }

    /**
     * @return int
     */
    public static function getControlPacketType()
    {
        return ControlPacketType::CONNECT;
    }

    /**
     * @return string
     */
    protected function getVariableHeader()
    {
        return chr(ControlPacketType::MOST_SIGNIFICANT_BYTE)         // byte 1
        . chr(strlen($this->version->getProtocolIdentifierString())) // byte 2
        . $this->version->getProtocolIdentifierString()              // byte 3,4,5,6
        . chr($this->version->getProtocolVersion())                  // byte 7
        . chr($this->getConnectFlags())                              // byte 8
        . chr(($this->options->keepAlive >> 8) & 0x00FF)             // byte 9
        . chr($this->options->keepAlive & 0x00FF)                    // byte 10
        ;
    }

    /**
     * @return int
     */
    protected function getConnectFlags()
    {
        $connectByte = 0;
        if ($this->options->cleanSession) {
            $connectByte += 1 << 1;
        }
        if (!is_null($this->options->willTopic) && !is_null($this->options->willMessage)) {
            $connectByte += 1 << 2;
        }

        if ($this->options->willQos) {
            $connectByte += 1 << 3;
            // 4 TODO ?
        }

        if ($this->options->willRetain) {
            $connectByte += 1 << 5;
        }

        if (!empty($this->options->password)) {
            $connectByte += 1 << 6;
        }

        if (!empty($this->options->username)) {
            $connectByte += 1 << 7;
        }
        return $connectByte;
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        if (is_null($this->options->clientId)) {
            $this->options->clientId = md5(microtime());
        }
        return substr($this->options->clientId, 0, 23);
    }
}