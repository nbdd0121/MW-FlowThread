<?php
namespace FlowThread;

class PopularPosts {

	const CACHE_TTL = 3600;

	public static function getFromPageId($pageid) {
		return self::fetchFromCache($pageid);
	}

	public static function invalidateCache($post) {
		$pageid = $post->pageid;
		$cache = \ObjectCache::getMainWANInstance();
		$key = wfMemcKey('flowthread', 'popular', $pageid);
		$cachedValue = $cache->get($key);
		if ($cachedValue === false) {
			return;
		}
		if (isset($cachedValue[$post->id->getBin()])) {
			$cache->delete($key);
		}
	}

	private static function fetchFromCache($pageid) {
		$cache = \ObjectCache::getMainWANInstance();
		$key = wfMemcKey('flowthread', 'popular', $pageid);
		$cachedValue = $cache->get($key);
		if ($cachedValue === false) {
			$posts = self::fetchFromDB($pageid);
			$valueToCache = array();
			foreach ($posts as $post) {
				$valueToCache[$post->id->getBin()] = $post->getFavorCount();
			}
			$cache->set($key, $valueToCache, self::CACHE_TTL);
		} else {
			$posts = array();
			foreach ($cachedValue as $id => $count) {
				$posts[] = Post::newFromId(UID::fromBin($id));
			}
		}
		return $posts;
	}

	private static function fetchFromDB($pageid) {
		global $wgFlowThreadConfig;
		if (!$wgFlowThreadConfig['PopularPostCount']) {
			return array();
		}
		$dbr = wfGetDB(DB_SLAVE);
		$cond = array(
			'flowthread_pageid' => $pageid,
			'flowthread_status' => Post::STATUS_NORMAL,
			'flowthread_like >= ' . $wgFlowThreadConfig['PopularPostThreshold'],
		);
		$options = array(
			'ORDER BY' => 'flowthread_like DESC, flowthread_id DESC',
			'LIMIT' => $wgFlowThreadConfig['PopularPostCount'],
		);
		$res = $dbr->select('FlowThread', Post::getRequiredColumns(), $cond, __METHOD__, $options);
		$comments = array();
		foreach ($res as $row) {
			$post = Post::newFromDatabaseRow($row);
			$comments[] = $post;
		}
		return $comments;
	}
}
