<?php
namespace FlowThread;

class API extends \ApiBase {

	private function dieNoParam($name) {
		$this->dieWithError([ 'apierror-paramempty', $name ], 'noprop');
	}

	private function convertPosts(array $posts, $needTitle = false, $priviledged = false) {
		$attTable = Helper::batchGetUserAttitude($this->getUser(), $posts);
		$ret = array();
		foreach ($posts as $post) {
			$json = array(
				'id' => $post->id->getHex(),
				'userid' => $post->userid,
				'username' => $post->username,
				'text' => $post->text,
				'timestamp' => $post->id->getTimestamp(),
				'parentid' => $post->parentid ? $post->parentid->getHex() : '',
				'like' => $post->getFavorCount(),
				'myatt' => $attTable[$post->id->getHex()],
			);
			if ($needTitle) {
				$title = \Title::newFromId($post->pageid);
				$json['pageid'] = $post->pageid;
				$json['title'] = $title ? $title->getPrefixedText() : null;
			}
			if ($priviledged) {
				$json['report'] = $post->getReportCount();
				$json['status'] = $post->status;
			}
			$ret[] = $json;
		}
		return $ret;
	}

	private function fetchPosts($pageid) {
		$limit = $this->getMain()->getVal('limit', 10);
		if ($limit === 'max') {
			$limit = -1;
		} else {
			// Limit must be positive
			$limit = max(intval($limit), 1);
		}
		// Offset must be non-negative
		$offset = max(intval($this->getMain()->getVal('offset', 0)), 0);

		if (!is_numeric($pageid) || $pageid == 0) {
			$this->dieWithError( [ 'apierror-nosuchpageid', $pageid ] );
		}

		$page = new Query();
		$page->pageid = $pageid;
		$page->filter = Query::FILTER_NORMAL;
		$page->offset = $offset;
		$page->limit = $limit;
		$page->fetch();

		$comments = $this->convertPosts($page->posts);

		// This is slow, use cache
		$cache = \ObjectCache::getMainWANInstance();
		$popular = PopularPosts::getFromPageId($pageid);
		$popularRet = $this->convertPosts($popular);

		$obj = array(
			"posts" => $comments,
			"popular" => $popularRet,
			"count" => $page->totalCount,
		);

		return $obj;
	}

	private function parsePostList($postList) {
		if (!$postList) {
			return null;
		}
		$ret = array();
		foreach (explode('|', $postList) as $id) {
			try {
				$ret[] = Post::newFromId(UID::fromHex($id));
			} catch (\Exception $ex) {
				$this->dieWithError(['apierror-nosuchpostid', $id]);
			}
		}
		return $ret;
	}

	private function executeList() {
		$page = $this->getMain()->getVal('pageid');
		if (!$page) {
			$this->dieNoParam('pageid');
		}
		$this->getResult()->addValue(null, $this->getModuleName(), $this->fetchPosts($page));
	}

