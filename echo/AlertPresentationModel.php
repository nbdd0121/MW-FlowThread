<?php
namespace FlowThread;

class EchoAlertPresentationModel extends EchoPresentationModel {

	public function getIconType() {
		switch ($this->type) {
		case 'flowthread_delete':
			return 'revert';
		case 'flowthread_recover':
			return 'reviewed';
		case 'flowthread_spam':
			return 'flowthread-delete';
		}
		return 'chat';
	}

	public function getBodyMessage() {
		$this->load();
		if ($this->post) {
			$msg = $this->msg('notification-body-flowthread');
			$msg->plaintextParams($this->getTruncatedBody($this->htmlToText($this->post->text)));
			return $msg;
		} else {
			return false;
		}
	}

	public function getSecondaryLinks() {
		return [];
	}
}
