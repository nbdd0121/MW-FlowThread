<?php
namespace FlowThread;
class Page {
	public $pageid = 0;
	public $totalCount = 0;
	public $offset = 0;
	public $limit = 10;
	public $posts = null;
	public $type = null;

	public function __construct($id) {
		// Invalid ID
		if (!is_numeric($id) || $id == 0) {
			throw new Exception("Invalid ID");
		}

		$this->pageid = $id;
	}

	public function fetch() {
		$dbr = wfGetDB(DB_SLAVE);

		$comments = array();
		$parentLookup = array();

		$options = array(
			'OFFSET' => $this->offset,
			'ORDER BY' => 'flowthread_id DESC',
			'SQL_CALC_FOUND_ROWS',
		);
		if ($this->limit !== -1) {
			$options['LIMIT'] = $this->limit;
		}

		$cond = array(
			'flowthread_pageid' => $this->pageid,
			'flowthread_parentid IS NULL',
		);
		if ($this->type !== null) {
			$cond['flowthread_status'] = $this->type;
		}

		// Get all root posts
		$res = $dbr->select('FlowThread', Post::getRequiredColumns(),
			$cond, __METHOD__, $options);

		$this->totalCount = $dbr->query('select FOUND_ROWS() as row')->fetchObject()->row;

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

		// Recursively get all children post list
		// This is not really resource consuming as you might think, as we use IN to boost it up
		while ($sqlPart) {
			$cond = array(
				'flowthread_pageid' => $this->pageid,
				'flowthread_parentid IN(' . $sqlPart . ')',
			);
			if ($this->type !== null) {
				$cond['flowthread_status'] = $this->type;
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

		$this->posts = $comments;
	}

	public function erase() {
		$dbw = wfGetDB(DB_MASTER);
		foreach ($this->posts as $post) {
			if ($post->isValid()) {
				$post->eraseSilently($dbw);
			}
		}
		$this->posts = array();
	}

}
