<?php
namespace FlowThread;

class Helper {

	public static function buildSQLInExpr(\DatabaseBase $db, array $arr) {
		$range = '';
		foreach ($arr as $item) {
			if ($range) {
				$range .= ',';
			}
			$range .= $db->addQuotes($item);
		}
		return ' IN(' . $range . ')';
	}

	public static function buildPostInExpr(\DatabaseBase $db, array $arr) {
		$range = '';
		foreach ($arr as $post) {
			if ($range) {
				$range .= ',';
			}
			$range .= $db->addQuotes($post->id->getBin());
		}
		return ' IN(' . $range . ')';
	}

	public static function batchFetchParent(array $posts) {
		$needed = [];
		$ids = [];
		foreach ($posts as $post) {
			$p = $post;
			while ($p->parent !== null) $p = $p->parent;
			if ($p->parentid !== null) {
				$needed[] = $p;
				$ids[] = $p->parentid->getBin();
			}
		}

		if ( !count($needed) ) return 0;

		$dbr = wfGetDB(DB_SLAVE);
		$inExpr = self::buildSQLInExpr($dbr, $ids);
		$res = $dbr->select('FlowThread', Post::getRequiredColumns(), [
			'flowthread_id' . $inExpr
		]);

		$ret = [];
		foreach ($res as $row) {
			$ret[UID::fromBin($row->flowthread_id)->getHex()] = $row;
		}
		foreach ($needed as $post) {
			if ($post->parent !== null || $post->parentid === null) continue;
			$hex = $post->parentid->getHex();
			if (isset($ret[$hex])) {
				$post->parent = Post::newFromDatabaseRow($ret[$hex]);
			} else {
				// Inconsistent database state, probably caused by a removed
				// parent but the child not being removed.
				// Treat as deleted
				$post->parentid = null;
				$post->status = Post::STATUS_DELETED;
			}
		}

		return count($needed);
	}

	public static function batchGetUserAttitude(\User $user, array $posts) {
		if (!count($posts)) {
			return array();
		}

		$ret = [];

		// In this case we don't even need db query
		if ($user->isAnon()) {
			foreach ($posts as $post) {
				$ret[$post->id->getHex()] = Post::ATTITUDE_NORMAL;
			}
			return $ret;
		}

		$dbr = wfGetDB(DB_SLAVE);

		$inExpr = self::buildPostInExpr($dbr, $posts);
		$res = $dbr->select('FlowThreadAttitude', array(
			'flowthread_att_id',
			'flowthread_att_type',
		), array(
			'flowthread_att_id' . $inExpr,
			'flowthread_att_userid' => $user->getId(),
		));

		foreach ($res as $row) {
			$ret[UID::fromBin($row->flowthread_att_id)->getHex()] = intval($row->flowthread_att_type);
		}
		foreach ($posts as $post) {
			if (!isset($ret[$post->id->getHex()])) {
				$ret[$post->id->getHex()] = Post::ATTITUDE_NORMAL;
			}
		}

		return $ret;
	}

	public static function generateMentionedList(\ParserOutput $output, Post $post) {
		$pageTitle = \Title::newFromId($post->pageid);
		$mentioned = array();
		$links = $output->getLinks();
		if (isset($links[NS_USER]) && is_array($links[NS_USER])) {
			foreach ($links[NS_USER] as $titleName => $pageId) {
				$user = \User::newFromName($titleName);
				if (!$user) {
					continue; // Invalid user
				}
				if ($user->isAnon()) {
					continue;
				}
				if ($user->getId() == $post->userid) {
					continue; // Mention oneself
				}
				if ($pageTitle->getNamespace() === NS_USER && $pageTitle->getDBkey() === $titleName) {
					continue; // Do mentioning in one's own page.
				}
				$mentioned[$user->getId()] = $user->getId();
			}
		}

		// Exclude all users that will be notified on Post hook
		$parent = $post->getParent();
		for (; $parent; $parent = $parent->getParent()) {
			if (isset($mentioned[$parent->userid])) {
				unset($mentioned[$parent->userid]);
			}
		}
		return $mentioned;
	}

	public static function getFilteredNamespace() {
		$ret = array(
			NS_MEDIAWIKI,
			NS_TEMPLATE,
			NS_CATEGORY,
			NS_FILE,
		);
		if (defined('NS_MODULE')) {
			$ret[] = NS_MODULE;
		}

		return $ret;
	}

	public static function canEverPostOnTitle(\Title $title) {
		// Disallow commenting on pages without article id
		if ($title->getArticleID() == 0) {
			return false;
		}

		if ($title->isSpecialPage()) {
			return false;
		}

		// These could be explicitly allowed in later version
		if (!$title->canTalk()) {
			return false;
		}

		if ($title->isTalkPage()) {
			return false;
		}

		// No commenting on main page
		if ($title->isMainPage()) {
			return false;
		}

		// Blacklist several namespace
		if (in_array($title->getNamespace(), self::getFilteredNamespace())) {
			return false;
		}

		return true;
	}
}
