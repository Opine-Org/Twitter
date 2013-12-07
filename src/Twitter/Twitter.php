<?php
namespace Twitter;

class Twitter {
	private $cache;
	private $db;
	private $root;
	private $queue;
	private $config;

	public function __construct ($root, $cache, $queue, $db, $config) {
		$this->root = $root;
		$this->cache = $cache;
		$this->queue = $queue;
		$this->db = $db;
		$this->config = $config;
	}

	public function tweets($value, $limit=10, $expire=600, $type='user') {
		if (!in_array($type, ['user', 'search'])) {
			throw new \Exception('invalid twitter API type: ' . $type);
		}
		$key = $this->root . '-TWITTERFEED-'  . md5($type . '-' . $value);
		$feed = $this->cache->get($key);
		if ($feed === false) {
			$this->queue->add('TweetsFetch', [
				'value' => $value,
				'expire' => $expire,
				'type' => $type
			]);
		}
		return $this->findAll($type, $value, $limit);
	}

	private function findAll($type, $value, $limit) {
		$key = $type . '-' . $value;
		$tweets = $this->db->collection('tweets')->findOne(['key' => $key], ['tweets']);
		if (!isset($tweets['_id']) || !isset($tweets['tweets']) || !is_array($tweets['tweets']) || count($tweets['tweets']) == 0) {
			return [];
		}
		return $tweets['tweets'];
	}

	public function save($type, $value, Array $tweets) {
		foreach ($tweets as $tweet) {
			$tweet['key'] = $type . '-' . $value;
			$tweet['created_date'] = new \MongoDate(strtotime($tweet['created_at']));
			$this->db->collection('tweets')->update(
				['id_str' => $tweet['id_str']], 
				$tweet, 
				['upsert' => true]
			);
		}
		$this->db->collection('tweets')->ensureIndex(['key' => 1, 'id_str' => 1]);
	}

	public function externalFetch($value, $expire, $type='user') {
        $key = $this->root . '-TWITTERFEED-'  . md5($type . '-' . $value);
		$config = $this->config->twitter;
		try {
            $settings = [
                'oauth_access_token' => $config['oauth_access_token'],
                'oauth_access_token_secret' => $config['oauth_access_token_secret'],
                'consumer_key' => $config['consumer_key'],
                'consumer_secret' => $config['consumer_secret']
            ];
            $requestMethod = 'GET';
            if ($type == 'user') {
                $url = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
                $getfield = '?screen_name=' . $value;
            } else {
                $url = 'https://api.twitter.com/1.1/search/tweets.json';
                $getfield = '?q=' . urldecode($value);
            }
            $twitter = new \TwitterAPIExchange($settings);
            $twtData = $twitter->setGetfield($getfield)->buildOauth($url, $requestMethod)->performRequest();
		} catch (\Exception $e) {
//FIXME: monolog?
			$this->cache->set($key, '', 0, $expire);
			return false;
		}
		var_dump($twtData);flush();
		if ($twtData === false) {
			$this->cache->set($key, '', 0, $expire);
			return false;
		}
		$this->cache->set($key, $twtData, 0, $expire);
		$twtData = json_decode($twtData, true);
		if (isset($twtData['error']) || !is_array($twtData) || count($twtData) == 0) {
			return false;
		}
		if ($type == 'user') {
			return $twtData;
		} else {
			if (isset ($twtData['results'])) {
				return $twtData['results'];
			}
			return false;
		}
	}
}