<?php

require './lib/soundcloud/Services/Soundcloud.php';
require './lib/log.php';

define('BASE_PATH', rtrim("http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']), '/'));

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
    logMsg('getMetadata');
    logMsg($params);

    $result = new StdClass();

    $params->id = explode('::', $params->id);

    switch ($params->id[0]) {
      case 'root':
        $result->index = 0;
        $result->mediaCollection = array(
          array(
            'id' => 'stream',
            'title' => 'Stream',
            'itemType' => 'playlist',
            'canPlay' => true
          ),
          array(
            'id' => 'you',
            'title' => 'You',
            'itemType' => 'container'
          ),
          array(
            'id' => 'explore',
            'title' => 'Explore',
            'itemType' => 'container'
          )
        );
        $result->count = $result->total = count($result->mediaCollection);
        break;
      case 'search':
        $result->index = 0;
        $result->mediaCollection = array(
          array(
            'id' => 'search_tracks',
            'title' => 'Sounds',
            'itemType' => 'search'
          )
        );

        $result->count = $result->total = count($result->mediaCollection);
        break;
      case 'stream':
        try {

          $options = array(
            'limit' => min($params->count, 100) // Sonos sometimes returns `2147483647` here which causes a 500 in the SoundCloud API
          );
          $stream = json_decode($this->soundcloud->get('e1/me/stream', $options), true);
          $stream = $stream['collection'];
        } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
          logMsg($e->getMessage());
        }
        $result->index = 0;
        $result->mediaMetadata = array();

        foreach ($stream as $item) {
          $isTrack = $item['type'] === 'track';
          $isTrackRepost = $item['type'] === 'track-repost';

          if ($isTrack || $isTrackRepost) {
            $track = $item['track'];
            $reposter = $isTrackRepost ? $item['user'] : null;

            array_push($result->mediaMetadata, $this->trackToMediaMetadata($track, $reposter));

          }
        }
        // TODO make pagination work with next_href
        $result->count = $result->total = count($result->mediaMetadata);
        break;
      case 'you':
        $result->index = 0;
        $result->mediaCollection = array(
          array(
            'itemType' => 'playlist',
            'id' => 'likes',
            'title' => 'Likes',
            'canPlay' => true
          ),
          array(
            'itemType' => 'artistTrackList',
            'id' => 'sounds',
            'title' => 'Sounds',
            'canPlay' => true
          )
        );
        $result->count = $result->total = count($result->mediaCollection);
        break;
      case 'likes':
        try {
          $options = array(
            'limit' => min($params->count, 100)
          );
          $likes = json_decode($this->soundcloud->get('me/favorites', $options), true);
        } catch(Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
          logMsg($e->getMessage());
        }
        $result->index = 0;
        $result->mediaMetadata = array();

        foreach ($likes as $item) {
          array_push($result->mediaMetadata, $this->trackToMediaMetadata($item));
        }
        $result->count = $result->total = count($result->mediaMetadata);
        break;
      case 'sounds':
        try {
          $options = array(
            'limit' => min($params->count, 100)
          );
          $likes = json_decode($this->soundcloud->get('me/tracks', $options), true);
        } catch(Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
          logMsg($e->getMessage());
        }
        $result->index = 0;
        $result->mediaMetadata = array();

        foreach ($likes as $item) {
          array_push($result->mediaMetadata, $this->trackToMediaMetadata($item));
        }
        $result->count = $result->total = count($result->mediaMetadata);
        break;
      case 'explore':
        $exploreCategory = isset($params->id[1]) ? $params->id[1] : false;
        $exploreSubcategory = isset($params->id[2]) ? $params->id[2] : false;
        try {
          $options = array(
            'limit' => 10
          );
          $categories = json_decode($this->soundcloud->get('explore/sounds/category' . ($exploreCategory ? '/' . $exploreCategory : ''), $options), true);
          $categories = $categories['collection'];
        } catch(Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
          logMsg($e->getMessage());
        }
        $result->index = 0;

        if ($exploreSubcategory) {
          $result->mediaMetadata = array();
          $trackIds = array();
          foreach ($categories as $category) {
            if ($category['permalink'] === $exploreSubcategory) {
              foreach ($category['tracks'] as $track) {
                array_push($trackIds, $track['id']);
              }
            }
          }
          $options = array(
            'ids' => implode(',', $trackIds)
          );
          $tracks = json_decode($this->soundcloud->get('tracks', $options), true);
          foreach ($tracks as $track) {
            array_push($result->mediaMetadata, $this->trackToMediaMetadata($track));
          }
          $result->count = $result->total = count($result->mediaMetadata);
        } else {
          $result->mediaCollection = array();
          foreach ($categories as $category) {
            array_push($result->mediaCollection, array(
              'itemType' => $exploreCategory ? 'playlist' : 'genre',
              'id' => 'explore::' . ($exploreCategory ? $exploreCategory . '::' : '') . $category['permalink'],
              'title' => $category['name'],
              'canPlay' => isset($exploreCategory) ? true : false
            ));
          }
          $result->count = $result->total = count($result->mediaCollection);
        }

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


  /**
   * getExtendedMetadata request
   *
   * @param  object $params
   * @param  string $params->id Unique id of the item
   *
   * @access public
   * @see http://musicpartners.sonos.com/node/127
   */
  public function getExtendedMetadata($params) {
    // logMsg('getExtendedMetadata');
    // logMsg($params);

    // TODO investigate what other metadata options are available to show here
    try {
      $track = json_decode($this->soundcloud->get('tracks/' . $params->id), true);
    } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
      logMsg($e->getMessage());
    }

    return array('getExtendedMetadataResult' => array(
      'mediaMetadata' => $this->trackToMediaMetadata($track)
    ));

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

    // logMsg('search');
    // logMsg($params);

    $result = new StdClass();
    $result->index = 0;

    switch ($params->id) {
      case 'search_tracks':
        try {
          $tracks = json_decode($this->soundcloud->get('tracks', array('q' => $params->term, 'limit'=> $params->count)), true);
        } catch (Services_Soundcloud_Invalid_Http_Response_Code_Exception $e) {
          logMsg($e->getMessage());
        }
        $result->mediaMetadata = array();

        foreach ($tracks as $track) {
          array_push($result->mediaMetadata, $this->trackToMediaMetadata($track));
        }

        $result->count = $result->total = count($result->mediaMetadata);
        break;
    }

    return array('searchResult' => $result);
  }

  /* ----------------------------------------- *
   * PRIVATE FUNCTIONS
   * ----------------------------------------- */

  private function trackToMediaMetadata($track, $reposter = null) {

    return array(
      'itemType'      => 'track',
      'id'            => (string)$track['id'],
      'title'         => substr($track['title'], 0, 63),
      'mimeType'      => 'audio/mp3',
      'trackMetadata' => $this->trackToTrackMetadata($track, $reposter)
    );
  }

  private function trackToTrackMetadata($track, $reposter = null) {

    if ($track['artwork_url'] === NULL) {
      $track['artwork_url'] = ($track['user']['avatar_url'] !== NULL)
                                ? $track['user']['avatar_url']
                                : BASE_PATH . '/assets/images/sound.png';
    }

    // change to higher resolution image
    $track['artwork_url'] = str_replace('large.jpg', 't200x200.jpg', $track['artwork_url']);

    return array(
      'artist'      => substr($track['user']['username'] . ($reposter ? ' (reposted by ' . $reposter['username'] . ')' : '') , 0, 63),
      'albumArtURI' => $track['artwork_url'],
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
