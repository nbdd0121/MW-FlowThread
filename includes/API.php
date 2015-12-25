<?php
namespace FlowThread;

class API extends \ApiBase
{

    private function fetchPosts($pageid) {
        $page = Page::newFromId($pageid);

        $comments = array();
        foreach ($page->posts as $post) {
            // Filter out invisible posts (deleted posts, or one of those posts' parent is deleted)
            if($post->isVisible()) {
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
        if(!$postList) {
            return array();
        }
        $ret = array();
        foreach(explode('|', $postList) as $id) {
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
                    $page = $this->getMain()->getVal('pageid');
                    $this->getResult()->addValue(null, $this->getModuleName() , $this->fetchPosts($page));
                    break;

                case 'like':
                    foreach($postList as $post)
                        $post->setUserAttitude($this->getUser(), Post::ATTITUDE_LIKE);
                    break;

                case 'dislike':
                    foreach($postList as $post)
                        $post->setUserAttitude($this->getUser(), Post::ATTITUDE_NORMAL);
                    break;

                case 'report':
                    foreach($postList as $post)
                        $post->setUserAttitude($this->getUser(), Post::ATTITUDE_REPORT);
                    break;

                case 'post':
                    // Permission check
                    Post::checkIfCanPost($this->getUser());

                    $text = $this->getMain()->getVal('content');

                    // Parse as wikitext if specified
                    if($this->getMain()->getCheck('wikitext')) {
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
                        'status' => 0,
                        'like' => 0,
                        'report' => 0
                    );

                    $postObject = new Post($data);
                    $postObject->post();
                    break;

                case 'delete':
                    foreach($postList as $post)
                        $post->delete($this->getUser());
                    break;

                case 'recover':
                    foreach($postList as $post)
                        $post->recover($this->getUser());
                    break;

                case 'erase':
                    foreach($postList as $post)
                        $post->erase($this->getUser());
                    break;
            }
            return true;
        }
        catch(\Exception $e) {
            $this->getResult()->addValue(null, 'error', $e->getMessage());
        }
    }
    
    public function getDescription() {
        return 'FlowThread action API';
    }
    
    public function getAllowedParams() {
        return array(
            'type' => array(
                \ApiBase::PARAM_TYPE => 'string'
            ) ,
            'pageid' => array(
                \ApiBase::PARAM_TYPE => 'integer'
            ) ,
            'postid' => array(
                \ApiBase::PARAM_TYPE => 'integer'
            ) ,
            'content' => array(
                \ApiBase::PARAM_TYPE => 'string'
            ) ,
            'usewikitext' => array(
                \ApiBase::PARAM_TYPE => 'boolean'
            )
        );
    }
    
    public function getParamDescription() {
        return array(
            'type' => 'Type of action to take (post, list, like, dislike, delete, report, recover, erase)',
            'pageid' => 'The page id of the commented page',
            'postid' => 'The id of a certain comment',
            'content' => 'Content of comment',
            'usewikitext' => 'Specify whether content is intepreted as wikitext or not'
        );
    }
    
    public function getExamplesMessages() {
        return array(
            'action=flowthread&pageid=1&type=list' => 'List all comments in article 1'
        );
    }
}
