<?php
namespace FlowThread;

class SpecialLink extends \RedirectSpecialPage {

	private $query = array();

	public function __construct() {
		parent::__construct('FlowThreadLink');
	}

	public function getRedirect($subpage) {
		if ($subpage) {
			$post = Post::newFromId(UID::fromHex($subpage));
			if ($post) {
				while ($post->getParent()) {
					$post = $post->getParent();
				}

				$db = wfGetDB(DB_SLAVE);

				$cond = array(
					'flowthread_pageid' => $post->pageid,
					'flowthread_id > ' . $db->addQuotes($post->id->getBin()),
					'flowthread_parentid IS NULL',
					'flowthread_status' => Post::STATUS_NORMAL,
				);

				$row = $db->selectRow('FlowThread', array(
					'count' => 'count(*)',
				), $cond);

				if ($row) {
					$pageCount = intval($row->count / 10) + 1;
					if ($pageCount !== 1) {
						$this->query['flowthread-page'] = $pageCount;
					}
				}

				return \Title::newFromId($post->pageid)->createFragmentTarget('comment-' . $subpage);
			}
		}
		throw new \ErrorPageError('nopagetitle', 'nopagetext');
	}

	public function getRedirectQuery() {
		return $this->query;
	}
}
