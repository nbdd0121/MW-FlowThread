<?php
namespace FlowThread;

class EchoHook {

	public static function onBeforeCreateEchoEvent( &$notifications, &$notificationCategories, &$icons ) {
        $notificationCategories['flowthread'] = array(
		    'priority' => 4,
		    'tooltip' => 'echo-pref-tooltip-flowthread',
		);
		$notifications['flowthread_reply'] = array(
			'primary-link' => array( 'message' => 'notification-link-text-view-flowthread_reply', 'destination' => 'title' ),
			'category' => 'flowthread',
			'group' => 'interactive',
			'section' => 'message',
			'formatter-class' => 'FlowThread\\EchoReplyFormatter',
			'title-message' => 'notification-flowthread_reply',
			'title-params' => array('agent','title'),
			'flyout-message' => 'notification-flowthread_reply-flyout',
			'flyout-params' => array( 'agent', 'title'),
			'payload' => array( 'text' ),
			'email-subject-message' => 'notification-flowthread_reply-email-subject',
			'email-subject-params' => array( 'agent' ),
			'email-body-batch-message' => 'notification-flowthread_reply-email-batch-body',
			'email-body-batch-params' => array( 'agent', 'title' ),
			'icon' => 'chat'
		);
        return true;
    }

	public static function onEchoGetDefaultNotifiedUsers( $event, &$users ) {
		switch ( $event->getType() ) {
	 		case 'flowthread_reply':
	 			$extra = $event->getExtra();
	 			if ( !$extra || !isset( $extra['target-user-id'] ) ) {
	 				break;
	 			}
	 			$recipientId = $extra['target-user-id'];
	 			$recipient = \User::newFromId( $recipientId );
	 			$users[$recipientId] = $recipient;
	 			break;
	 	}
	 	return true;
	}
		
	public static function onFlowThreadPosted($post) {
		$parent = $post->getParent();
		// If it is a new post, we generate no message
		if(!$parent) {
			return true;
		}
		// If the parent post is anonymous, we generate no message
		if($parent->userid === 0) {
			return true;
		}
		// If the parent is the user himself, we generate no message
		if($parent->userid === $post->userid) {
			return true;
		}
		\EchoEvent::create(array(
			'type' => 'flowthread_reply',
			'title' => \Title::newFromId($post->pageid),
			'extra' => array(
				'target-user-id' => $parent->userid,
				'postid' => $post->id->getBin()
			),
			'agent' => \User::newFromId($post->userid),
		));
		return true;
	}

}

 class EchoReplyFormatter extends \EchoBasicFormatter {
     protected function formatPayload( $payload, $event, $user ) {
          switch ( $payload ) {
               case 'text': 
               		try{
                    	return Post::newFromId(UUID::fromBin($event->getExtraParam('postid')))->text;
                    }catch(\Exception $e){
                    	return wfMessage('notification-flowthread_reply-payload-error');
                    }
               default:
                    return parent::formatPayload( $payload, $event, $user );
                    break;
          }
     }
 }
