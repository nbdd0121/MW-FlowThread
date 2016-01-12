<?php
namespace FlowThread;

class API extends \ApiBase {

	private function dieNoParam($name) {
		$this->dieUsage("The $name parameter must be set", "no$name");
	}

	private function fetchPosts($pageid) {
		$offset = intval($this->getMain()->getVal('offset', 0));

		$page = new Page($pageid);
		$page->type = Post::STATUS_NORMAL;
		$page->offset = $offset;
		$page->fetch();

		$comments = array();
		foreach ($page->posts as $post) {
			// No longer need to filter out invisible posts

			$favorCount = $post->getFavorCount();
			$myAtt = $post->getUserAttitude($this->getUser());

			$data = array(
				'id' => $post->id->getHex(),
				'userid' => $post->userid,
				'username' => $post->username,
				'text' => $post->text,
				'timestamp' => $post->id->getTimestamp(),
				'parentid' => $post->parentid ? $post->parentid->getHex() : '',
				'like' => $favorCount,
				'myatt' => $myAtt,
			);

			$comments[] = $data;
		}

		$obj = array(
			"posts" => $comments,
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
				if (!$page) {
					$this->dieNoParam('pageid');
				}
				$this->getResult()->addValue(null, $this->getModuleName(), $this->fetchPosts($page));
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

				$spam = !SpamFilter::validate($text);

				// Parse as wikitext if specified
				if ($this->getMain()->getCheck('wikitext')) {
					$parser = new \Parser();
					$opt = new \ParserOptions($this->getUser());
					$opt->setEditSection(false);
					$output = $parser->parse($text, \Title::newFromId($page), $opt);
					$text = $output->getText();
					unset($parser);
					unset($opt);
					unset($output);
				}

				$data = array(
					'id' => null,
					'pageid' => $page,
					'userid' => $this->getUser()->getId(),
					'username' => $this->getUser()->getName(),
					'text' => $text,
					'parentid' => count($postList) ? $postList[0]->id : null,
					'status' => $spam ? Post::STATUS_SPAM : Post::STATUS_NORMAL,
					'like' => 0,
					'report' => 0,
				);

				$postObject = new Post($data);

				global $wgFlowThreadConfig;
				// Restrict max nest level
				if ($postObject->getNestLevel() > $wgFlowThreadConfig['MaxNestLevel']) {
					$postObject->parentid = $postObject->getParent()->parentid;
					$postObject->parent = $postObject->getParent()->parent;
				}

				$postObject->post();

				if ($spam) {
					global $wgTriggerFlowThreadHooks;
					if ($wgTriggerFlowThreadHooks) {
						\Hooks::run('FlowThreadSpammed', array($postObject));
					}
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
