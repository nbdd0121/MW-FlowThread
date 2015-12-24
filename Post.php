<?php
class FlowThreadPost {
	const FLOW_THREAD_STATUS_NORMAL = 0;
	const FLOW_THREAD_STATUS_DELETED = 1;
	const FLOW_THREAD_ATT_NORM = 0;
	const FLOW_THREAD_ATT_LIKE = 1;
	const FLOW_THREAD_ATT_REPO = 2;

	public $pageid = 0;
	public $id = 0;
	public $userid = 0;
	public $username = '';
	public $text = null;
	public $timestamp = null;
	public $parentid = 0;
	public $status = 0;

	public $favorCount = -1;
	public $parent = null; // LAZY

	public function __construct( $data ) {
		$this->id = $data['id'];
		$this->pageid = $data['pageid'];
		$this->userid = $data['userid'];
		$this->username = $data['username'];
		$this->text = $data['text'];
		$this->timestamp = $data['timestamp'];
		$this->parentid = $data['parentid'];
		$this->status = $data['status'];
	}

	public static function newFromId( $id ) {
		$dbr = wfGetDB( DB_SLAVE );

		// Invalid ID
		if (!is_numeric($id) || $id == 0) {
			throw new Exception("Invalid ID");
		}

		$row = $dbr->selectRow('FlowThread', array(
            'flowthread_pageid',
            'flowthread_userid',
            'flowthread_username',
            'flowthread_text',
            'flowthread_timestamp',
            'flowthread_parentid',
            'flowthread_status'
        ) , array(
            'flowthread_id' => $id
        ));

		if($row === false){
			return null;
		}

        $data = array(
        	'id' => intval($id),
        	'pageid' => intval($row->flowthread_pageid),
        	'userid' => intval($row->flowthread_userid),
        	'username' => $row->flowthread_username,
        	'text' => $row->flowthread_text,
        	'timestamp' => $row->flowthread_timestamp,
        	'parentid' => intval($row->flowthread_parentid),
        	'status' => intval($row->flowthread_status)
        );

        return new FlowThreadPost($data);
	}

	public static function newFromDatabaseRow( $row ) {
		$data = array(
    		'id' => intval($row->flowthread_id),
    		'pageid' => intval($row->flowthread_pageid),
    		'userid' => intval($row->flowthread_userid),
    		'username' => $row->flowthread_username,
    		'text' => $row->flowthread_text,
    		'timestamp' => $row->flowthread_timestamp,
    		'parentid' => intval($row->flowthread_parentid),
    		'status' => intval($row->flowthread_status)
    	);

        return new FlowThreadPost($data);
	}

	private static function checkIfAdmin(User $user) {
		if(!$user->isAllowed('commentadmin-restricted')) {
			throw new Exception("Current user cannot perform comment admin");
		}
	}

	private static function checkIfAdminFull(User $user) {
		if(!$user->isAllowed('commentadmin')) {
			throw new Exception("Current user cannot perform full comment admin");
		}
	}

	public static function checkIfCanPost(User $user) {
        /* Disallow blocked user to post */
        if ($user->isBlocked()) {
            throw new Exception('User blocked');
        }
        /* User without comment right cannot post */
        if (!$user->isAllowed('comment')) {
            throw new Exception("Current user cannot post comment");
        }
        /* Prevent cross-site request forgeries */
        if (wfReadOnly()) {
            throw new Exception("csrf");
        }
	}

	private static function checkIfCanVote(User $user) {
        FlowThreadPost::checkIfCanPost($user);
        if($user->getId() == 0){
            throw new Exception("Must login first");
        }
	}

	public function recover(User $user) {
		FlowThreadPost::checkIfAdmin($user);

		// Recover is invalid for a not-deleted post
		if(!$this->isDeleted()) {
			throw new Exception("Post is not deleted");
		}

		$dbw = wfGetDB( DB_MASTER );

		$data = array(
            'flowthread_status' => FlowThreadPost::FLOW_THREAD_STATUS_NORMAL,
        );

		$dbw->update('FlowThread', $data, array(
            'flowthread_id' => $this->id
        ));

        $dbw->commit();

        $logEntry = new ManualLogEntry( 'comments', 'recover' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( Title::newFromId( $this->pageid ) );
		$logEntry->setParameters( array(
			'4::postid' => $this->username
		) );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId, 'udp' );
	}

	public function delete(User $user) {
		FlowThreadPost::checkIfAdmin($user);

		// Delete is not valid for deleted post
		if($this->isDeleted()) {
			throw new Exception("Post is already deleted");
		}

		$dbw = wfGetDB( DB_MASTER );

		$data = array(
            'flowthread_status' => FlowThreadPost::FLOW_THREAD_STATUS_DELETED,
        );

		$dbw->update('FlowThread', $data, array(
            'flowthread_id' => $this->id
        ));

        $dbw->commit();

        $logEntry = new ManualLogEntry( 'comments', 'delete' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( Title::newFromId( $this->pageid ) );
		$logEntry->setParameters( array(
			'4::postid' => $this->username
		) );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId, 'udp' );
	}

	private function eraseSilently(DatabaseBase $db) {
		$counter = 1;

		$db->delete('FlowThread', array(
			'flowthread_id' => $this->id
		));

		$children = $this->getChildren();
		foreach($children as $post) {
			$counter += $post->eraseSilently($db);
		}

		return $counter;
	}

