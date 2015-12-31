<?php
if (!defined('MEDIAWIKI')) {
	die('Wrong Entracne Point');
}

class FlowThread {
	public static function onBeforePageDisplay(OutputPage &$output, Skin &$skin) {
		$title = $output->getTitle();

		// Disallow commenting on pages without article id
		if ($title->getArticleID() == 0) {
			return true;
		}

		if ($title->isSpecialPage()) {
			return true;
		}

		// These could be explicitly allowed in later version
		if (!$title->canTalk()) {
			return true;
		}

		if ($title->isTalkPage()) {
			return true;
		}

		// if ($title->isMainPage()) return;

		// Do not display when printing
		if ($output->isPrintable()) {
			return true;
		}

		// Disable if not viewing
		if ($skin->getRequest()->getVal('action', 'view') != 'view') {
			return true;
		}

		// Blacklist several namespace
		if (in_array($title->getNamespace(), array(
			NS_MEDIAWIKI,
			NS_TEMPLATE,
			NS_CATEGORY,
		))) {
			return true;
		}

		if ($output->getUser()->isAllowed('commentadmin-restricted')) {
			$output->addJsConfigVars(array('commentadmin' => ''));
		}

		global $wgExtAvatar, $wgDefaultAvatar;
		if ($wgExtAvatar) {
			$output->addJsConfigVars(array('wgExtAvatar' => true));
		}
		$output->addJsConfigVars(array('wgDefaultAvatar' => $wgDefaultAvatar));
		$output->addModules('ext.flowthread');
		return true;
	}

	public static function onLoadExtensionSchemaUpdates($updater) {
		$dir = __DIR__ . '/sql';

		$dbType = $updater->getDB()->getType();
		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
		if (!in_array($dbType, array('mysql', 'sqlite'))) {
			throw new Exception('Database type not currently supported');
		} else {
			$filename = 'mysql.sql';
		}

		$updater->addExtensionTable('FlowThread', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadAttitude', "{$dir}/{$filename}");

		return true;
	}

	public static function onArticleDeleteComplete(&$article, User &$user, $reason, $id, Content $content = null, LogEntry $logEntry) {
		$page = new \FlowThread\Page($id);
		$page->limit = -1;
		$page->fetch();
		$page->erase();
		return true;
	}
}
