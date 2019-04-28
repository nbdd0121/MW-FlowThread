<?php
namespace FlowThread;

class SpecialControl extends \FormSpecialPage {

	const STATUS_ENABLED = 0;
	const STATUS_OPTEDOUT = 1;
	const STATUS_DISABLED = 2;

	protected $title;
	protected $isAdmin;
	protected $ownsPage;

	protected $currentStatus;

	public function __construct() {
		parent::__construct('FlowThreadControl', '', false);
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $par ) {
		parent::execute( $par );

		$this->getSkin()->setRelevantTitle( $this->title );
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'flowthreadcontrol', $this->title->getPrefixedText() ) );
	}

	protected function setParameter( $par ) {
		$title = \Title::newFromText( $par );
		$this->title = $title;

		if ( !$title ) {
			throw new \ErrorPageError( 'notargettitle', 'notargettext' );
		}
		if ( !$title->exists() ) {
			throw new \ErrorPageError( 'nopagetitle', 'nopagetext' );
		}

		if ( !Helper::canEverPostOnTitle($title) ) {
			// XXX: Should be some different text, but I'm lazy
			throw new \ErrorPageError( 'notargettitle', 'notargettext' );
		}

		$status = self::getControlStatus($title);
		$this->currentStatus = $status;

		$isAdmin = $this->getUser()->isAllowed('commentadmin');
		$this->isAdmin = $isAdmin;
		$ownsPage = Post::userOwnsPage($this->getUser(), $title);
		$this->ownsPage = $ownsPage;

		// Access granted only if: is comment admin, or the user owns the page and the page is not disabled
		// by admin.
		if (!$isAdmin && (!$ownsPage || $status === self::STATUS_DISABLED)) {
			throw new \PermissionsError('commentadmin');
		}
	}

	protected function getFormFields() {
		$fields = [];

		if ($this->currentStatus === self::STATUS_ENABLED) {
			$fields['Reason'] = [
				'type' => 'selectandother',
				'maxlength' => \CommentStore::COMMENT_CHARACTER_LIMIT,
				'maxlength-unit' => 'codepoints',
				'options-message' => 'flowthreadcontrol-disable-reason-dropdown',
				'label-message' => 'flowthreadcontrol-disable-reason',
			];

			if (!$this->ownsPage && $this->title->getNamespace() === NS_USER) {
				$fields['AllowOptin'] = [
					'type' => 'check',
					'label-message' => 'flowthreadcontrol-allow-optin',
					'default' => true,
				];
			} else {
				$fields['AllowOptin'] = [
					'type' => 'hidden',
					'default' => false,
				];
			}
		} else {
			$fields['Reason'] = [
				'type' => 'text',
				'maxlength' => \CommentStore::COMMENT_CHARACTER_LIMIT,
				'maxlength-unit' => 'codepoints',
				'label-message' => 'flowthreadcontrol-enable-reason',
			];
		}

		$fields['CurrentStatus'] = [
			'type' => 'hidden',
			'default' => $this->currentStatus,
		];

		return $fields;
	}

	protected function alterForm( \HTMLForm $form ) {
		$form->setHeaderText('');
		if ($this->currentStatus === self::STATUS_ENABLED) {
			$form->setSubmitDestructive();
			$form->setSubmitTextMsg( $this->ownsPage ? 'flowthreadcontrol-optout' : 'flowthreadcontrol-disable' );
		} else {
			$form->setSubmitTextMsg( 'flowthreadcontrol-enable' );
		}
	}

	public function onSubmit(array $data, \HTMLForm $form = null ) {
		$hiddenStatus = intval($data['CurrentStatus']);
		if ($this->currentStatus !== $hiddenStatus) {
			$this->currentStatus = $hiddenStatus;
			return true;
		}

		$disable = $this->currentStatus === self::STATUS_ENABLED;
		if ($disable) {
			if ($this->ownsPage || $data['AllowOptin']) {
				$target = self::STATUS_OPTEDOUT;
			} else {
				$target = self::STATUS_DISABLED;
			}
		} else {
			$target = self::STATUS_ENABLED;
		}
		self::setControlStatus($this->title, $target);

		$context = $form->getContext();
		$performer = $context->getUser();
		$reason = $data['Reason'];
		if (isset($reason[0])){
			$reason = $reason[0];
		}
		$logEntry = new \ManualLogEntry('comments', $disable ? 'disable' : 'enable');
		$logEntry->setPerformer($performer);
		$logEntry->setTarget($this->title);
		$logEntry->setComment($reason);
		$logId = $logEntry->insert();
		$logEntry->publish($logId, 'udp');

		return true;
	}

	public function onSuccess() {
		$out = $this->getOutput();

		if ($this->currentStatus === self::STATUS_ENABLED) {
			$out->addWikiMsg( 'flowthreadcontrol-disable-success', wfEscapeWikiText( $this->title ) );
		} else {
			$out->addWikiMsg( 'flowthreadcontrol-enable-success', wfEscapeWikiText( $this->title ) );
		}
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	protected function postText() {
		$links = [];

		if ( $this->getUser()->isAllowed( 'editinterface' ) ) {
			$linkRenderer = $this->getLinkRenderer();
			$links[] = $linkRenderer->makeKnownLink(
				$this->msg( 'flowthreadcontrol-disable-reason-dropdown' )->inContentLanguage()->getTitle(),
				$this->msg( 'flowthreadcontrol-edit-disable-dropdown' )->text(),
				[],
				[ 'action' => 'edit' ]
			);
		}

		$text = \Html::rawElement(
			'p',
			[ 'class' => 'mw-protect-editreasons' ],
			$this->getLanguage()->pipeList( $links )
		);

		# Get relevant extracts from the block and suppression logs, if possible
		$out = '';
		\LogEventsList::showLogExtract(
			$out,
			'comments',
			$this->title->getPrefixedDBKey(),
			'',
			[
				'lim' => 10,
				'msgKey' => [ 'flowthreadcontrol-showlog' ],
				'showIfEmpty' => false
			]
		);
		return $text . $out;
	}

	public static function getControlStatus(\Title $title) {
		$id = $title->getArticleID();

		$dbr = wfGetDB(DB_SLAVE);
		$row = $dbr->selectRow('FlowThreadControl', ['flowthread_ctrl_status'], [
			'flowthread_ctrl_pageid' => $title->getArticleID(),
		]);

		if ($row === false) return self::STATUS_ENABLED;
		return intval($row->flowthread_ctrl_status);
	}

	public static function setControlStatus(\Title $title, $status) {
		$id = $title->getArticleID();
		$dbw = wfGetDB(DB_MASTER);
		if ($status === self::STATUS_ENABLED) {
			$dbw->delete('FlowThreadControl', [
				'flowthread_ctrl_pageid' => $id,
			]);
		} else {
			$values = [
				'flowthread_ctrl_pageid' => $id,
				'flowthread_ctrl_status' => $status,
			];
			$dbw->upsert('FlowThreadControl', $values, [
				'flowthread_ctrl_pageid' => $id,
			], $values);
		}
	}

}
