<?php
namespace FlowThread;

class SpecialManage extends \SpecialPage {

	private $page;
	private $user;
	private $keyword;
	private $filter;
	private $limit = 10;

	public function __construct() {
		parent::__construct('FlowThreadManage', 'commentadmin-restricted');
	}

	public function execute($par) {
		$user = $this->getUser();
		if (!$this->userCanExecute($user)) {
			throw new \PermissionsError('commentadmin-restricted');
		}

		// Parse request
		$opt = new \FormOptions;
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

		global $wgScript;

		$this->setHeaders();
		$this->outputHeader();
		$output = $this->getOutput();
		$output->addModules('mediawiki.userSuggest'); # This is used for user input field
		$output->addModules('ext.flowthread.manage');

		$this->showForm();

		// Pager can only be generated after query
		$output->addHTML($this->getPager());

		if ($this->getUser()->isAllowed('commentadmin')) {
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
		$title = parent::getTitleFor('FlowThreadManage');
		$html = \Html::hidden('title', $this->getTitle());

		$html .= $this->getTitleInput($this->page) . "\n";
		$html .= $this->getUserInput($this->user) . "\n";
		$html .= $this->getKeywordInput($this->keyword) . "\n";

		$html .= \Xml::tags('p', null, $this->getFilterLinks($this->filter));

		// Submit button
		$html .= \Xml::submitButton($this->msg('allpagessubmit')->text());

		// Fieldset
		$html = \Xml::fieldset($this->msg('flowthreadmanage')->text(), $html);

		// Wrap with a form
		$html = \Xml::tags('form', array('action' => $wgScript, 'method' => 'get'), $html);

		if ($this->getUser()->isAllowed('editinterface')) {
			$html .= \Xml::tags('small', array('style' => 'float:right;'), \Linker::linkKnown(
				\Title::newFromText('MediaWiki:Flowthread-blacklist'),
				$this->msg('flowthread-ui-editblacklist')
			));
		}

		$this->getOutput()->addHTML($html);
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

		$hiddens = \Html::hidden("filter", $current) . "\n";

		// Build links
		return '<small>' . $this->getLanguage()->pipeList($links) . '</small>' . $hiddens;
	}

	private function getQuery() {
		$query = $this->getRequest()->getQueryValues();
		unset($query['title']);
		return $query;
	}

	private function getUserInput($user) {
		$label = \Xml::inputLabel(
			$this->msg('flowthreadmanage-user')->text(),
			'user',
			'',
			15,
			$user,
			array('class' => 'mw-autocomplete-user') # This together with mediawiki.userSuggest will give us an auto completion
		);

		return '<span style="white-space: nowrap">' . $label . '</span>';
	}

	private function getTitleInput($title) {
		$label = \Xml::inputLabel(
			$this->msg('flowthreadmanage-title')->text(),
			'page',
			'',
			20,
			$title
		);

		return '<span style="white-space: nowrap">' . $label . '</span>';
	}

	private function getKeywordInput($keyword) {
		$label = \Xml::inputLabel(
			$this->msg('flowthreadmanage-keyword')->text(),
			'keyword',
			'',
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
		return \Linker::linkKnown(
			$this->getTitle(),
			$msg,
			['id' => $id],
			$query
		);
	}

}