	private function executeListAll() {
		$query = new Query();
		$query->threadMode = false;

		$filter = $this->getMain()->getVal('filter');
		$priviledged = true;
		if ($filter === 'all') {
			$query->filter = Query::FILTER_ALL;
		} else if ($filter === 'deleted') {
			$query->filter = Query::FILTER_DELETED;
		} else if ($filter === 'spam') {
			$query->filter = Query::FILTER_SPAM;
		} else if ($filter === 'reported') {
			$query->filter = Query::FILTER_REPORTED;
		} else {
			$priviledged = false;
			$query->filter = Query::FILTER_NORMAL;
		}

		// Try pageid first, if it is absent/invalid, also try title.
		$pageid = max(intval($this->getMain()->getVal('pageid')), 0);
		if (!$pageid) {
			$title = $this->getMain()->getVal('title');
			if ($title) {
				$titleObj = \Title::newFromText($title);
				if ($titleObj !== null && $titleObj->exists()) {
					$pageid = $titleObj->getArticleID();
				}
			}
		}
		$query->pageid = $pageid;
		$user = $this->getMain()->getVal('user');
		if ($user) $query->user = $user;

		$keyword = $this->getMain()->getVal('keyword');
		if ($keyword) {
			// Even though this is public information, this operation is quite
			// expensive, so we restrict its usage.
			$priviledged = true;
			$query->keyword = $keyword;
		}
		$dir = $this->getMain()->getVal('dir');
		$query->dir = $dir === 'newer' ? 'newer' : 'older';
		$limit = max(min(intval($this->getMain()->getVal('limit', 10)), 500), 1);
		$query->limit = $limit + 1;
		$query->offset = max(intval($this->getMain()->getVal('offset', 0)), 0);

		// Check if the user is allowed to do priviledged queries.
		if ($priviledged) {
			$this->checkUserRightsAny('commentadmin-restricted');
		} else {
			if ($this->getUser()->isAllowed('commentadmin-restricted')) $priviledged = true;
		}

		$query->fetch();
		$posts = $query->posts;

		// We fetched one extra row. If it exists in response, then we know we have more to fetch.
		$more = false;
		if (count($posts) > $limit) {
			$more = true;
			array_pop($posts);
		}

		// For un-priviledged users, do sanitisation
		if (!$priviledged) {
			// Fetch parents to check visibility
			do {} while (Helper::batchFetchParent($query->posts));
			$visible = [];
			foreach ($posts as $post) {
				if ($post->isVisible()) $visible[] = $post;
			}
			$posts = $visible;
		}

		$comments = $this->convertPosts($posts, true, $priviledged);
		$obj = [
			"more" => $more,
			"posts" => $comments,
		];
		$this->getResult()->addValue(null, $this->getModuleName(), $obj);
	}

	public static function stripWrapper( $html ) {
		$m = [];
		if ( preg_match( '/^<div class="mw-parser-output">(.*)<\/div>$/sU', $html, $m ) ) {
			$html = $m[1];
		}
		return $html;
	}

