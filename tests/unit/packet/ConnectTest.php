<?php
/**
 * @author Oliver Lorenz
 * @since 2015-05-08
 * Time: 18:56
 */

use oliverlorenz\reactphpmqtt\packet\Connect;
use oliverlorenz\reactphpmqtt\packet\ConnectionOptions;
use \oliverlorenz\reactphpmqtt\packet\MessageHelper;
use oliverlorenz\reactphpmqtt\protocol\Version4;

class ConnectTest extends PHPUnit_Framework_TestCase {

    /**********************************************************
     * Helpers
     *********************************************************/

    /**
     * Returns a connection options instance
     *
     * @param array $data [optional]
     *
     * @return ConnectionOptions
     */
    public function makeOptions(array $data = [])
    {
        return new ConnectionOptions($data);
    }

    /**
     * Returns a new Protocol version instance
     *
     * @return Version4
     */
    public function makeProtocolVersion()
    {
        return new Version4();
    }

    /**
     * Returns a new connection instance
     *
     * @param ConnectionOptions $options [optional]
     *
     * @return Connect
     */
    public function makeConnection(ConnectionOptions $options = null)
    {
        if(!isset($options)){
            $options = $this->makeOptions();
        }

        return new Connect($this->makeProtocolVersion(), $options);
    }

    /**********************************************************
     * Helpers
     *********************************************************/

    public function testGetControlPacketType()
    {
        $packet = $this->makeConnection();

        $this->assertEquals(
            1,
            Connect::getControlPacketType()
        );
    }

    public function testGetHeaderTestFixedHeader()
    {
        $options = $this->makeOptions(['clientId' => 'clientid']);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(chr(1 << 4) . chr(20)),
            MessageHelper::getReadableByRawString(substr($packet->get(), 0, 2))
        );
    }

    public function testGetHeaderTestVariableHeaderWithoutConnectFlags()
    {
        $options = $this->makeOptions([
            'clientId'      => 'clientid',
            'cleanSession'  => false
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(0) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagsCleanSession()
    {
        $packet = $this->makeConnection();

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(2) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagWillFlag()
    {
        $options = $this->makeOptions([
            'clientId'      =>  'clientid',
            'cleanSession'  =>  false,
            'willTopic'     =>  'willTopic',
            'willMessage'   =>  'willMessage'
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(4) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagWillRetain()
    {
        $options = $this->makeOptions([
            'clientId'      =>  'clientid',
            'cleanSession'  =>  false,
            'willRetain'    =>  true
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(32) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagUsername()
    {
        $options = $this->makeOptions([
            'clientId'      =>  'clientId',
            'username'      =>  'username',
            'cleanSession'  =>  false,
            'willTopic'     =>  false // ??? -> why is this allowed?
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(128) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagPassword()
    {
        $options = $this->makeOptions([
            'clientId'      =>  'clientId',
            'password'      =>  'password',
            'cleanSession'  =>  false
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(64) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagWillWillQos()
    {
        $options = $this->makeOptions([
            'clientId'      =>  'clientId',
            'cleanSession'  =>  false,
            'willQos'       =>  true, // ??? - why is a bool allowed?
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(8) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestVariableHeaderWithConnectFlagUserNamePasswordCleanSession()
    {
        $options = $this->makeOptions([
            'username'      =>  'username',
            'password'      =>  'password',
            'clientId'      =>  'clientId',
            'cleanSession'  =>  true,
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            MessageHelper::getReadableByRawString(
                chr(0) .    // byte 1
                chr(4) .    // byte 2
                'MQTT' .    // byte 3,4,5,6
                chr(4) .    // byte 7
                chr(194) .    // byte 8
                chr(0) .    // byte 9
                chr(10)     // byte 10
            ),
            MessageHelper::getReadableByRawString(substr($packet->get(), 2, 10))
        );
    }

    public function testGetHeaderTestPayloadClientId()
    {
        $options = $this->makeOptions([
            'clientId'      =>  'clientid',
        ]);
        $packet = $this->makeConnection($options);

        $this->assertEquals(
            substr($packet->get(), 12),
            chr(0) .    // byte 1
            chr(8) .    // byte 2
            'clientid'
        );
    }

}