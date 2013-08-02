<?php

class SonosService_Test extends PHPUnit_Framework_TestCase {

  private $client;
  private $wsdl = "http://localhost/soundcloud/sonos/index.php?wsdl";

  public function SonosService_Test() {
    // Start client
    $this->client = new SoapClient($this->wsdl, array("cache_wsdl" => 0));
    // Get credentials and set header
    $credentials = $this->client->getSessionId(array('username'=>'sonoscloud', 'password'=>'sonos123'));
    $header = new SOAPHeader($this->wsdl, 'credentials', (object)array(
      'sessionId' => $credentials->getSessionIdResult
    ));
    $this->client->__setSoapHeaders($header);
  }

  public function testGetMetadata() {
    // Test root
    $params = (object)array(
      'id' => 'root',
      'index' => 0,
      'count' => 100
    );
    $result = $this->client->getMetadata($params);
    $this->assertAttributeEquals(2, 'total', $result->getMetadataResult, 'Root menu should have 2 items');

    // Test stream
    $params = (object)array(
      'id' => 'stream',
      'index' => 0,
      'count' => 100
    );
    $result = $this->client->getMetadata($params);
    // $this->assertCount(100, $result->getMetadataResult->mediaMetadata, 'Should contain 100 results');
    // $this->assertAttributeEquals(100, 'count', $result->getMetadataResult, 'Should return correct total');
  }

  public function testGetMediaMetadata() {
    $params = (object)array(
      'id' => '47075488'
    );
    $result = $this->client->getMediaMetadata($params);
    $this->assertAttributeEquals('47075488', 'id', $result->getMediaMetadataResult, 'Return the correct track id');
  }

  public function testGetMediaURI() {
    $params = (object)array(
      'id' => '47075488'
    );
    $result = $this->client->getMediaURI($params);
    $this->assertStringStartsWith('http', $result->getMediaURIResult, 'Return the correct track URI');
  }

}

?>
