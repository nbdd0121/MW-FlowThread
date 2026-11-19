<?php
namespace FlowThread;

use MediaWiki\Html\FormOptions;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

class SpecialManage extends SpecialPage {

	private $page;
	private $user;
	private $keyword;
	private $filter;
	private $limit = 10;

	public function __construct() {
		parent::__construct('FlowThreadManage');
		if (property_exists($this, 'mRestriction')) {
			$this->mRestriction = $this->getRestriction();
		}
	}

	public function getRestriction(): string {
		return 'commentadmin-restricted';
	}

	public function execute($par) {
		$this->checkPermissions();

		// Parse request
		$opt = new FormOptions;
		$opt->add('user', '');
		$opt->add('page', '');
		$opt->add('filter', 'all');
		$opt->add('keyword', '');
		$opt->add('limit', '20');

		$opt->fetchValuesFromRequest($this->getRequest());

		// Reset filter to all if it cannot be recognized
		$filter = $opt->getValue('filter');
		if (!in_array($filter, $this->getPossibleFilters())) {
			$filter = 'all';
		}
		$this->filter = $filter;

		// Set local variable
		$this->page = $opt->getValue('page');
		$this->user = $opt->getValue('user');
		$this->keyword = $opt->getValue('keyword');
		$this->limit = intval($opt->getValue('limit'));

		// Limit the max limit
		if ($this->limit >= 500) {
			$this->limit = 500;
		}

		$this->setHeaders();
		$this->outputHeader();
		$output = $this->getOutput();
		$output->addModules('mediawiki.userSuggest'); # This is used for user input field
		$output->addModules('ext.flowthread.manage');

		$this->showForm();

		// Pager can only be generated after query
		$output->addHTML($this->getPager());

		if (MediaWikiServices::getInstance()->getPermissionManager()->userHasRight($this->getUser(), 'commentadmin')) {
			$output->addJsConfigVars(array(
				'commentadmin' => '',
			));
		}

		global $wgFlowThreadConfig;
		$output->addJsConfigVars(array('wgFlowThreadConfig' => array(
			'Avatar' => $wgFlowThreadConfig['Avatar'],
			'AnonymousAvatar' => $wgFlowThreadConfig['AnonymousAvatar'],
		)));
	}

	private function showForm() {
		global $wgScript;

		// This is essential as we need to submit the form to this page
		$html = Html::hidden('title', $this->getPageTitle()->getPrefixedText());

		$html .= $this->getTitleInput($this->page) . "\n";
		$html .= $this->getUserInput($this->user) . "\n";
		$html .= $this->getKeywordInput($this->keyword) . "\n";

		$html .= Html::rawElement('p', [], $this->getFilterLinks($this->filter));

		// Submit button
		$html .= Html::submitButton($this->msg('allpagessubmit')->text());

		// Fieldset
		$legend = Html::element('legend', [], $this->msg('flowthreadmanage')->text());
		$html = Html::rawElement('fieldset', [], $legend . $html);

		// Wrap with a form
		$html = Html::rawElement('form', ['action' => $wgScript, 'method' => 'get'], $html);

		if (MediaWikiServices::getInstance()->getPermissionManager()->userHasRight($this->getUser(), 'editinterface')) {
			$link = MediaWikiServices::getInstance()->getLinkRenderer()
				->makeKnownLink(Title::newFromText('MediaWiki:Flowthread-blacklist'),
					$this->msg('flowthread-ui-editblacklist'));
			$html .= Html::rawElement('small', ['style' => 'float:right;'], $link);
		}

		$this->getOutput()->addHTML($html);
	}

	private function inputLabel($label, $name, $size, $value, $attribs = array()) {
		$attribs['size'] = $size;
		$attribs['id'] = 'flowthread-manage-' . $name;
		return Html::label($label, $attribs['id'])
			. ' ' . Html::input($name, $value, 'text', $attribs);
	}

