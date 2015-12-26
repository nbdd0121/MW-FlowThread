<?php

$wgAutoloadClasses['FlowThread\\EchoHook'] = __DIR__ . '/includes/Echo.php';
$wgAutoloadClasses['FlowThread\\EchoReplyFormatter'] = __DIR__ . '/includes/Echo.php';

$wgHooks['FlowThreadPosted'][] = 'FlowThread\\EchoHook::onFlowThreadPosted';
$wgHooks['BeforeCreateEchoEvent'][] = 'FlowThread\\EchoHook::onBeforeCreateEchoEvent';
$wgHooks['EchoGetDefaultNotifiedUsers'][] = 'FlowThread\\EchoHook::onEchoGetDefaultNotifiedUsers';

$wgDefaultUserOptions['echo-subscriptions-web-flowthread'] = true;
$wgDefaultUserOptions['echo-subscriptions-email-flowthread'] = false;