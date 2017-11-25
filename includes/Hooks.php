<?php
namespace FlowThread;

class Hooks {

	public static function getFilteredNamespace() {
		$ret = array(
			NS_MEDIAWIKI,
			NS_TEMPLATE,
			NS_CATEGORY,
			NS_FILE,
		);
		if (defined('NS_MODULE')) {
			$ret[] = NS_MODULE;
		}

		return $ret;
	}

	public static function onBeforePageDisplay(\OutputPage &$output, \Skin &$skin) {
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

		// No commenting on main page
		if ($title->isMainPage()) {
			return true;
		}

		// Do not display when printing
		if ($output->isPrintable()) {
			return true;
		}

		// Disable if not viewing
		if ($skin->getRequest()->getVal('action', 'view') != 'view') {
			return true;
		}

		// Blacklist several namespace
		if (in_array($title->getNamespace(), self::getFilteredNamespace())) {
			return true;
		}

		if ($output->getUser()->isAllowed('commentadmin-restricted')) {
			$output->addJsConfigVars(array('commentadmin' => ''));
		}

		global $wgFlowThreadConfig;
		$config = array(
			'Avatar' => $wgFlowThreadConfig['Avatar'],
			'AnonymousAvatar' => $wgFlowThreadConfig['AnonymousAvatar'],
		);

		if (\FlowThread\Post::canPost($output->getUser())) {
			$config['UserOptOutNotice'] = wfMessage('flowthread-ui-useroptout')->toString();
			$output->addJsConfigVars(array('canpost' => ''));
		} else {
			$config['CantPostNotice'] = wfMessage('flowthread-ui-cantpost')->toString();
		}

		global $wgFlowThreadConfig;
		$output->addJsConfigVars(array('wgFlowThreadConfig' => $config));
		$output->addModules('ext.flowthread');
		return true;
	}

	public static function onLoadExtensionSchemaUpdates($updater) {
		$dir = __DIR__ . '/../sql';

		$dbType = $updater->getDB()->getType();
		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
		if (!in_array($dbType, array('mysql', 'sqlite'))) {
			throw new \Exception('Database type not currently supported');
		} else {
			$filename = 'mysql.sql';
		}

		$updater->addExtensionTable('FlowThread', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadAttitude', "{$dir}/{$filename}");

		return true;
	}

	public static function onArticleDeleteComplete(&$article, \User &$user, $reason, $id, \Content $content = null, \LogEntry $logEntry) {
		$page = new Page($id);
		$page->limit = -1;
		$page->fetch();
		$page->erase();
		return true;
	}

	public static function onBaseTemplateToolbox(\BaseTemplate &$baseTemplate, array &$toolbox) {
		if (isset($baseTemplate->data['nav_urls']['usercomments'])
			&& $baseTemplate->data['nav_urls']['usercomments']) {
			$toolbox['usercomments'] = $baseTemplate->data['nav_urls']['usercomments'];
			$toolbox['usercomments']['id'] = 't-usercomments';
		}
	}

	public static function onSkinTemplateOutputPageBeforeExec(&$skinTemplate, &$tpl) {
		$user = $skinTemplate->getRelevantUser();

		if ($user && $skinTemplate->getUser()->isAllowed('commentadmin-restricted')) {
			$nav_urls = $tpl->get('nav_urls');
			$nav_urls['usercomments'] = [
				'text' => wfMessage('sidebar-usercomments')->text(),
				'href' => \SpecialPage::getTitleFor('FlowThreadManage')->getLocalURL(array(
					'user' => $user->getName(),
				)),
			];
			$tpl->set('nav_urls', $nav_urls);
		}

		return true;
	}
}
