<?php
class FlowThreadPage {
	public $pageid = 0;
	public $posts = null;
	
	public function __construct( $listOfPost ) {
		$this->posts = $listOfPost;
	}

	public static function newFromId( $id ) {
		$dbr = wfGetDB( DB_SLAVE );

		// Invalid ID
		if (!is_numeric($id) || $id == 0) {
			throw new Exception("Invalid ID");
		}

		$res = $dbr->select('FlowThread', array(
            'flowthread_id',
            'flowthread_userid',
            'flowthread_username',
            'flowthread_text',
            'flowthread_timestamp',
            'flowthread_parentid',
            'flowthread_status'
        ) , array(
            'flowthread_pageid' => $id
        ));

		$comments = array();
		$lookup = array();
        
        foreach ($res as $row) {
            $data = array(
        		'id' => intval($row->flowthread_id),
        		'pageid' => $id,
        		'userid' => intval($row->flowthread_userid),
        		'username' => $row->flowthread_username,
        		'text' => $row->flowthread_text,
        		'timestamp' => $row->flowthread_timestamp,
        		'parentid' => intval($row->flowthread_parentid),
        		'status' => intval($row->flowthread_status)
        	);

            $post = new FlowThreadPost($data);
            $comments[] = $post;
            $lookup[$row->flowthread_id] = $post;
        }

        foreach ($comments as $post) {
        	if($post->parentid !== 0)
        		$post->parent = $lookup[$post->parentid];
        }

        return new FlowThreadPage($comments);
	}

}
