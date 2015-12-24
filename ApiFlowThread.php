<?php
define('FLOW_THREAD_ATT_NORM', 0);
define('FLOW_THREAD_ATT_LIKE', 1);
define('FLOW_THREAD_ATT_REPO', 2);

class ApiFlowThread extends ApiBase
{
    private function fetchPosts($pageid) {
        $page = FlowThreadPage::newFromId($pageid);

        $comments = array();
        foreach ($page->posts as $post) {
            // Filter out invisible posts (deleted posts, or one of those posts' parent is deleted)
            if($post->isVisible()) {
                $favorCount = $post->getFavorCount();
                $myAtt = $post->getUserAttitude($this->getUser());

                $data = array(
                    'id' => $post->id,
                    'userid' => $post->userid,
                    'username' => $post->username,
                    'text' => $post->text,
                    'timestamp' => wfTimestamp(TS_UNIX, $post->timestamp),
                    'parentid' => $post->parentid,
                    'like' => $favorCount,
                    'myatt' => $myAtt,
                );
                
                $comments[] = $data;
            }
        }
        
        return $comments;
    }
    
    public function execute() {
        $action = $this->getMain()->getVal('type');
        $page = $this->getMain()->getVal('pageid');
        $post = $this->getMain()->getVal('postid');
        try {
            switch ($action) {
                case 'list':
                    $page = $this->getMain()->getVal('pageid');
                    $this->getResult()->addValue(null, $this->getModuleName() , $this->fetchPosts($page));
                    break;

                case 'like':
                    $postObject = FlowThreadPost::newFromId($post);
                    $postObject->setUserAttitude($this->getUser(), FLOW_THREAD_ATT_LIKE);
                    break;

                case 'dislike':
                    $postObject = FlowThreadPost::newFromId($post);
                    $postObject->setUserAttitude($this->getUser(), FLOW_THREAD_ATT_NORM);
                    break;

                case 'report':
                    $postObject = FlowThreadPost::newFromId($post);
                    $postObject->setUserAttitude($this->getUser(), FLOW_THREAD_ATT_REPO);
                    break;

                case 'post':
                    FlowThreadPost::checkIfCanPost($this->getUser());
                    $text = $this->getMain()->getVal('content');
                    if($this->getMain()->getCheck('wikitext')) {
                        $parser = new Parser();
                        $opt = new ParserOptions($this->getUser());
                        $opt->setEditSection(false);
                        $output = $parser->parse($text, Title::newFromId($page), $opt);
                        $text = $output->getText();
                        unset($parser);
                        unset($opt);
                        unset($output);
                    }
                    $data = array(
                        'id' => 0,
                        'pageid' => $page,
                        'userid' => $this->getUser()->getId(),
                        'username' => $this->getUser()->getName(),
                        'text' => $text,
                        'timestamp' => wfTimestamp(TS_MW),
                        'parentid' => $post,
                        'status' => 0
                    );
                    $postObject = new FlowThreadPost($data);
                    $postObject->post();
                    break;

                case 'delete':
                    $postObject = FlowThreadPost::newFromId($post);
                    $postObject->delete($this->getUser());
                    break;

                case 'recover':
                    $postObject = FlowThreadPost::newFromId($post);
                    $postObject->recover($this->getUser());
                    break;

                case 'erase':
                    $postObject = FlowThreadPost::newFromId($post);
                    $postObject->erase($this->getUser());
                    break;
            }
            return true;
        }
        catch(Exception $e) {
            $this->getResult()->addValue(null, 'error', $e->getMessage());
        }
    }
    
    public function getDescription() {
        return 'FlowThread action API';
    }
    
    public function getAllowedParams() {
        return array(
            'type' => array(
                ApiBase::PARAM_TYPE => 'string'
            ) ,
            'pageid' => array(
                ApiBase::PARAM_TYPE => 'integer'
            ) ,
            'postid' => array(
                ApiBase::PARAM_TYPE => 'integer'
            ) ,
            'content' => array(
                ApiBase::PARAM_TYPE => 'string'
            ) ,
        );
    }
    
    public function getParamDescription() {
        return array(
            'type' => 'Type of action to take (post, list, like, dislike, delete, report)',
            'pageid' => 'The page id of the commented page',
            'postid' => 'The id of a certain comment'
        );
    }
    
    public function getExamplesMessages() {
        return array(
            'action=flowthread&pageid=0&type=list' => 'List all comments in 0'
        );
    }
}