	public function erase(User $user) {
		FlowThreadPost::checkIfAdminFull($user);

		// To avoid mis-operation, a comment must be deleted (hidden from user) first before it is erased from database
		if(!$this->isDeleted()){
			throw new Exception("Post must be deleted first before erasing");
		}

		$dbw = wfGetDB(DB_MASTER);
		$counter = $this->eraseSilently($dbw);
		$dbw->commit();

		// Add to log
		$logEntry = new ManualLogEntry( 'comments', 'erase' );
		$logEntry->setPerformer( $user );
		$logEntry->setTarget( Title::newFromId( $this->pageid ) );
		$logEntry->setParameters( array(
			'4::postid' => $this->username,
			'5::children' => $counter
		) );
		$logId = $logEntry->insert();
		$logEntry->publish( $logId, 'udp' );

		$this->invalidate();
	}

	public function post() {
		$dbw = wfGetDB(DB_MASTER);
		$dbw->insert('FlowThread', array(
            'flowthread_pageid' => $this->pageid,
            'flowthread_userid' => $this->userid,
            'flowthread_username' => $this->username ,
            'flowthread_text' => $this->text,
            'flowthread_timestamp' => $this->timestamp,
            'flowthread_parentid' => $this->parentid,
            'flowthread_status' => FlowThreadPost::FLOW_THREAD_STATUS_NORMAL
        ));
        $dbw->commit();
	}


	public function isDeleted() {
		return $this->status === FlowThreadPost::FLOW_THREAD_STATUS_DELETED;
	}

	public function isVisible() {
		if($this->isDeleted()) {
			return false;
		}
		if($this->parentid === 0) {
			return true;
		}
		return $this->getParent()->isVisible();
	}

	private function invalidate() {
		$this->id = 0;
	}

	private function validate() {
		// This can happen if code is continuing to operate on post after it is erased
		if($this->id === 0) {
			throw new Exception("Post is invalid");
		}
	}

	public function getParent() {
		if($this->parentid === 0) {
			return null;
		}
		if($this->parent === null) {
			$this->parent = FlowThreadPost::newFromId($this->parentid);
		}
		return $this->parent;
	}

	public function getChildren() {
		$this->validate();

		$dbr = wfGetDB( DB_SLAVE );

		$res = $dbr->select('FlowThread', array(
            'flowthread_id',
            'flowthread_pageid',
            'flowthread_userid',
            'flowthread_username',
            'flowthread_text',
            'flowthread_timestamp',
            'flowthread_status'
        ) , array(
            'flowthread_parentid' => $this->id
        ));

		$comments = array();
		
        foreach ($res as $row) {
            $data = array(
        		'id' => intval($row->flowthread_id),
        		'pageid' => intval($row->flowthread_pageid),
        		'userid' => intval($row->flowthread_userid),
        		'username' => $row->flowthread_username,
        		'text' => $row->flowthread_text,
        		'timestamp' => $row->flowthread_timestamp,
        		'parentid' => $this->id,
        		'status' => intval($row->flowthread_status)
        	);

            $post = new FlowThreadPost($data);
            $comments[] = $post;
        }

       	return $comments;
	}

	public function getFavorCount() {
		if($this->favorCount === -1) {
			$dbr = wfGetDB(DB_SLAVE);
			$this->favorCount = $dbr->selectRowCount('FlowThreadAttitude', '*', array(
	            'flowthread_att_id' => $this->id,
	            'flowthread_att_type' => FLOW_THREAD_ATT_LIKE
	        ));
	    }
	    return $this->favorCount;
	}

	public function getUserAttitude(User $user) {
		$dbr = wfGetDB(DB_SLAVE);
		$row = $dbr->selectRow('FlowThreadAttitude', 'flowthread_att_type', array(
            'flowthread_att_id' => $this->id,
            'flowthread_att_userid' => $user->getId()
        ));
        if ($row === false) {
            return FlowThreadPost::FLOW_THREAD_ATT_NORM;
        } else {
            return intval($row->flowthread_att_type);
        }
	}

	public function setUserAttitude(User $user, $att) {		
		FlowThreadPost::checkIfCanVote($user);

		$dbw = wfGetDB(DB_MASTER);
        
        // Get current attitude
        $oldatt = $this->getUserAttitude($user);
        
        // Short path, return if they match
        if ($oldatt === $att) return;
        
        // Delete entry if the attitude is neutral
        if ($att === FlowThreadPost::FLOW_THREAD_ATT_NORM) {
            $dbw->delete('FlowThreadAttitude', array(
                'flowthread_att_id' => $this->id,
                'flowthread_att_userid' => $user->getId()
            ));
            return;
        }
        
        $time = wfTimestamp(TS_MW);
        
        $data = array(
            'flowthread_att_id' => $this->id,
            'flowthread_att_type' => $att,
            'flowthread_att_userid' => $user->getId(),
            'flowthread_att_username' => $user->getName() ,
            'flowthread_att_timestamp' => $time
        );
        
        if ($oldatt !== FlowThreadPost::FLOW_THREAD_ATT_NORM) {
            $dbw->update('FlowThreadAttitude', $data, array(
                'flowthread_att_id' => $this->id,
                'flowthread_att_userid' => $user->getId()
            ));
        } 
        else {
            $dbw->insert('FlowThreadAttitude', $data);
        }

        $dbw->commit();
	}

}
