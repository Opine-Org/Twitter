<?php
/**
 * Opine\Twitter
 *
 * Copyright (c)2013, 2014 Ryan Mahoney, https://github.com/Opine-Org <ryan@virtuecenter.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
namespace Foo;

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
            $tweet['acl'] = ['public'];
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
            $this->cache->set($key, '', $expire);
            return false;
        }
        var_dump($twtData);flush();
        if ($twtData === false) {
            $this->cache->set($key, '', $expire);
            return false;
        }
        $this->cache->set($key, $twtData, $expire);
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
