<?php
namespace FlowThread;

class Post {
	const STATUS_NORMAL = 0;
	const STATUS_DELETED = 1;
	const STATUS_SPAM = 2;

	const ATTITUDE_NORMAL = 0;
	const ATTITUDE_LIKE = 1;
	const ATTITUDE_REPORT = 2;

	/**
	 * @var UID
	 */
	public $id = null;

	/**
	 * @var UID
	 */
	public $parentid = null;

	public $pageid = 0;
	public $userid = 0;
	public $username = '';
	public $text = null;
	public $status = 0;

	public $favorCount = 0;
	public $reportCount = 0;

	public $parent = null; // LAZY

	public function __construct(array $data) {
		$this->id = $data['id'];
		$this->pageid = $data['pageid'];
		$this->userid = $data['userid'];
		$this->username = $data['username'];
		$this->text = $data['text'];
		$this->parentid = $data['parentid'];
		$this->status = $data['status'];
		$this->favorCount = $data['like'];
		$this->reportCount = $data['report'];
	}

	public static function getRequiredColumns() {
		return array(
			'flowthread_id',
			'flowthread_pageid',
			'flowthread_userid',
			'flowthread_username',
			'flowthread_text',
			'flowthread_parentid',
			'flowthread_status',
			'flowthread_like',
			'flowthread_report',
		);
	}

	public static function newFromDatabaseRow(\stdClass $row) {
		$id = UID::fromBin($row->flowthread_id);

		// This is either NULL or a binary UID
		$parentid = $row->flowthread_parentid;
		if ($parentid !== null) {
			$parentid = UID::fromBin($parentid);
		}

		$data = array(
			'id' => $id,
			'pageid' => intval($row->flowthread_pageid),
			'userid' => intval($row->flowthread_userid),
			'username' => $row->flowthread_username,
			'text' => $row->flowthread_text,
			'parentid' => $parentid,
			'status' => intval($row->flowthread_status),
			'like' => intval($row->flowthread_like),
			'report' => intval($row->flowthread_report),
		);

		return new self($data);
	}

	public static function newFromId(UID $id) {
		// Do not apply cache here, it will seriously slow down the application
		$dbr = wfGetDB(DB_SLAVE);

		$row = $dbr->selectRow('FlowThread',
			self::getRequiredColumns(),
			array('flowthread_id' => $id->getBin())
		);

		if ($row === false) {
			throw new \Exception("Invalid post id");
		}

		return self::newFromDatabaseRow($row);
	}

	/**
	 * Terminate the API execution for permission reason
	 */
	private static function diePermission() {
		throw new \Exception("Current user cannot perform comment the specified administration action");
	}

	/**
	 * Check if the user can perform basic administration action
	 *
	 * @param User $user
	 *   User who is acting the action
	 * @return
	 *   True if the user can performe admin
	 */
	private static function canPerformAdmin(\User $user) {
		return $user->isAllowed('commentadmin-restricted');
	}

	private static function checkIfAdmin(\User $user) {
		if (!$user->isAllowed('commentadmin-restricted')) {
			throw new \Exception("Current user cannot perform comment admin");
		}
	}

	private static function checkIfAdminFull(\User $user) {
		if (!$user->isAllowed('commentadmin')) {
			throw new \Exception("Current user cannot perform full comment admin");
		}
	}

	/**
	 * Check if the a page is one's user page or user subpage
	 *
	 * @param User $user
	 *   User who is acting the action
	 * @param Title $title
	 *   Page on which the action is acting
	 * @return
	 *   True if the page belongs to the user
	 */
	public static function userOwnsPage(\User $user, \Title $title) {
		if ($title->getNamespace() === NS_USER && $title->getRootText() === $user->getName()) {
			return true;
		}
		return false;
	}

	public static function canPost(\User $user) {
		/* Disallow blocked user to post */
		if ($user->isBlocked()) {
			return false;
		}
		/* User without comment right cannot post */
		if (!$user->isAllowed('comment')) {
			return false;
		}
		/* Prevent cross-site request forgeries */
		if (wfReadOnly()) {
			return false;
		}
		return true;
	}

	public static function checkIfCanPost(\User $user) {
		/* Disallow blocked user to post */
		if ($user->isBlocked()) {
			throw new \Exception('User blocked');
		}
		/* User without comment right cannot post */
		if (!$user->isAllowed('comment')) {
			throw new \Exception("Current user cannot post comment");
		}
		/* Prevent cross-site request forgeries */
		if (wfReadOnly()) {
			throw new \Exception("Site in readonly mode");
		}
	}

