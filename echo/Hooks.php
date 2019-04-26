<?php
namespace FlowThread;

class EchoHooks {

	public static function onBeforeCreateEchoEvent(&$notifications, &$notificationCategories, &$icons) {
		$icons += array(
			'flowthread-delete' => array(
				'path' => 'FlowThread/assets/delete.svg'
			),
		);

		$notificationCategories['flowthread'] = array(
			'priority' => 4,
			'tooltip' => 'echo-pref-tooltip-flowthread',
		);
		$notifications['flowthread_reply'] = array(
			'category' => 'flowthread',
			'group' => 'interactive',
			'section' => 'message',
			'presentation-model' => 'FlowThread\\EchoPresentationModel',
		);
		$notifications['flowthread_userpage'] = array(
			'category' => 'flowthread',
			'group' => 'interactive',
			'section' => 'message',
			'presentation-model' => 'FlowThread\\EchoPresentationModel',
		);
		$notifications['flowthread_mention'] = array(
			'category' => 'flowthread',
			'group' => 'interactive',
			'section' => 'message',
			'presentation-model' => 'FlowThread\\EchoPresentationModel',
		);
		$notifications['flowthread_delete'] = array(
			'user-locators' => array(
				'EchoUserLocator::locateEventAgent',
			),
			'category' => 'flowthread',
			'group' => 'negative',
			'section' => 'alert',
			'presentation-model' => 'FlowThread\\EchoAlertPresentationModel',
		);
		$notifications['flowthread_recover'] = array(
			'user-locators' => array(
				'EchoUserLocator::locateEventAgent',
			),
			'category' => 'flowthread',
			'group' => 'positive',
			'section' => 'alert',
			'presentation-model' => 'FlowThread\\EchoAlertPresentationModel',
		);
		$notifications['flowthread_spam'] = array(
			'user-locators' => array(
				'EchoUserLocator::locateEventAgent',
			),
			'category' => 'flowthread',
			'group' => 'negative',
			'section' => 'alert',
			'presentation-model' => 'FlowThread\\EchoAlertPresentationModel',
		);
		return true;
	}

	public static function onEchoGetDefaultNotifiedUsers($event, &$users) {
		switch ($event->getType()) {
		case 'flowthread_reply':
		case 'flowthread_mention':
		case 'flowthread_userpage':
			$extra = $event->getExtra();
			if (!$extra || !isset($extra['target-user-id'])) {
				break;
			}
			$recipientId = $extra['target-user-id'];
			foreach ($recipientId as $id) {
				$recipient = \User::newFromId($id);
				$users[$id] = $recipient;
			}
			break;
		}
		return true;
	}

	public static function onFlowThreadPosted($post) {
		$poster = \User::newFromId($post->userid);
		$title = \Title::newFromId($post->pageid);

		$targets = array();
		$parent = $post->getParent();
		for (; $parent; $parent = $parent->getParent()) {
			// If the parent post is anonymous, we generate no message
			if ($parent->userid === 0) {
				continue;
			}
			// If the parent is the user himself, we generate no message
			if ($parent->userid === $post->userid) {
				continue;
			}
			$targets[] = $parent->userid;
		}
		\EchoEvent::create(array(
			'type' => 'flowthread_reply',
			'title' => $title,
			'extra' => array(
				'target-user-id' => $targets,
				'postid' => $post->id->getBin(),
			),
			'agent' => $poster,
		));

		// Check if posted on a user page
		if ($title->getNamespace() === NS_USER && !$title->isSubpage()) {
			$user = \User::newFromName($title->getText());
			// If user exists and is not the poster
			if ($user && $user->getId() !== 0 && !$user->equals($poster) && !in_array($user->getId(), $targets)) {
				\EchoEvent::create(array(
					'type' => 'flowthread_userpage',
					'title' => $title,
					'extra' => array(
						'target-user-id' => array($user->getId()),
						'postid' => $post->id->getBin(),
					),
					'agent' => $poster,
				));
			}
		}

		return true;
	}

	public static function onFlowThreadDeleted($post, \User $initiator) {
		if ($post->userid === 0 || $post->userid === $initiator->getId()) {
			return true;
		}

		$poster = \User::newFromId($post->userid);
		$title = \Title::newFromId($post->pageid);

		\EchoEvent::create(array(
			'type' => 'flowthread_delete',
			'title' => $title,
			'extra' => array(
				'notifyAgent' => true,
				'postid' => $post->id->getBin(),
			),
			'agent' => $poster,
		));
		return true;
	}

	public static function onFlowThreadRecovered($post, \User $initiator) {
		if ($post->userid === 0 || $post->userid === $initiator->getId()) {
			return true;
		}

		$poster = \User::newFromId($post->userid);
		$title = \Title::newFromId($post->pageid);

		\EchoEvent::create(array(
			'type' => 'flowthread_recover',
			'title' => $title,
			'extra' => array(
				'notifyAgent' => true,
				'postid' => $post->id->getBin(),
			),
			'agent' => $poster,
		));
		return true;
	}

	public static function onFlowThreadSpammed($post) {
		if ($post->userid === 0) {
			return true;
		}

		$poster = \User::newFromId($post->userid);
		$title = \Title::newFromId($post->pageid);

		\EchoEvent::create(array(
			'type' => 'flowthread_spam',
			'title' => $title,
			'extra' => array(
				'notifyAgent' => true,
				'postid' => $post->id->getBin(),
			),
			'agent' => $poster,
		));
		return true;
	}

	public static function onFlowThreadMention($post, $mentioned) {
		$targets = array();
		foreach ($mentioned as $id => $id2) {
			$targets[] = $id;
		}

		$poster = \User::newFromId($post->userid);
		$title = \Title::newFromId($post->pageid);

		\EchoEvent::create(array(
			'type' => 'flowthread_mention',
			'title' => $title,
			'extra' => array(
				'target-user-id' => $targets,
				'postid' => $post->id->getBin(),
			),
			'agent' => $poster,
		));
		return true;
	}

}
