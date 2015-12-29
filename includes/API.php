<?php
namespace FlowThread;

class API extends \ApiBase {

	private function fetchPosts($pageid) {
		$page = Page::newFromId($pageid);

		$comments = array();
		foreach ($page->posts as $post) {
			// Filter out invisible posts (deleted posts, or one of those posts' parent is deleted)
			if ($post->isVisible()) {
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
		}

		return $comments;
	}

	private function parsePostList($postList) {
		if (!$postList) {
			return null;
		}
		$ret = array();
		foreach (explode('|', $postList) as $id) {
			$ret[] = Post::newFromId(UUID::fromHex($id));
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
					throw new \Exception("Page id must be specified");
				}
				$this->getResult()->addValue(null, $this->getModuleName(), $this->fetchPosts($page));
				break;

			case 'like':
				if (!$postList) {
					throw new \Exception("Post id must be specified");
				}
				foreach ($postList as $post) {
					$post->setUserAttitude($this->getUser(), Post::ATTITUDE_LIKE);
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'dislike':
				if (!$postList) {
					throw new \Exception("Post id must be specified");
				}
				foreach ($postList as $post) {
					$post->setUserAttitude($this->getUser(), Post::ATTITUDE_NORMAL);
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'report':
				if (!$postList) {
					throw new \Exception("Post id must be specified");
				}
				foreach ($postList as $post) {
					$post->setUserAttitude($this->getUser(), Post::ATTITUDE_REPORT);
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'delete':
				if (!$postList) {
					throw new \Exception("Post id must be specified");
				}
				foreach ($postList as $post) {
					$post->delete($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'recover':
				if (!$postList) {
					throw new \Exception("Post id must be specified");
				}
				foreach ($postList as $post) {
					$post->recover($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'erase':
				if (!$postList) {
					throw new \Exception("Post id must be specified");
				}
				foreach ($postList as $post) {
					$post->erase($this->getUser());
				}
				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;

			case 'post':
				if (!$page) {
					throw new \Exception("Page id must be specified");
				}
				$text = $this->getMain()->getVal('content');
				if (!$text) {
					throw new \Exception("Content must be specified");
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
				$postObject->post();

				$this->getResult()->addValue(null, $this->getModuleName(), '');
				break;
			default:
				throw new \Exception("Unknown action");
			}
			return true;
		} catch (\Exception $e) {
			$this->getResult()->addValue("error", 'code', $e->getMessage());
			$this->getResult()->addValue("error", 'info', $e->getMessage());
		}
	}

	public function getDescription() {
		return 'FlowThread action API';
	}

	public function getAllowedParams() {
		return array(
			'type' => array(
				\ApiBase::PARAM_TYPE => 'string',
			),
			'pageid' => array(
				\ApiBase::PARAM_TYPE => 'integer',
			),
			'postid' => array(
				\ApiBase::PARAM_TYPE => 'integer',
			),
			'content' => array(
				\ApiBase::PARAM_TYPE => 'string',
			),
			'wikitext' => array(
				\ApiBase::PARAM_TYPE => 'boolean',
			),
		);
	}

	public function getParamDescription() {
		return array(
			'type' => 'Type of action to take (post, list, like, dislike, delete, report, recover, erase)',
			'pageid' => 'The page id of the commented page',
			'postid' => 'The id of a certain comment',
			'content' => 'Content of comment',
			'wikitext' => 'Specify whether content is intepreted as wikitext or not',
		);
	}

	public function getExamplesMessages() {
		return array(
			'action=flowthread&pageid=1&type=list' => 'List all comments in article 1',
		);
	}
}