	private static function checkIfCanVote(\User $user) {
		self::checkIfCanPost($user);
		if ($user->getId() == 0) {
			throw new \Exception("Must login first");
		}
	}

	private function publishSimpleLog($subtype, \User $initiator) {
		$logEntry = new \ManualLogEntry('comments', $subtype);
		$logEntry->setPerformer($initiator);
		$logEntry->setTarget(\Title::newFromId($this->pageid));
		$logEntry->setParameters(array(
			'4::username' => $this->username,
		));
		$logId = $logEntry->insert();
		$logEntry->publish($logId, 'udp');
	}

	private function switchStatus($newStatus) {
		$dbw = wfGetDB(DB_MASTER);
		$dbw->update('FlowThread', array(
			'flowthread_status' => $newStatus,
		), array(
			'flowthread_id' => $this->id->getBin(),
		));
	}

	public function recover(\User $user) {
		self::checkIfAdmin($user);

		// Recover is invalid for a not-deleted post
		if (!$this->isDeleted()) {
			throw new \Exception("Post is not deleted");
		}

		// Mark status as normal
		$this->switchStatus(static::STATUS_NORMAL);

		// Write a log
		$this->publishSimpleLog('recover', $user);

		global $wgTriggerFlowThreadHooks;
		if ($wgTriggerFlowThreadHooks) {
			\Hooks::run('FlowThreadRecovered', array($this, $user));
		}
	}

	public function markchecked(\User $user) {
		self::checkIfAdminFull($user);

		// Mark-as-checked is invalid for a deleted post
		if ($this->isDeleted()) {
			throw new \Exception("Post is deleted");
		}

		// Write a log
		$this->publishSimpleLog('markchecked', $user);

		$this->reportCount = 0;
		$this->updateFavorReportCount();

		$db = wfGetDB(DB_MASTER);
		$db->delete('FlowThreadAttitude', array(
			'flowthread_att_id' => $this->id->getBin(),
			'flowthread_att_type' => self::ATTITUDE_REPORT,
		));
	}

	public function delete(\User $user) {
		// Poster himself can delete as well
		if ($user->getId() === 0 || $user->getId() !== $this->userid) {
			if (!self::canPerformAdmin($user) &&
				!self::userOwnsPage($user, \Title::newFromId($this->pageid))) {
				self::diePermission();
			}
		}

		// Delete is not valid for deleted post
		if ($this->isDeleted()) {
			throw new \Exception("Post is already deleted");
		}

		PopularPosts::invalidateCache($this);

		// Mark status as deleted
		$this->switchStatus(static::STATUS_DELETED);

		// Write a log
		$this->publishSimpleLog('delete', $user);

		global $wgTriggerFlowThreadHooks;
		if ($wgTriggerFlowThreadHooks) {
			\Hooks::run('FlowThreadDeleted', array($this, $user));
		}

	}

	// Recursively delete a thread and its children
	// FIXME: Should be somehow protected
	public function eraseSilently(\DatabaseBase $db) {
		$counter = 1;

		$db->delete('FlowThread', array(
			'flowthread_id' => $this->id->getBin(),
		));
		// We need to delete attitude as well to free up space
		$db->delete('FlowThreadAttitude', array(
			'flowthread_att_id' => $this->id->getBin(),
		));

		$children = $this->getChildren();
		foreach ($children as $post) {
			$counter += $post->eraseSilently($db);
		}

		return $counter;
	}

	public function erase(\User $user) {
		self::checkIfAdminFull($user);

		// To avoid mis-operation, a comment must be deleted (hidden from user) first before it is erased from database
		if (!$this->isDeleted()) {
			throw new \Exception("Post must be deleted first before erasing");
		}

		$dbw = wfGetDB(DB_MASTER);
		$counter = $this->eraseSilently($dbw);

		// Add to log
		$logEntry = new \ManualLogEntry('comments', 'erase');
		$logEntry->setPerformer($user);
		$logEntry->setTarget(\Title::newFromId($this->pageid));
		$logEntry->setParameters(array(
			'4::postid' => $this->username,
			'5::children' => $counter - 1,
		));
		$logId = $logEntry->insert();
		$logEntry->publish($logId, 'udp');

		$this->invalidate();
	}

