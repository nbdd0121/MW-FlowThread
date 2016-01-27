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

	public static function batchGetUserAttitude(\User $user, array $posts) {
		if (!count($posts)) {
			return array();
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

		$ret = array();
		foreach ($res as $row) {
			$ret[UUID::fromBin($row->flowthread_att_id)->getHex()] = intval($row->flowthread_att_type);
		}
		foreach ($posts as $post) {
			if (!isset($ret[$post->id->getHex()])) {
				$ret[$post->id->getHex()] = Post::ATTITUDE_NORMAL;
			}
		}

		return $ret;
	}
}