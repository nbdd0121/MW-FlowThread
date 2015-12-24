<?php

class SpecialFlowThreadExport extends SpecialPage {

	public function __construct() {
		parent::__construct( 'FlowThreadExport', 'commentadmin-restricted');
	}

	public function execute( $par ) {
		$user = $this->getUser();
		if (!$this->userCanExecute($user)) {
			throw new PermissionsError( 'commentadmin-restricted' );
		}

		$request = $this->getRequest();
		$output = $this->getOutput();
		$config = $this->getConfig();
		$this->setHeaders();

		$doExport = false;

		if($request->wasPosted()) {
			$doExport = true;
		}

		if($doExport) {
			// No default output, we are printing raw data now
			$this->getOutput()->disable();

			// No buffering, otherwise output will consume too much memory
			wfResetOutputBuffers();
			$request->response()->header( "Content-type: application/json; charset=utf-8" );

			// Got headers ready so browser triggers a download
			$filename = urlencode( $config->get( 'Sitename' ) . '-' . wfTimestampNow() . '-flowthread.json' );
			$request->response()->header( "Content-disposition: attachment;filename={$filename}" );

			// Got all data. NOTE that ORDER BY is essential since we are grouping comments
			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select('FlowThread', array(
	            'flowthread_id',
	            'flowthread_pageid',
	            'flowthread_userid',
	            'flowthread_username',
	            'flowthread_text',
	            'flowthread_timestamp',
	            'flowthread_parentid',
	            'flowthread_status'
	        ) , array(), __METHOD__, array('ORDER BY flowthread_pageid'));

			$pageid = 0;

			echo "[";
	        foreach($res as $row) {
	        	$post = FlowThreadPost::newFromDatabaseRow($row);

	        	if($post->pageid != $pageid) {
	        		if($pageid != 0) {
	        			echo "\n]},";
	        		}
	        		$pageid = $post->pageid;
	        		$title = Title::newFromId($pageid);
	        		$title = FormatJSON::encode($title ? $title->getPrefixedText() : '');
	        		echo "{\"title\":{$title}, \"posts\":[\n";
	        		$first = true;
	        	}

	        	if($first) {
	        		$first = false;
	        	} else {
	        		echo ",\n";
	        	}

	        	$username = FormatJSON::encode($post->username);
	        	$text = FormatJSON::encode($post->text);
	        	echo "  {\"id\":{$post->id}, \"userid\":{$post->userid}, \"username\":{$username}, \"text\":{$text}, \"timestamp\":{$post->timestamp}, \"parentid\":{$post->parentid}, \"status\":{$post->status}}";
	        }
	        echo "\n]}]";

			return;
		}else{
			$formDescriptor = array();

			$htmlForm = HTMLForm::factory( 'div', $formDescriptor, $this->getContext(), 'flowthread_export_form' );

			$htmlForm->setSubmitTextMsg( 'flowthreadexport-submit' );
			$htmlForm->show();
		}

	}


	protected function getGroupName() {
		return 'pagetools';
	}
}
