<?php
namespace FlowThread;

class API extends \ApiBase {

	private function dieNoParam($name) {
		$this->dieUsage("The $name parameter must be set", "no$name");
	}

	private function validatePageId($pageid) {
		if (!\Title::newFromId($pageid)) {
			$this->dieUsageMsg(array('nosuchpageid', $pageid));
		}
	}

	private function convertPosts(array $posts) {
		$attTable = Helper::batchGetUserAttitude($this->getUser(), $posts);
		$ret = array();
		foreach ($posts as $post) {
			$ret[] = array(
				'id' => $post->id->getHex(),
				'userid' => $post->userid,
				'username' => $post->username,
				'text' => $post->text,
				'timestamp' => $post->id->getTimestamp(),
				'parentid' => $post->parentid ? $post->parentid->getHex() : '',
				'like' => $post->getFavorCount(),
				'myatt' => $attTable[$post->id->getHex()],
			);
		}
		return $ret;
	}

	private function fetchPosts($pageid) {
		$offset = intval($this->getMain()->getVal('offset', 0));

		$page = new Page($pageid);
		$page->filter = Page::FILTER_NORMAL;
		$page->offset = $offset;
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
				$ret[] = Post::newFromId(UUID::fromHex($id));
			} catch (\Exception $ex) {
				$this->dieUsage("There is no post with ID $id", 'nosuchpostid');
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

	public function execute() {
		$action = $this->getMain()->getVal('type');
		$page = $this->getMain()->getVal('pageid');

		try {
			// If post is set, get the post object by id
			// By fetching the post object, we also validate the id
			$postList = $this->getMain()->getVal('postid');
			$postList = $this->parsePostList($postList);

			switch ($action) {
			case 'list':
				$this->executeList();
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

				$this->validatePageId($page);

				// Construct the object first without setting the text
				// As we need to use some useful functions on the post object
				$data = array(
					'id' => null,
					'pageid' => $page,
					'userid' => $this->getUser()->getId(),
					'username' => $this->getUser()->getName(),
					'text' => '', // Will be changed later
					'parentid' => count($postList) ? $postList[0]->id : null,
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
				$this->dieUsage("Unrecognized value for parameter 'type': $action", 'unknown_type');
			}
		} catch (\UsageException $e) {
			throw $e;
		} catch (\Exception $e) {
			$this->getResult()->addValue("error", 'code', 'unknown_error');
			$this->getResult()->addValue("error", 'info', $e->getMessage());
		}
		return true;
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
		);
	}

	public function getExamplesMessages() {
		return array(
			'action=flowthread&pageid=1&type=list' => 'apihelp-flowthread-example-1',
			'action=flowthread&pageid=1&type=post&content=Some+Text' => 'apihelp-flowthread-example-2',
			'action=flowthread&pageid=1&postid=AValidPostID&type=post&content=Some+Text' => 'apihelp-flowthread-example-3',
			'action=flowthread&postid=AValidPostID&type=like' => 'apihelp-flowthread-example-4',
			'action=flowthread&postid=AValidPostID&type=delete' => 'apihelp-flowthread-example-5',
		);
	}
}
