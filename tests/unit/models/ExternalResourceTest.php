<?php

/**
 * @covers ExternalResource
 */
class ExternalResourceTest extends \Codeception\Test\Unit
{
    function testGetId()
    {
        $resource = new ExternalResource();
        $resource->id = 'lt:/foos/123';
        
        $this->assertEquals('lt:/foos/123', $resource->getId());
    }
    
    function testGetIdProperty()
    {
        $this->assertEquals('id', ExternalResource::getIdProperty());
    }
    
    
    public function setVersionFromProvider()
    {
        return [
            ['lt:/foos/123'],
            ['lt:/foos/123?v=GKot5hBsd81kMupNCXHaqbhv3huEbxAFMLnpcX2hniwn']
        ];
    }
    
    /**
     * @dataProvider setVersionFromProvider
     * 
     * @param string $id
     */
    public function testSetVersionFrom($id)
    {
        $resource = new ExternalResource();
        $resource->id = $id;
        
        $resource->setVersionFrom("77qGgmn5kjj84aS3JRo6bP8mdDr2BSF35dNi5yH3DTZb5Ja2zVa2wo2");
        
        $this->assertAttributeEquals('lt:/foos/123?v=4ZL83zt5', 'id', $resource);
    }
    
    public function testFromEvent()
    {
        $event = $this->createMock(Event::class);
        $event->body = "77qGgmn5kjj84aS3JRo6bP8mdDr2BSF35dNi5yH3DTZb5Ja2zVa2wo2";
        $event->expects($this->atLeastOnce())->method('getBody')
            ->willReturn(['id' => 'lt:/foos/123', 'color' => 'red']);
        
        $resource = ExternalResource::fromEvent($event);
        
        $this->assertAttributeEquals('lt:/foos/123?v=4ZL83zt5', 'id', $resource);
        $this->assertAttributeEquals('red', 'color', $resource);
    }
    
    /**
     * @covers ResourceBase::setIdentity
     */
    public function testSetIdentity()
    {
        $identity = $this->createMock(Identity::class);
        
        $resource = new ExternalResource();
        
        $ret = $resource->setIdentity($identity);
        
        $this->assertSame($ret, $resource);
        $this->assertAttributeSame($identity, 'identity', $resource);
    }
}
