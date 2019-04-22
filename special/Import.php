<?php
namespace FlowThread;

class SpecialImport extends \FormSpecialPage {

	public function __construct() {
		parent::__construct('FlowThreadImport', 'commentadmin');
	}

	public function execute($par) {
		$user = $this->getUser();
		if (!$this->userCanExecute($user)) {
			throw new \PermissionsError('commentadmin');
		}
		parent::execute($par);
	}

	public function onSubmit(array $data, \HTMLForm $form = null) {
		// Get uploaded file
		$upload = &$_FILES['wpjsonimport'];

		// Check to make sure there is a file uploaded
		if ($upload === null || !$upload['name']) {
			return \Status::newFatal('importnofile');
		}

		// Messages borrowed from Special:Import
		if (!empty($upload['error'])) {
			switch ($upload['error']) {
			case 1:
			case 2:
				return \Status::newFatal('importuploaderrorsize');
			case 3:
				return \Status::newFatal('importuploaderrorpartial');
			case 4:
				return \Status::newFatal('importuploaderrortemp');
			default:
				return \Status::newFatal('importnofile');
			}
		}

		// Read file
		$fname = $upload['tmp_name'];
		if (!is_uploaded_file($fname)) {
			return \Status::newFatal('importnofile');
		}

		$data = \FormatJSON::parse(file_get_contents($fname));

		// If there is an error during JSON parsing, abort
		if (!$data->isOK()) {
			return $data;
		}

		$this->doImport($data->getValue());
	}

	private function doImport(array $json) {
		global $wgTriggerFlowThreadHooks;
		$wgTriggerFlowThreadHooks = false;

		$output = $this->getOutput();
		foreach ($json as $articles) {
			$title = \Title::newFromText($articles->title);
			$count = count($articles->posts);
			$skipped = 0;

			// Skip non-existent title
			if ($title === null || !$title->exists()) {
				$output->addWikiMsg('flowthreadimport-failed', $articles->title, $count);
				continue;
			}

			$titleId = $title->getArticleID();

			foreach ($articles->posts as $postJson) {
				$data = array(
					'id' => UID::fromHex($postJson->id),
					'pageid' => $titleId,
					'userid' => $postJson->userid,
					'username' => $postJson->username,
					'text' => $postJson->text,
					'parentid' => $postJson->parentid ? UID::fromHex($postJson->parentid) : null,
					'status' => $postJson->status,
					'like' => 0,
					'report' => 0,
				);
				$postObject = new Post($data);
				try {
					$postObject->post();
				} catch (\Exception $ex) {
					$count--;
					$skipped++;
				}
			}

			if ($count) {
				$logEntry = new \ManualLogEntry('comments', 'import');
				$logEntry->setPerformer($this->getUser());
				$logEntry->setTarget($title);
				$logEntry->setParameters(array(
					'4::count' => $count,
				));
				$logId = $logEntry->insert();
				$logEntry->publish($logId, 'udp');
				$output->addWikiMsg('flowthreadimport-success', $articles->title, $count);
			}
			if ($skipped) {
				$output->addWikiMsg('flowthreadimport-skipped', $articles->title, $skipped);
			}
		}
	}

	protected function alterForm(\HTMLForm $form) {
		$form->setSubmitTextMsg('uploadbtn');
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
}
