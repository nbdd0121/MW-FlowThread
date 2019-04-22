<?php
namespace FlowThread;

class Query {
	const FILTER_ALL = 0;
	const FILTER_NORMAL = 1;
	const FILTER_REPORTED = 2;
	const FILTER_DELETED = 3;
	const FILTER_SPAM = 4;

	// Query options
	public $pageid = 0;
	public $user = '';
	public $keyword = '';
	public $dir = 'older';
	public $offset = 0;
	public $limit = -1;
	public $threadMode = true;
	public $filter = self::FILTER_ALL;

	// Query results
	public $totalCount = 0;
	public $posts = null;

	public function fetch() {
		$dbr = wfGetDB(DB_SLAVE);

		$comments = array();
		$parentLookup = array();

		$options = array(
			'OFFSET' => $this->offset,
			'ORDER BY' => 'flowthread_id ' . ($this->dir === 'older' ? 'DESC' : 'ASC'),
		);
		if ($this->limit !== -1) {
			$options['LIMIT'] = $this->limit;
		}
		if ($this->threadMode) {
			$options[] = 'SQL_CALC_FOUND_ROWS';
		}

		$cond = [];
		if ($this->pageid) {
			$cond['flowthread_pageid'] = $this->pageid;
		}
		if ($this->user) {
			$cond['flowthread_username'] = $this->user;
		}
		if ($this->keyword) {
			$cond[] = 'flowthread_text' . $dbr->buildLike($dbr->anyString(), $this->keyword, $dbr->anyString());
		}
		if ($this->threadMode) {
			$cond[] = 'flowthread_parentid IS NULL';
		}

		switch ($this->filter) {
		case static::FILTER_ALL:
			break;
		case static::FILTER_NORMAL:
			$cond['flowthread_status'] = Post::STATUS_NORMAL;
			break;
		case static::FILTER_REPORTED:
			$cond['flowthread_status'] = Post::STATUS_NORMAL;
			$cond[] = 'flowthread_report > 0';
			$options['ORDER BY'] = 'flowthread_report DESC, ' . $options['ORDER BY'];
			break;
		case self::FILTER_DELETED:
			$cond['flowthread_status'] = Post::STATUS_DELETED;
			break;
		case self::FILTER_SPAM:
			$cond['flowthread_status'] = Post::STATUS_SPAM;
			break;
		}

		// Get all root posts
		$res = $dbr->select('FlowThread', Post::getRequiredColumns(),
			$cond, __METHOD__, $options);

		$sqlPart = '';
		foreach ($res as $row) {
			$post = Post::newFromDatabaseRow($row);
			$comments[] = $post;
			$parentLookup[$post->id->getBin()] = $post;

			// Build SQL Statement for children query
			if ($sqlPart) {
				$sqlPart .= ',';
			}
			$sqlPart .= $dbr->addQuotes($post->id->getBin());
		}

		if ($this->threadMode) {
			$this->totalCount = intval($dbr->query('select FOUND_ROWS() as row')->fetchObject()->row);

			// Recursively get all children post list
			// This is not really resource consuming as you might think, as we use IN to boost it up
			while ($sqlPart) {
				$cond = array(
					'flowthread_pageid' => $this->pageid,
					'flowthread_parentid IN(' . $sqlPart . ')',
				);
				switch ($this->filter) {
				case static::FILTER_ALL:
					break;
				// Other cases shouldn't match
				default:
					$cond['flowthread_status'] = Post::STATUS_NORMAL;
					break;
				}

				$res = $dbr->select('FlowThread', Post::getRequiredColumns(), $cond);

				$sqlPart = '';

				foreach ($res as $row) {
					$post = Post::newFromDatabaseRow($row);
					if ($post->parentid) {
						$post->parent = $parentLookup[$post->parentid->getBin()];
					}

					$comments[] = $post;
					$parentLookup[$post->id->getBin()] = $post;

					// Build SQL Statement for children query
					if ($sqlPart) {
						$sqlPart .= ',';
					}
					$sqlPart .= $dbr->addQuotes($post->id->getBin());
				}
			}
		}

		$this->posts = $comments;
	}

	public function erase() {
		global $wgTriggerFlowThreadHooks;
		$wgTriggerFlowThreadHooks = false;

		$dbw = wfGetDB(DB_MASTER);
		foreach ($this->posts as $post) {
			if ($post->isValid()) {
				$post->eraseSilently($dbw);
			}
		}
		$this->posts = array();
	}

}