	public function post() {
		$dbw = wfGetDB(DB_MASTER);
		if (!$this->id) {
			$this->id = UID::generate();
		}
		$dbw->insert('FlowThread', array(
			'flowthread_id' => $this->id->getBin(),
			'flowthread_pageid' => $this->pageid,
			'flowthread_userid' => $this->userid,
			'flowthread_username' => $this->username,
			'flowthread_text' => $this->text,
			'flowthread_parentid' => $this->parentid ? $this->parentid->getBin() : null,
			'flowthread_status' => $this->status,
			'flowthread_like' => $this->favorCount,
			'flowthread_report' => $this->reportCount,
		));

		global $wgTriggerFlowThreadHooks;
		if ($wgTriggerFlowThreadHooks) {
			\Hooks::run('FlowThreadPosted', array($this));
		}

	}

	public function isDeleted() {
		// This include spam and deleted
		return $this->status !== static::STATUS_NORMAL;
	}

	public function isVisible() {
		if ($this->isDeleted()) {
			return false;
		}
		if ($this->parentid === null) {
			return true;
		}
		return $this->getParent()->isVisible();
	}

	private function invalidate() {
		$this->id = null;
	}

	public function isValid() {
		// This can happen if code is continuing to operate on post after it is erased
		if ($this->id === null) {
			return false;
		}
		return true;
	}

	public function validate() {
		if (!$this->isValid()) {
			throw new \Exception("Invalid post");
		}
	}

	public function getParent() {
		if ($this->parentid === null) {
			return null;
		}
		if ($this->parent === null) {
			$this->parent = self::newFromId($this->parentid);
		}
		return $this->parent;
	}

	public function getNestLevel() {
		$level = 0;
		$item = $this->getParent();
		while ($item) {
			$item = $item->getParent();
			$level++;
		}
		return $level;
	}

	public function getTimestamp() {
		return $this->id->getTimestamp();
	}

	public function getChildren() {
		$this->validate();

		$dbr = wfGetDB(DB_SLAVE);

		$res = $dbr->select('FlowThread',
			self::getRequiredColumns(), array(
				'flowthread_pageid' => $this->pageid, # This line is critical in performance, as we indexed pageid
				'flowthread_parentid' => $this->id->getBin(),
			));

		$comments = array();

		foreach ($res as $row) {
			$post = self::newFromDatabaseRow($row);
			$comments[] = $post;
		}

		return $comments;
	}

	public function getFavorCount() {
		return $this->favorCount;
	}

	public function getReportCount() {
		return $this->reportCount;
	}

	public function getUserAttitude(\User $user) {
		$dbr = wfGetDB(DB_SLAVE);
		$row = $dbr->selectRow('FlowThreadAttitude', 'flowthread_att_type', array(
			'flowthread_att_id' => $this->id->getBin(),
			'flowthread_att_userid' => $user->getId(),
		));
		if ($row === false) {
			return static::ATTITUDE_NORMAL;
		} else {
			return intval($row->flowthread_att_type);
		}
	}

	private function updateFavorReportCount() {
		$dbw = wfGetDB(DB_MASTER);
		$dbw->update('FlowThread', array(
			'flowthread_like' => $this->favorCount,
			'flowthread_report' => $this->reportCount,
		), array(
			'flowthread_id' => $this->id->getBin(),
		));
	}

	public function setUserAttitude(\User $user, $att) {
		self::checkIfCanVote($user);

		$dbw = wfGetDB(DB_MASTER);

		// Get current attitude
		$oldatt = $this->getUserAttitude($user);

		// Short path, return if they match
		if ($oldatt === $att) {
			return;
		}

		// Delete entry if the attitude is neutral
		if ($att === static::ATTITUDE_NORMAL) {
			$dbw->delete('FlowThreadAttitude', array(
				'flowthread_att_id' => $this->id->getBin(),
				'flowthread_att_userid' => $user->getId(),
			));
		} else if ($oldatt !== static::ATTITUDE_NORMAL) {
			$dbw->update('FlowThreadAttitude', array(
				'flowthread_att_type' => $att,
			), array(
				'flowthread_att_id' => $this->id->getBin(),
				'flowthread_att_userid' => $user->getId(),
			));
		} else {
			$dbw->insert('FlowThreadAttitude', array(
				'flowthread_att_id' => $this->id->getBin(),
				'flowthread_att_type' => $att,
				'flowthread_att_userid' => $user->getId(),
			));
		}

		// Update number in the main table
		if ($oldatt === static::ATTITUDE_LIKE) {
			$this->favorCount--;
		} else if ($oldatt === static::ATTITUDE_REPORT) {
			$this->reportCount--;
		}
		if ($att === static::ATTITUDE_LIKE) {
			$this->favorCount++;
		} else if ($att === static::ATTITUDE_REPORT) {
			$this->reportCount++;
		}
		$this->updateFavorReportCount();
	}

}
