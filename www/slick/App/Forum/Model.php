<?php
class Slick_App_Forum_Model extends Slick_Core_Model
{
	function __construct()
	{
		parent::__construct();
		$this->tca = new Slick_App_LTBcoin_TCA_Model;
		$this->boardModule = $this->get('modules', 'forum-board', array(), 'slug');
		$this->postModule = $this->get('modules', 'forum-post', array(), 'slug');
		$this->profileModule = $this->get('modules', 'user-profile', array(), 'slug');
	}
	
	public function getForumCategories($site, $app, $user = false)
	{
		$siteId = $site['siteId'];
		$getCats = $this->getAll('forum_categories', array('siteId' => $siteId), array(), 'rank', 'asc');
		$boardModule = $this->boardModule;
		$profModel = new Slick_App_Profile_User_Model;
		$tca = $this->tca;
		$userId = 0;
		if($user){
			$userId = $user['userId'];
		}
		foreach($getCats as $k => $cat){
			$getBoards = $this->getAll('forum_boards', array('categoryId' => $cat['categoryId'], 'siteId' => $siteId,
														'active' => 1), array(), 'rank', 'asc');
			
			$checkTCA = $tca->checkItemAccess($userId, $boardModule['moduleId'], $cat['categoryId'], 'category');
			if(!$checkTCA){
				unset($getCats[$k]);
				continue;
			}
															
			foreach($getBoards as $bk => $board){
				$checkTCA = $tca->checkItemAccess($userId, $boardModule['moduleId'], $board['boardId'], 'board');
				if(!$checkTCA){
					unset($getBoards[$bk]);
					continue;
				}
				$getBoards[$bk]['numTopics'] = $this->count('forum_topics', 'boardId', $board['boardId']);
				$countReplies = $this->fetchSingle('SELECT COUNT(*) as total 
													FROM forum_posts p
													LEFT JOIN forum_topics t ON t.topicId = p.topicId
													WHERE t.boardId = :boardId', array(':boardId' => $board['boardId']));
				$getBoards[$bk]['numReplies'] = $countReplies['total'];
				
				$lastTopic = $this->getLastBoardTopic($board, $userId);
				$lastPost = $this->getLastBoardPost($board, $userId);

				$topicTime = 0;
				if($lastTopic){
					$topicTime = strtotime($lastTopic['postTime']);
				}
				$postTime = 0;
				if($lastPost){
					$postTime = strtotime($lastPost['postTime']);
				}
				
				if($topicTime === 0 AND $postTime === 0){
					$getBoards[$bk]['mostRecent'] = '';
				}
				elseif($topicTime > $postTime){
					//recent topic
					$lastAuthor = $profModel->getUserProfile($lastTopic['userId'], $site['siteId']);
					$authorTCA = $this->tca->checkItemAccess($user, $this->profileModule['moduleId'], $lastAuthor['userId'], 'user-profile');
					$authorLink = $lastAuthor['username'];
					if($authorTCA){
						$authorLink = '<a href="'.$site['url'].'/profile/user/'.$lastAuthor['slug'].'">'.$authorLink.'</a>';
					}
					
					$getBoards[$bk]['mostRecent'] = '<a href="'.$site['url'].'/'.$app['url'].'/post/'.$lastTopic['url'].'"  title="'.str_replace('"', '', shorten(strip_tags($lastTopic['content']), 150)).'">'.$lastTopic['title'].'</a> by
													'.$authorLink;
				}
				else{
					//recent post
					$lastAuthor = $profModel->getUserProfile($lastPost['userId'], $site['siteId']);
					$authorTCA = $this->tca->checkItemAccess($user, $this->profileModule['moduleId'], $lastAuthor['userId'], 'user-profile');
					$authorLink = $lastAuthor['username'];
					if($authorTCA){
						$authorLink = '<a href="'.$site['url'].'/profile/user/'.$lastAuthor['slug'].'">'.$authorLink.'</a>';
					}
										
					$lastTopic = $this->get('forum_topics', $lastPost['topicId']);
					$numReplies = $this->count('forum_posts', 'topicId', $lastPost['topicId']);
					$numPages = ceil($numReplies / $app['meta']['postsPerPage']);
					$andPage = '';
					if($numPages > 1){
						$andPage = '?page='.$numPages;
					}
					$getBoards[$bk]['mostRecent'] = 'Reply to <a href="'.$site['url'].'/'.$app['url'].'/post/'.$lastTopic['url'].$andPage.'#post-'.$lastPost['postId'].'" title="'.str_replace('"', '', shorten(strip_tags($lastPost['content']), 150)).'">'.$lastTopic['title'].'</a> by
													'.$authorLink;
				}
				
			}
			$getCats[$k]['boards'] = $getBoards;
		}
		
		return $getCats;
	}
	
	public function getLastBoardTopic($board, $userId, $offset = 0)
	{
		$lastTopic = $this->fetchSingle('SELECT * 
										FROM forum_topics
										WHERE boardId = :id AND trollPost = 0 AND buried = 0
										ORDER BY topicId DESC
										LIMIT '.$offset.', 1', array(':id' => $board['boardId']));
		if(!$lastTopic){
			return false;
		}			
		$checkTCA = $this->tca->checkItemAccess($userId, $this->postModule['moduleId'], $lastTopic['topicId'], 'topic');
		if(!$checkTCA){
			return $this->getLastBoardTopic($board, $userId, ($offset+1));
		}
		return $lastTopic;
	}
	
	public function getLastBoardPost($board, $userId, $offset = 0)
	{
		$lastPost = $this->fetchSingle('SELECT p.* 
										FROM forum_posts p
										LEFT JOIN forum_topics t ON t.topicId = p.topicId
										WHERE t.boardId = :id AND p.buried = 0 AND p.trollPost = 0 AND t.buried = 0
										ORDER BY p.postId DESC
										LIMIT '.$offset.', 1', array(':id' => $board['boardId']));					
		if(!$lastPost){
			return false;
		}						
		$checkTCA = $this->tca->checkItemAccess($userId, $this->postModule['moduleId'], $lastPost['topicId'], 'topic');
		if(!$checkTCA){
			return $this->getLastBoardPost($board, $userId, ($offset+1));
		}										
		return $lastPost;
	}
}
