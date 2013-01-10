<?php

include 'lib/Soundcloud.php';
include 'lib/log.php';

// logMsg('-------------------REQUEST START---------------------');

class SonosService {

  /**
   * SoundCloud SDK instance
   *
   * @var class
   * @access private
   */
  private $soundcloud;

  private $sessionId;

  /**
   * Class constructor
   *
   * @return void
   * @access public
   */
  function SonosService() {
    $this->soundcloud = new Services_Soundcloud(
      '414b47389d9116fd4d1daca6bea4476a', // client id
      '59667049b96bf93a9cd3f0683e0d60b2'  // client secret
    );
  }

  /* ----------------------------------------- *
   * PUBLIC FUNCTIONS
   * ----------------------------------------- */

  /**
   * getSessionId request
   *
   * @param  object $params Credentials
   *
   * @access public
   */
  public function credentials($params) {
    // logMsg('credentials');
    // logMsg($params);

    if (isset($params->sessionId)) {
      $this->sessionId = $params->sessionId;
      $this->soundcloud->setAccessToken($params->sessionId);
    }
  }

  /**
   * getSessionId request
   *
   * @param  object $params
   * @param  string $params->username The username for the account holder
   * @param  string $params->password The password for the account holder
   * @return array
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/82
   */
  public function getSessionId($params) {
    try {
      $data = array(
        'username' => $params->username,
        'password' => $params->password,
        'grant_type' => 'password',
        'scope' => 'non-expiring'
      );
      $response = $this->soundcloud->accessToken(null, $data);
    } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
      logMsg($e->getMessage());
    }

    if (!$response) {
      throw new SoapFault('Client.LoginUnauthorized', 'MSG_SOAPFAULT_LOGIN_UNAUTHORIZED');
    }

    $result = array(
      'getSessionIdResult' => $response['access_token']
    );
    // logMsg($result);
    return $result;
  }

  /**
   * getMetadata request
   *
   * @param  object  $params
   * @param  string  $params->id        Unique id of the item
   * @param  integer $params->index     Zero-based index at which to start retrieving metadata items
   * @param  integer $params->count     Number of items requested
   * @param  boolean $params->recursive If true, returns a flat collection of track metadata
   * @return array
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/83
   */
  public function getMetadata($params) {
    // logMsg('getMetadata');
    // logMsg($params);

    $result = new StdClass();

    switch ($params->id) {
      case 'root':
        $result->index = 0;
        $result->mediaCollection = array(
          array(
            'itemType' => 'playlist',
            'id' => 'stream',
            'title' => 'Stream',
            'canPlay' => true
          ),
          array(
            'itemType' => 'container',
            'id' => 'you',
            'title' => 'You'
          )
        );
        $result->count = $result->total = count($result->mediaCollection);
        break;
      case 'stream':
        try {

          $options = array(
            'limit' => min($params->count, 100) // Sonos sometimes returns `2147483647` here which causes a 500 in the SoundCloud API
          );
          $stream = json_decode($this->soundcloud->get('me/activities/tracks/affiliated', $options), true);
          $stream = $stream['collection'];
        } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
          logMsg($e->getMessage());
        }
        $result->index = 0;
        $result->mediaMetadata = array();

        foreach ($stream as $item) {
          $track = ($item['type'] === 'track-sharing') ? $item['origin']['track'] : $item['origin'];
          array_push($result->mediaMetadata, $this->trackToMediaMetadata($track));
        }

        // TODO make pagination work with next_href
        $result->count = $result->total = count($result->mediaMetadata);
        break;
    }
    // logMsg($result);
    return array('getMetadataResult' => $result);
  }

  /**
   * getMediaMetadata request
   *
   * @param  object  $params
   * @param  string  $params->id Unique id of the item
   * @return array
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/84
   */
  public function getMediaMetadata($params) {
    // logMsg('getMediaMetadata');
    // logMsg($params);

    try {
      $track = json_decode($this->soundcloud->get('tracks/' . $params->id), true);
    } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
      logMsg($e->getMessage());
    }

    return array('getMediaMetadataResult' => $this->trackToMediaMetadata($track));
  }

  /**
   * getMediaURI request
   *
   * @param  object $params
   * @param  string $params->id Unique id of the item
   * @return array
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/85
   */
  public function getMediaURI($params) {
    // logMsg('getMediaURI');
    // logMsg($params);

    try {
      $track = json_decode($this->soundcloud->get('tracks/' . $params->id), true);
      $url = $track['stream_url'] . '?client_id=414b47389d9116fd4d1daca6bea4476a&oauth_token=' . $this->sessionId;
      $headers = get_headers($url, 1);
      $url = $headers['Location'];
    } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
      logMsg($e->getMessage());
    }
    // logMsg($url);
    return array('getMediaURIResult' => $url);
  }

  /**
   * getLastUpdate request
   *
   * @return array
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/87
   */
  public function getLastUpdate() {
    // logMsg('getLastUpdate');

    $result = new StdClass();
    $result->catalog = time();
    $result->favorites = time();
    $result->pollInterval = 60;

    return array('getLastUpdateResult' => $result );
  }

  public function getExtendedMetadata($params) {
    // logMsg('getExtendedMetadata');
    // logMsg($params);
  }

  public function getExtendedMetadataText($params) {
    // logMsg('getExtendedMetadataText');
    // logMsg($params);
  }

  /**
   * search request
   *
   * @param object  $params
   * @param string  $params->id    Id of the search category
   * @param string  $params->term  Search term
   * @param integer $params->index Zero-based index at which to start retrieving search results
   * @param integer $params->count Number of search results requested
   * @return array
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/86
   */
  public function search($params) {

    logMsg('search');
    logMsg($params);

    $result = new StdClass();

    try {
      $tracks = json_decode($this->soundcloud->get('tracks', array('q' => $params->term, 'limit'=> $params->count)), true);
    } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
      logMsg($e->getMessage());
    }

    $result->index = 0;
    $result->mediaMetadata = array();

    foreach ($tracks as $track) {
      $track = $item['origin'];
      array_push($result->mediaMetadata, $this->trackToMediaMetadata($track));
    }

    $result->count = $result->total = count($result->mediaMetadata);

    return array('searchResult' => $result);
  }

  /* ----------------------------------------- *
   * PRIVATE FUNCTIONS
   * ----------------------------------------- */

  private function trackToMediaMetadata($track) {

    return array(
      'itemType'      => 'track',
      'id'            => (string)$track['id'],
      'title'         => substr($track['title'], 0, 63),
      'mimeType'      => 'audio/mp3',
      'trackMetadata' => $this->trackToTrackMetadata($track)
    );
  }

  private function trackToTrackMetadata($track) {
    if (!isset($track['artwork_url'])) {
      // TODO set default artwork
      $track['artwork_url']  = '';
    }

    return array(
      'artist'      => substr($track['user']['username'], 0, 63),
      'albumArtURI' => $track['artwork_url'],
      // 'genre'       => $track['genre'],
      'genre'       => '',
      'duration'    => ceil($track['duration']/1000)
    );
  }
}


// Initialize SOAP server
$server = new SoapServer('sonos.wsdl', array(
  'cache_wsdl' => 0 // disable cache in development
));
$server->setClass('SonosService');

// Initialize request
try {
  $server->handle();

} catch (Exception $e) {
  // handle error

}

?>
