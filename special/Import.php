<?php

class SpecialFlowThreadImport extends FormSpecialPage {

	public function __construct() {
		parent::__construct('FlowThreadImport', 'commentadmin');
	}

	public function execute( $par ) {
		$user = $this->getUser();
		if ( !$user->isAllowedAny( 'commentadmin' ) ) {
			throw new PermissionsError( 'commentadmin' );
		}
		parent::execute($par);
	}

	public function onSubmit( array $data, HTMLForm $form = null ) {
		// Get uploaded file
		$upload =& $_FILES['wpjsonimport'];
 
 		// Check to make sure there is a file uploaded
		if ( $upload === null || !$upload['name'] ) {
			return Status::newFatal('importnofile');
        }

        // Messages borrowed from Special:Import
        if ( !empty( $upload['error'] ) ) {
        	switch ( $upload['error'] ) {
 				case 1:
 				case 2:
 					return Status::newFatal( 'importuploaderrorsize' );
 				case 3:
 					return Status::newFatal( 'importuploaderrorpartial' );
 				case 4:
 					return Status::newFatal( 'importuploaderrortemp' );
 				default:
 					return Status::newFatal('importnofile');
 			}
        }

        // Read file
 		$fname = $upload['tmp_name'];
 		if ( !is_uploaded_file( $fname ) ) {
 			return Status::newFatal('importnofile');
 		}

 		$data = FormatJSON::parse(file_get_contents($fname));

 		// If there is an error during JSON parsing, abort
 		if(!$data->isOK()) {
 			return $data;
 		}

 		$this->doImport($data->getValue());
	}

	private function doImport( array $json ) {
		$output = $this->getOutput();
		foreach($json as $articles) {
			$title = Title::newFromText($articles->title);
			$count = count($articles->posts);

			// Skip non-existent title
			if($title === null || !$title->exists()) {
				$output->addWikitext("* Title [[{$articles->title}]] skipped as it does not exist, {$count} comments not imported\n");
				continue;
			}

			$titleId = $title->getArticleID();

			$output->addWikitext("* {$count} comments imported for page [[{$articles->title}]]\n");

			foreach($articles->posts as $postJson) {
				$data = array(
                    'id' => 0,
                    'pageid' => $titleId,
                    'userid' => $postJson->userid,
                    'username' => $postJson->username,
                    'text' => $postJson->text,
                    'timestamp' => wfTimestamp($postJson->timestamp, TS_MW),
                    'parentid' => 0,
                    'status' => $postJson->status
                );
                $postObject = new FlowThreadPost($data);
                $postObject->post();
			}
		}
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'uploadbtn' );
	}

	protected function getFormFields() {
		$formDescriptor = array(
			'jsonimport' => array(
				'class' => 'HTMLTextField',
				'type' => 'file',
				'label-message' => 'import-upload-filename',
			),
		);
		return $formDescriptor;
	}

	protected function getGroupName() {
		return 'pagetools';
	}
}
