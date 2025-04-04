<?php
namespace FlowThread;

use Exception;
use MediaWiki\Content\Content;
use MediaWiki\Logging\LogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Skin\BaseTemplate;
use MediaWiki\Skin\Skin;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;

class Hooks {

	public static function onBeforePageDisplay(OutputPage &$output, Skin &$skin) {
		$title = $output->getTitle();

		// If the comments are never allowed on the title, do not load
		// FlowThread at all.
		if (!Helper::canEverPostOnTitle($title)) {
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

		if (self::getPermissionManager()->userHasRight($output->getUser(), 'commentadmin-restricted')) {
			$output->addJsConfigVars(array('commentadmin' => ''));
		}

		global $wgFlowThreadConfig;
		$config = array(
			'Avatar' => $wgFlowThreadConfig['Avatar'],
			'AnonymousAvatar' => $wgFlowThreadConfig['AnonymousAvatar'],
		);

		// First check if user can post at all
		if (!Post::canPost($output->getUser())) {
			$config['CantPostNotice'] = wfMessage('flowthread-ui-cantpost')->parse();
		} else {
			$status = SpecialControl::getControlStatus($title);
			if ($status === SpecialControl::STATUS_OPTEDOUT) {
				$config['CantPostNotice'] = wfMessage('flowthread-ui-useroptout')->parse();
			} else if ($status === SpecialControl::STATUS_DISABLED) {
				$config['CantPostNotice'] = wfMessage('flowthread-ui-disabled')->parse();
			} else {
				$output->addJsConfigVars(array('canpost' => ''));
			}
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
			throw new Exception('Database type not currently supported');
		} else {
			$filename = 'mysql.sql';
		}

		$updater->addExtensionTable('FlowThread', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadAttitude', "{$dir}/{$filename}");
		$updater->addExtensionTable('FlowThreadControl', "{$dir}/control.sql");

		return true;
	}

	public static function onArticleDeleteComplete(&$article, User &$user, $reason, $id, Content $content = null, LogEntry $logEntry) {
		$page = new Query();
		$page->pageid = $id;
		$page->limit = -1;
		$page->threadMode = false;
		$page->fetch();
		$page->erase();
		return true;
	}

	public static function onBaseTemplateToolbox(BaseTemplate &$baseTemplate, array &$toolbox) {
		if (isset($baseTemplate->data['nav_urls']['usercomments'])
			&& $baseTemplate->data['nav_urls']['usercomments']) {
			$toolbox['usercomments'] = $baseTemplate->data['nav_urls']['usercomments'];
			$toolbox['usercomments']['id'] = 't-usercomments';
		}
	}

	public static function onSidebarBeforeOutput(Skin $skin, &$sidebar) {
		$commentAdmin = self::getPermissionManager()->userHasRight($skin->getUser(), 'commentadmin-restricted');
		$user = $skin->getRelevantUser();

		if ($user && $commentAdmin) {
			$sidebar['TOOLBOX'][] = [
				'text' => wfMessage('sidebar-usercomments')->text(),
				'href' => SpecialPage::getTitleFor('FlowThreadManage')->getLocalURL(array(
					'user' => $user->getName(),
				)),
			];
		}
	}

	public static function onSkinTemplateNavigation_Universal(SkinTemplate $skinTemplate, array &$links) {
		$commentAdmin = self::getPermissionManager()->userHasRight($skinTemplate->getUser(), 'commentadmin-restricted');
		$user = $skinTemplate->getRelevantUser();

		$title = $skinTemplate->getRelevantTitle();
		if (Helper::canEverPostOnTitle($title) && ($commentAdmin || Post::userOwnsPage($skinTemplate->getUser(), $title))) {
			// add a new action
			$links['actions']['flowthreadcontrol'] = [
				'id' => 'ca-flowthreadcontrol',
				'text' => wfMessage('action-flowthreadcontrol')->text(),
				'href' => SpecialPage::getTitleFor('FlowThreadControl', $title->getPrefixedDBKey())->getLocalURL()
			];
		}

		return true;
	}

	private static function getPermissionManager() : PermissionManager {
		return MediaWikiServices::getInstance()->getPermissionManager();
	}
}