	private function getPossibleFilters() {
		return array('all', 'reported', 'spam', 'deleted');
	}

	private function getFilterLinks($current) {
		$links = array();
		$query = $this->getQuery();
		foreach ($this->getPossibleFilters() as $filter) {
			$msg = $this->msg("flowthread-filter-{$filter}")->escaped();
			if ($filter === $current) {
				$links[] = '<b>' . $msg . '</b>';
			} else {
				$query['filter'] = $filter;
				$links[] = $this->getQueryLink($msg, $query);
			}
		}

		$hiddens = Html::hidden("filter", $current) . "\n";

		// Build links
		return '<small>' . $this->getLanguage()->pipeList($links) . '</small>' . $hiddens;
	}

	private function getQuery() {
		$query = $this->getRequest()->getQueryValues();
		unset($query['title']);
		return $query;
	}

	private function getUserInput($user) {
		$label = $this->inputLabel(
			$this->msg('flowthreadmanage-user')->text(),
			'user',
			15,
			$user,
			array('class' => 'mw-autocomplete-user') # This together with mediawiki.userSuggest will give us an auto completion
		);

		return '<span style="white-space: nowrap">' . $label . '</span>';
	}

	private function getTitleInput($title) {
		$label = $this->inputLabel(
			$this->msg('flowthreadmanage-title')->text(),
			'page',
			20,
			$title
		);

		return '<span style="white-space: nowrap">' . $label . '</span>';
	}

	private function getKeywordInput($keyword) {
		$label = $this->inputLabel(
			$this->msg('flowthreadmanage-keyword')->text(),
			'keyword',
			20,
			$keyword
		);

		return '<span style="white-space: nowrap">' . $label . '</span>';
	}

	private function getPager() {
		$firstLastLinks = $this->getFirstPageLink();
		$firstLastLinks .= $this->msg('pipe-separator')->escaped();
		$firstLastLinks .= $this->getLastPageLink();
		$firstLastLinks = $this->msg('parentheses')->rawParams($firstLastLinks)->escaped();

		return $firstLastLinks . $this->msg('viewprevnext')->rawParams(
			$this->getPrevPageLink(), $this->getNextPageLink(), $this->getLimitLinks())->escaped();
	}

	private function getFirstPageLink() {
		$query = $this->getQuery();
		unset($query['dir']);
		$query['offset'] = 0;
		$msg = $this->msg('histlast')->escaped();
		return $this->getQueryLink($msg, $query, 'pager-first');
	}

	private function getLastPageLink() {
		$query = $this->getQuery();
		$query['dir'] = 'prev';
		$query['offset'] = 0;
		$msg = $this->msg('histfirst')->escaped();
		return $this->getQueryLink($msg, $query, 'pager-last');
	}

	private function getPrevPageLink() {
		$query = $this->getQuery();
		// No need to set any query params here, as this is done in client.
		$msg = $this->msg('pager-newer-n')->numParams($this->limit)->escaped();
		return $this->getQueryLink($msg, $query, 'pager-prev');
	}

	private function getNextPageLink() {
		$query = $this->getQuery();
		$msg = $this->msg('pager-older-n')->numParams($this->limit)->escaped();
		return $this->getQueryLink($msg, $query, 'pager-next');
	}

	private function getLimitLinks() {
		$possibleLimits = array(10, 20, 50, 100, 200);
		$query = $this->getQuery();
		$str = '';
		foreach ($possibleLimits as $limit) {
			if (strlen($str) !== 0) {
				$str .= $this->msg('pipe-separator')->escaped();
			}
			if ($limit === $this->limit) {
				$str .= $limit;
			} else {
				$query['limit'] = $limit;
				$str .= $this->getQueryLink($limit, $query);
			}
		}
		return $str;
	}

	private function getQueryLink($msg, $query, $id = false) {
		return MediaWikiServices::getInstance()->getLinkRenderer()
			->makeKnownLink($this->getPageTitle(), $msg, ['id' => $id], $query);
	}

}
