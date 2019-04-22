<?php
namespace FlowThread;

class EchoPresentationModel extends \EchoEventPresentationModel {

	protected $post = false;

	protected function load() {
		if ($this->post === false) {
			try {
				$this->post = Post::newFromId(UID::fromBin($this->event->getExtraParam('postid')));
			} catch (\Exception $e) {
				$this->post = null;
			}
		}
		return $this->post;
	}

	protected function htmlToText($html) {
		return trim(html_entity_decode(strip_tags($html)));
	}

	protected function getTruncatedBody($text) {
		return $this->language->embedBidi($this->language->truncate($text, 150, '...', false));
	}

	public function canRender() {
		return (bool) $this->event->getTitle();
	}

	public function getIconType() {
		switch ($this->type) {
		case 'flowthread_reply':
			return 'chat';
		case 'flowthread_userpage':
			return 'edit-user-talk';
		case 'flowthread_mention':
			return 'mention';
		}
		return 'chat';
	}

	public function getHeaderMessage() {
		$msg = parent::getHeaderMessage();
		$msg->params($this->getTruncatedTitleText($this->event->getTitle(), true));
		$msg->params($this->getViewingUserForGender());
		return $msg;
	}

	public function getBodyMessage() {
		$this->load();
		if ($this->post) {
			$msg = $this->msg('notification-body-flowthread');
			$msg->plaintextParams($this->getTruncatedBody($this->htmlToText($this->post->text)));
			return $msg;
		} else {
			return $this->msg('notification-body-flowthread-error');
		}
	}

	public function getPrimaryLink() {
		$this->load();
		if ($this->post && $this->post->isVisible()) {
			$msg = $this->msg('notification-link-text-view-' . $this->type);
			if (!$msg->exists()) {
				$msg = $this->msg('notification-link-text-view-flowthread-post');
			}
			return [
				'url' => \SpecialPage::getTitleFor('FlowThreadLink', $this->post->id->getHex())->getLocalURL(),
				'label' => $msg->text(),
			];
		} else {
			return [
				'url' => $this->event->getTitle()->getLocalURL(),
				'label' => $this->msg('notification-link-text-view-flowthread-page')->text(),
			];
		}
	}

	public function getSecondaryLinks() {
		return [$this->getAgentLink()];
	}

}