	public function execute() {
		$action = $this->getMain()->getVal('type');
		$page = $this->getMain()->getVal('pageid');

		// Forbid non-POST request on operations that could change the database.
		// However for backware compatibility do not enforce it now.
		// This will be changed to true in future versions, or can be set manually.
		global $wgFlowThreadEnforcePost;
		if ($wgFlowThreadEnforcePost &&
		    $this->isWriteMode() && $this->getRequest()->getMethod() !== 'POST') {
			$this->dieWithError(['apierror-mustbeposted', 'FlowThread']);
		}

		try {
			// If post is set, get the post object by id
			// By fetching the post object, we also validate the id
			$postList = $this->getMain()->getVal('postid');
			$postList = $this->parsePostList($postList);

			switch ($action) {
			case 'list':
				$this->executeList();
				break;

			case 'listall':
				$this->executeListAll();
				break;

			case 'like':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->setUserAttitude($this->getUser(), Post::ATTITUDE_LIKE);
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'dislike':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->setUserAttitude($this->getUser(), Post::ATTITUDE_NORMAL);
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'report':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->setUserAttitude($this->getUser(), Post::ATTITUDE_REPORT);
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'delete':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->delete($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'recover':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->recover($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'markchecked':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->markchecked($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'erase':
				if (!$postList) {
					$this->dieNoParam('postid');
				}
				foreach ($postList as $post) {
					$post->erase($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'post':
				if (!$page) {
					$this->dieNoParam('pageid');
				}
				$text = $this->getMain()->getVal('content');
				if (!$text) {
					$this->dieNoParam('content');
				}

				// Permission check
				Post::checkIfCanPost($this->getUser());

				$title = \Title::newFromId($page);
				if (!$title) {
					$this->dieWithError( [ 'apierror-nosuchpageid', $page ] );
				}

				if (!Helper::canEverPostOnTitle($title)) {
					$this->dieWithError(['apierror-cantpost', $title]);
				}

				$controlStatus = SpecialControl::getControlStatus($title);
				if ($controlStatus !== SpecialControl::STATUS_ENABLED) {
					$this->dieWithError(['apierror-commentcontrol', $title]);
				}

				// Construct the object first without setting the text
				// As we need to use some useful functions on the post object
				$data = array(
					'id' => null,
					'pageid' => $page,
					'userid' => $this->getUser()->getId(),
					'username' => $this->getUser()->getName(),
					'text' => '', // Will be changed later
					'parentid' => $postList && count($postList) ? $postList[0]->id : null,
					'status' => Post::STATUS_NORMAL, // Will be changed later
					'like' => 0,
					'report' => 0,
				);
				$postObject = new Post($data);

				// Need to feed this to spam filter
				$useWikitext = $this->getMain()->getCheck('wikitext');
				$filterResult = SpamFilter::validate($text, $this->getUser(), $useWikitext);
				$text = $filterResult['text'];

				// We need to do this step, as we might need to transform
				// the text, so unify both cases will be more convenient.
				if (!$useWikitext) {
					$text = '<nowiki>' . htmlspecialchars($text) . '</nowiki>';
				}

				// Restrict max nest level. If exceeded, automatically prepend a @ before
				global $wgFlowThreadConfig;
				if ($postObject->getNestLevel() > $wgFlowThreadConfig['MaxNestLevel']) {
					$parent = $postObject->getParent();
					$postObject->parentid = $parent->parentid;
					$postObject->parent = $parent->parent;
					if ($parent->userid) {
						$text = $this->msg('flowthread-reply-user', $parent->username)->plain() . $text;
					} else {
						$text = $this->msg('flowthread-reply-anonymous', $parent->username)->plain() . $text;
					}
				}

				$parser = new \Parser();

				// Set options for parsing
				$opt = new \ParserOptions($this->getUser());
				$opt->setEditSection(false); // Edit button will not work!

				$output = $parser->parse($text, \Title::newFromId($page), $opt);
				$text = $output->getText();

				// Get all mentioned user
				$mentioned = Helper::generateMentionedList($output, $postObject);

				unset($parser);
				unset($opt);
				unset($output);

				// Useless p wrapper
				$text = self::stripWrapper($text);
				$text = \Parser::stripOuterParagraph($text);
				$text = SpamFilter::sanitize($text);

				// Fix object
				if (!$filterResult['good']) {
					$postObject->status = Post::STATUS_SPAM;
				}
				$postObject->text = $text;
				$postObject->post();

				if (!$filterResult['good']) {
					global $wgTriggerFlowThreadHooks;
					if ($wgTriggerFlowThreadHooks) {
						\Hooks::run('FlowThreadSpammed', array($postObject));
					}
				}

				if (count($mentioned)) {
					\Hooks::run('FlowThreadMention', array($postObject, $mentioned));
				}

				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;
			default:
				$this->dieWithError(['apierror-unrecognizedvalue', 'type', $action]);
			}
		} catch (\ApiUsageException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->getResult()->addValue("error", 'code', 'unknown_error');
			$this->getResult()->addValue("error", 'info', $e->getMessage());
		}
		return true;
	}

	public function isWriteMode() {
		$action = $this->getMain()->getVal('type');
		// These two subactions are non-write, and all others are.
		return $action !== 'list' && $action !== 'listall';
	}

	public function getAllowedParams() {
		return array(
			'type' => array(
				\ApiBase::PARAM_TYPE => 'string',
				\ApiBase::PARAM_REQUIRED => true,
			),
			'pageid' => array(
				\ApiBase::PARAM_TYPE => 'integer',
			),
			'postid' => array(
				\ApiBase::PARAM_TYPE => 'string',
			),
			'content' => array(
				\ApiBase::PARAM_TYPE => 'string',
			),
			'wikitext' => array(
				\ApiBase::PARAM_TYPE => 'boolean',
			),
			'offset' => array(
				\ApiBase::PARAM_TYPE => 'integer',
			),
			'limit' => array(
				\ApiBase::PARAM_TYPE => 'integer',
			),
		);
	}

	public function getExamplesMessages() {
		return array(
			'action=flowthread&pageid=1&type=list&limit=max' => 'apihelp-flowthread-example-1',
			'action=flowthread&pageid=1&type=post&content=Some+Text' => 'apihelp-flowthread-example-2',
			'action=flowthread&pageid=1&postid=AValidPostID&type=post&content=Some+Text' => 'apihelp-flowthread-example-3',
			'action=flowthread&postid=AValidPostID&type=like' => 'apihelp-flowthread-example-4',
			'action=flowthread&postid=AValidPostID&type=delete' => 'apihelp-flowthread-example-5',
		);
	}
}
