<?php
class Slick_App_Dashboard_Blog_Submissions_Controller extends Slick_App_ModControl
{
    
    function __construct()
    {
        parent::__construct();
        $this->model = new Slick_App_Dashboard_Blog_Submissions_Model;
        $this->user = Slick_App_Account_Home_Model::userInfo();
		$this->tca = new Slick_App_LTBcoin_TCA_Model;
		$this->inventory = new Slick_App_Dashboard_LTBcoin_Inventory_Model;
		$this->meta = new Slick_App_Meta_Model;
		$this->postModule = $this->model->get('modules', 'blog-post', array(), 'slug');
		$this->catModule = $this->model->get('modules', 'blog-category', array(), 'slug');        
		$this->blogApp = $this->model->get('apps', 'blog', array(), 'slug');
		$this->blogSettings = $this->meta->appMeta($this->blogApp['appId']);
        $this->postModel = new Slick_App_Blog_Post_Model;
    }
    
    function __install($moduleId)
    {
		$install = parent::__install($moduleId);
		if(!$install){
			return false;
		}
		
		$meta = new Slick_App_Meta_Model;
		$blogApp = $meta->get('apps', 'blog', array(), 'slug');
		$meta->updateAppMeta($blogApp['appId'], 'submission-fee', 1000, 'Article Submission Fee', 1);
		$meta->updateAppMeta($blogApp['appId'], 'submission-fee-token', 'LTBCOIN', 'Submission Fee Token', 1);
		
		$meta->addAppPerm($blogApp['appId'], 'canBypassSubmitFee');
		
		return $install;
	}
    
    public function init()
    {
		$output = parent::init();
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$this->data['perms'] = Slick_App_Meta_Model::getUserAppPerms($this->data['user']['userId'], 'blog');
		$this->data['perms'] = $tca->checkPerms($this->data['user'], $this->data['perms'], $postModule['moduleId'], 0, '');
		
        if(isset($this->args[2])){
			switch($this->args[2]){
				case 'view':
					$output = $this->showPosts();
					break;
				case 'add':
					$output = $this->addPost();
					break;
				case 'edit':
					$output = $this->editPost();
					break;
				case 'delete':
					$output = $this->deletePost();
					break;
				case 'preview':
					$output = $this->previewPost($output);
					break;
				case 'check-credits':
					$output = $this->checkCreditPayment();
					break;
				case 'trash':
					if(isset($this->args[3])){
						$output = $this->trashPost();
					}
					else{
						$output = $this->showPosts(1);
					}
					break;
				case 'restore':
					$output = $this->trashPost(true);
					break;
				case 'clear-trash':
					$output = $this->clearTrash();
					break;
				case 'compare':
					$output = $this->comparePostVersions();
					break;
				default:
					$output = $this->showPosts();
					break;
			}
		}
		else{
			$output = $this->showPosts();
		}
		$output['postModule'] = $this->postModule;
		$output['blogApp'] = $this->blogApp;
		$output['template'] = 'admin';
        $output['perms'] = $this->data['perms'];
       
        
        return $output;
    }
    
    /**
    * Shows a list of posts that the current user has submitted
    *
    * @return Array
    */
    private function showPosts($trash = 0)
    {
		$output = array('view' => 'list');
		$getPosts = $this->model->getAll('blog_posts', array('siteId' => $this->data['site']['siteId'],
															 'userId' => $this->data['user']['userId'],
															 'trash' => $trash), array(), 'postId');
															
		$output['totalPosts'] = 0;
		$output['totalPublished'] = 0;
		$output['totalViews'] = 0;
		$output['totalComments'] = 0;
		$disqus = new Slick_API_Disqus;
		foreach($getPosts as $key => $row){
			$postPerms = $this->tca->checkPerms($this->data['user'], $this->data['perms'], $this->postModule['moduleId'], $row['postId'], 'blog-post');
			$getPosts[$key]['perms'] = $postPerms;
			$output['totalPosts']++;
			if($row['published'] == 1){
				$output['totalPublished']++;
			}
			$output['totalViews']+=$row['views'];	
			$pageIndex = Slick_App_Controller::$pageIndex;
			$getIndex = extract_row($pageIndex, array('itemId' => $row['postId'], 'moduleId' => $this->postModule['moduleId']));
			$postURL = $this->data['site']['url'].'/blog/post/'.$row['url'];
			if($getIndex AND count($getIndex) > 0){
				$postURL = $this->data['site']['url'].'/'.$getIndex[count($getIndex) - 1]['url'];
			}			
			
			$comDiff = time() - strtotime($row['commentCheck']);
			$commentThread = false;
			if($comDiff > 1800){
				$commentThread = $disqus->getThread($postURL, false);
			}
			if($commentThread){
				$getPosts[$key]['commentCount'] = $commentThread['thread']['posts'];
				$this->model->edit('blog_posts', $row['postId'], array('commentCheck' => timestamp(), 'commentCount' => $commentThread['thread']['posts']));
				$output['totalComments'] += $commentThread['thread']['posts'];
			}
			else{
				$this->model->edit('blog_posts', $row['postId'], array('commentCheck' => timestamp()));
				$output['totalComments'] += $row['commentCount'];
			}
			
		}
		$output['postList'] = $getPosts;
		
		$output['submission_fee'] = $this->blogSettings['submission-fee'];
		$getDeposit = $this->meta->getUserMeta($this->user['userId'], 'article-credit-deposit-address');
		if(!$getDeposit){
			$btc = new Slick_API_Bitcoin(BTC_CONNECT);
			$accountName = XCP_PREFIX.'BLOG_CREDITS_'.$this->user['userId'];
			try{
				$getAddress = $btc->getaccountaddress($accountName);
			}
			catch(Exception $e){
				$getAddress = false;
			}
			$this->meta->updateUserMeta($this->user['userId'], 'article-credit-deposit-address', $getAddress);
			$output['credit_address'] = $getAddress;
		}
		else{
			$output['credit_address'] = $getDeposit;
		}
		$output['num_credits'] = intval($this->meta->getUserMeta($this->user['userId'], 'article-credits'));
		$output['fee_asset'] = strtoupper($this->blogSettings['submission-fee-token']);
		
		$output['trashCount'] = $this->model->countTrashItems($this->user['userId']);
		$output['trashMode'] = $trash;
		
		
		return $output;
	}
	
	
	private function addPost()
	{
		$output = array('view' => 'form');
		if(!$this->data['perms']['canWritePost']){
			$output['view'] = '403';
			return $output;
		}
		
		$output['num_credits'] = intval($this->meta->getUserMeta($this->user['userId'], 'article-credits'));
		if(!$this->data['perms']['canBypassSubmitFee'] AND $output['num_credits'] <= 0){
			Slick_Util_Session::flash('blog-message', 'You do not have enough submission credits to create a new post', 'error');
			$this->redirect($this->site.$this->moduleUrl);
			die();
		}
		
		$output['form'] = $this->model->getPostForm(0, $this->data['site']['siteId']);
		$output['formType'] = 'Submit';

		if(!$this->data['perms']['canPublishPost']){
			$output['form']->field('status')->removeOption('published');
			$output['form']->remove('featured');
		}
		if(!$this->data['perms']['canSetEditStatus']){
			$output['form']->field('status')->removeOption('editing');
		}
		if(!$this->data['perms']['canChangeEditor']){
			$output['form']->remove('editedBy');
		}		
		
		if(isset($this->data['perms']['canUseMagicWords']) AND !$this->data['perms']['canUseMagicWords']){
			$getField = $this->model->get('blog_postMetaTypes', 'magic-word', array(), 'slug');
			if($getField){
				$output['form']->remove('meta_'.$getField['metaTypeId']);
			}
		}
	
		if(!$this->data['perms']['canChangeAuthor']){
			$output['form']->remove('userId');
		}
		else{
			$output['form']->setValues(array('userId' => $this->data['user']['userId']));
		}

		if(posted()){
			$data = $output['form']->grabData();
			if(isset($data['publishDate'])){
				$data['publishDate'] = date('Y-m-d H:i:s', strtotime($data['publishDate']));
			}			
			$data['siteId'] = $this->data['site']['siteId'];
			if(!$this->data['perms']['canChangeAuthor']){
				$data['userId'] = $this->user['userId'];
			}
			if(!$this->data['perms']['canPublishPost']){
				if(isset($data['published'])){
					unset($data['published']);
				}
				if(isset($data['featured'])){
					unset($data['featured']);
				}
				if(isset($data['status']) AND $data['status'] == 'published'){
					$data['status'] = 'draft';
				}
			}
			if(!$this->data['perms']['canSetEditStatus']){
				if(isset($data['status']) AND $data['status'] == 'editing'){
					$data['status'] = 'draft';
				}
			}			
			if($data['autogen-excerpt'] == 0){
				$data['excerpt'] = shortenMsg(strip_tags($data['content']), 500);
			}			
			try{
				$add = $this->model->addPost($data, $this->data);
			}
			catch(Exception $e){
				Slick_Util_Session::flash('blog-message', $e->getMessage(), 'error');
				$add = false;
			}
			
			if($add){
				if(!$this->data['perms']['canBypassSubmitFee']){
					//deduct from their current credits
					$newCredits = $output['num_credits'] - 1;
					$this->meta->updateUserMeta($this->user['userId'], 'article-credits', $newCredits);
				}
				
				$this->redirect($this->site.$this->moduleUrl);
			}
			else{
				$this->redirect($this->site.$this->moduleUrl.'/add');
			}
			
			return;
		}
		
		$output['form']->field('publishDate')->setValue(date('Y/m/d H:i'));
		
		return $output;
		
	}
	
	protected function accessPost()
	{
		if(!isset($this->args[3])){
			throw new Exception('404');
		}		
		
		$getPost = $this->model->get('blog_posts', $this->args[3]);
		if(!$getPost){
			throw new Exception('404');
		}

		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');	

		$this->data['perms'] = $tca->checkPerms($this->data['user'], $this->data['perms'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		
		if(($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canEditSelfPost'])
		OR ($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canEditOtherPost'])){
			throw new Exception('403');
		}
		
		if($getPost['published'] == 1 AND !$this->data['perms']['canEditAfterPublished']){
			throw new Exception('403');
		}
		
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			throw new Exception('403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				throw new Exception('403');
			}
		}	
		
		$getPost['categories'] = $this->model->getPostFormCategories($getPost['postId']);
		$getPost['author'] = $this->model->get('users', $getPost['userId']);
		$getPost['editor'] = $this->model->get('users', $getPost['editedBy']);
		
		return $getPost;
	}
	
	protected function editPost()
	{
		try{
			$getPost = $this->accessPost();
		}
		catch(Exception $e){
			return array('view' => $e->getMessage());
		}
		
		$output = array('view' => 'form');
		$output['form'] = $this->model->getPostForm($getPost['postId'], $this->data['site']['siteId']);
		$output['formType'] = 'Edit';
		$output['post'] = $getPost;
		
		if(isset($this->data['perms']['canUseMagicWords'])){
			if(!$this->data['perms']['canUseMagicWords']){
				$getField = $this->model->get('blog_postMetaTypes', 'magic-word', array(), 'slug');
				if($getField){
					$output['form']->remove('meta_'.$getField['metaTypeId']);
				}
			}
			else{
				$getWords = $this->model->getAll('pop_words', array('itemId' => $getPost['postId'],
																	'moduleId' => $this->postModule['moduleId']),
																array('submitId'));
				$output['magic_word_count'] = count($getWords);
			}
		}
		
		$this->data['post'] = $getPost;
		
		if(!$this->data['perms']['canPublishPost']){
			if($getPost['published'] == 1){
				$output['form']->field('status')->addAttribute('disabled');
			}
			else{
				$output['form']->field('status')->removeOption('published');
			}
			$output['form']->remove('featured');
		}
		if(!$this->data['perms']['canChangeEditor']){
			$output['form']->remove('editedBy');
		}
		if(!$this->data['perms']['canSetEditStatus']){
			$output['form']->field('status')->removeOption('editing');
		}
		if(!$this->data['perms']['canChangeAuthor']){
			$output['form']->remove('userId');
		}
		
		//$getPost['status'] = '';
		if($getPost['published'] == 1){
			$getPost['status'] = 'published';
		}
		elseif($getPost['ready'] == 1){
			$getPost['status'] = 'ready';
		}
		/*else{
			$getPost['status'] = 'draft';
		}*/
		
		if(posted() AND !isset($_POST['no_edit'])){
			$data = $output['form']->grabData();
			if(isset($data['publishDate'])){
				$data['publishDate'] = date('Y-m-d H:i:s', strtotime($data['publishDate']));
			}
			$data['siteId'] = $this->data['site']['siteId'];
			if(!$this->data['perms']['canChangeAuthor']){
				$data['userId'] = false;
			}
			//$data['userId'] = $this->user['userId'];
			if(!$this->data['perms']['canPublishPost']){
				if($getPost['published'] == 0){
					if(isset($data['status']) AND $data['status'] == 'published'){
						$data['status'] = 'draft';
					}
				}
				else{
					$data['status'] = 'published';
				}
				if(!isset($data['status'])){
					$data['status'] = $getPost['status'];
				}

				if(isset($data['featured'])){
					unset($data['featured']);
				}
			}
			if(!$this->data['perms']['canSetEditStatus']){
				if(isset($data['status']) AND $data['status'] == 'editing'){
					$data['status'] = 'draft';
				}
			}
			if($data['autogen-excerpt'] == 0){
				$data['excerpt'] = shortenMsg(strip_tags($data['content']), 500);
			}
			try{
				$edit = $this->model->editPost($this->args[3], $data, $this->data);
			}
			catch(Exception $e){
				Slick_Util_Session::flash('blog-message', $e->getMessage(), 'error');			
				$edit = false;
			}
			
			if($edit){
				Slick_Util_Session::flash('blog-message', 'Post edited successfully!', 'success');
			}
			$this->redirect($this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$getPost['postId']);
			return true;
		}	
		
		//get version list and #
		$output['versions'] = $this->model->getVersions($getPost['postId']);
		$output['current_version'] = $this->model->getVersionNum($getPost['postId']);
		$output['old_version'] = false;
		
		if(isset($this->args[4])){
			foreach($output['versions'] as $version){
				if($version['num'] == $this->args[4]){
					$oldVersion = $this->model->getPostVersion($getPost['postId'], $version['num']);
					if($oldVersion AND $oldVersion['versionId'] != $getPost['version']){
						if(isset($this->args[5]) AND $this->args[5] == 'delete'){
							if(($getPost['userId'] == $this->data['user']['userId'] AND $this->data['perms']['canDeleteSelfPostVersion'])
								OR
							 ($getPost['userId'] != $this->data['user']['userId'] AND $this->data['perms']['canDeleteOtherPostVersion'])){
								$killVersion = $this->model->delete('content_versions', $oldVersion['versionId']);
								Slick_Util_Session::flash('blog-message', 'Version #'.$oldVersion['num'].' removed', 'success');
								$this->redirect($this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$getPost['postId']);
								die();
							}
						}
						$output['post']['content'] = $oldVersion['content']['content'];
						$output['post']['excerpt'] = $oldVersion['content']['excerpt'];
						$output['old_version'] = $oldVersion;
						$getPost['content'] = $output['post']['content'];
						$getPost['excerpt'] = $output['post']['excerpt'];
						$output['post']['formatType'] = $oldVersion['formatType'];
						$getPost['formatType'] = $oldVersion['formatType'];
						if($oldVersion['formatType'] == 'wysiwyg'){
							$output['form']->field('content')->setLivePreview(false);
							$output['form']->field('content')->setID('html-editor');
							$output['form']->field('excerpt')->setLivePreview(false);
							$output['form']->field('excerpt')->setID('mini-editor');							
						}
					}
					break;
				}
			}
		}		
		
		//private editorial discussion
		$output['comment_form'] = $this->postModel->getCommentForm();
		$output['private_comments'] = $this->postModel->getPostComments($getPost['postId'], 1);
		$output['comment_list_hash'] = $this->model->getCommentListHash($getPost['postId']);
		if(isset($this->args[4]) AND $this->args[4] == 'comments'){
			if(isset($this->args[5])){
				switch($this->args[5]){
					case 'post':
						$json = $this->postPrivateComment();
						break;
					case 'edit':
						$json = $this->editPrivateComment();
						break;
					case 'delete':
						$json = $this->deletePrivateComment();
						break;
					case 'check':
						$json = $this->checkCommentList();
						break;
					case 'get':
					default:
						$json = $this->getPrivateComments();
						break;
				}
				
				ob_end_clean();
				header('Content-Type: application/json');
				echo json_encode($json);
				die();
			}
		}
		
		//setup form values
		$output['form']->setValues($getPost);
		$output['form']->field('publishDate')->setValue(date('Y/m/d H:i', strtotime($getPost['publishDate'])));
		
		return $output;
		
	}
	
	protected function postPrivateComment()
	{
		$output = array('error' => null);
		
		if(!posted()){
			http_response_code(400);
			$output['error'] = 'Invalid request method';
			return $output;
		}
		
		if(!$this->data['perms']['canPostComment']){
			http_response_code(403);
			$output['error'] = 'You do not have permission for this';
			return $output;
		}
		
		if(!isset($_POST['message'])){
			http_response_code(400);
			$output['error'] = 'Message required';
			return $output;
		}
		
		$data = array();
		$data['postId'] = $this->data['post']['postId'];
		$data['userId'] = $this->data['user']['userId'];
		$data['message'] = strip_tags($_POST['message']);
		
		try{
			$postComment = $this->postModel->postComment($data, $this->data, 1);
		}
		catch(Exception $e){
			http_response_code(400);
			$output['error'] = $e->getMessage();
			return $output;
		}
		
		$output['result'] = 'success';
		$postComment['formatDate'] = formatDate($postComment['commentDate']);
		$postComment['html_content'] = markdown($postComment['message']);
		$postComment['encoded'] = base64_encode($postComment['message']);
		$profModel = new Slick_App_Profile_User_Model;
		$authProf = $profModel->getUserProfile($postComment['userId']);
		$postComment['author'] = array('username' => $authProf['username'], 'slug' => $authProf['slug'], 'avatar' => $authProf['avatar']);
		$output['comment'] = $postComment;
		$output['new_hash'] = $this->model->getCommentListHash($this->data['post']['postId']);
		
		return $output;
		
	}
	
	protected function deletePrivateComment()
	{
		$output = array('error' => null);
		
		if(!posted()){
			http_response_code(400);
			$output['error'] = 'Invalid request method';
			return $output;
		}	
		
		if(!isset($_POST['commentId'])){
			http_response_code(400);
			$output['error'] = 'Comment ID required';
			return $output;
		}
		
		$comment = $this->model->get('blog_comments', $_POST['commentId']);
		if(!$comment){
			http_response_code(400);
			$output['error'] = 'Invalid comment ID';
			return $output;
		}
		
		if(($comment['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfComment'])
			OR ($comment['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherComment'])){
			http_response_code(403);
			$output['error'] = 'You do not have permission for this';
			return $output;
		}			
		
		$delete = $this->model->delete('blog_comments', $comment['commentId']);
		$output['result'] = 'success';
	
		return $output;
	}
	
	protected function editPrivateComment()
	{
		$output = array('error' => null);
		
		if(!posted()){
			http_response_code(400);
			$output['error'] = 'Invalid request method';
			return $output;
		}	
		
		if(!isset($_POST['commentId'])){
			http_response_code(400);
			$output['error'] = 'Comment ID required';
			return $output;
		}
		
		if(!isset($_POST['message'])){
			http_response_code(400);
			$output['error'] = 'Message';
			return $output;
		}
		
		$comment = $this->model->get('blog_comments', $_POST['commentId']);
		if(!$comment){
			http_response_code(400);
			$output['error'] = 'Invalid comment ID';
			return $output;
		}
		
		if(($comment['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canEditSelfComment'])
			OR ($comment['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canEditOtherComment'])){
			http_response_code(403);
			$output['error'] = 'You do not have permission for this';
			return $output;
		}
		
		$data = array();
		$data['message'] = strip_tags($_POST['message']);
		$data['editTime'] = timestamp();
		
		$edit = $this->model->edit('blog_comments', $comment['commentId'], $data);

		$output['result'] = 'success';
		$comment['formatDate'] = formatDate($comment['commentDate']);
		$comment['formatEditDate'] = formatDate($data['editTime']);
		$comment['html_content'] = markdown($data['message']);
		$comment['encoded'] = base64_encode($data['message']);
		$profModel = new Slick_App_Profile_User_Model;
		$authProf = $profModel->getUserProfile($comment['userId']);
		$comment['author'] = array('username' => $authProf['username'], 'slug' => $authProf['slug'], 'avatar' => $authProf['avatar']);
		$output['comment'] = $comment;
		$output['new_hash'] = $this->model->getCommentListHash($this->data['post']['postId']);
		
		return $output;
	}
	
	protected function checkCommentList()
	{
		$hash = $this->model->getCommentListHash($this->data['post']['postId']);
		return array('hash' => $hash);
	}
	
	protected function getPrivateComments()
	{
		$comments = $this->postModel->getPostComments($this->data['post']['postId'], 1);
		foreach($comments as &$comment){
			$comment['author'] = array('username' => $comment['author']['username'],
									   'slug' => $comment['author']['slug'],
									   'avatar' => $comment['author']['avatar']);
			$comment['html_content'] = markdown($comment['message']);
			$comment['encoded'] = base64_encode($comment['message']);
			$comment['formatDate'] = formatDate($comment['commentDate']);
			$comment['formatEditDate'] = formatDate($comment['editTime']);
			unset($comment['buried']);
			unset($comment['editorial']);
			unset($comment['postId']);
			
		}
		$output['comments'] = $comments;
		$output['new_hash'] = $this->model->getCommentListHash($this->data['post']['postId']);
		
		return $output;
	}

	
	private function deletePost()
	{
		if(!isset($this->args[3])){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		$getPost = $this->model->get('blog_posts', $this->args[3]);
		if(!$getPost){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		if(($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfPost'])
		OR ($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherPost'])){
			return array('view' => '403');
		}

		if($getPost['published'] == 1 AND !$this->data['perms']['canPublishPost']){
			return array('view' => '403');
		}
		
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			return array('view' => '403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				return array('view' => '403');
			}
		}			
		
		$delete = $this->model->delete('blog_posts', $this->args[3]);
		Slick_Util_Session::flash('blog-message', $getPost['title'].' deleted successfully', 'success');
		
		$this->redirect($this->site.$this->moduleUrl.'/trash');
		return true;
	}
	
	private function previewPost($output)
	{
		if(!isset($this->args[3])){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		$model = new Slick_App_Blog_Post_Model;
		$getPost = $model->getPost($this->args[3], $this->data['site']['siteId']);
		if(!$getPost){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		if(isset($this->args[4])){
			$oldVersion = $this->model->getPostVersion($getPost['postId'], $this->args[4]);
			if($oldVersion){
				$getPost['content'] = $oldVersion['content']['content'];
				$getPost['excerpt'] = $oldVersion['content']['excerpt'];
			}
		}	
		
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');
		$this->data['perms'] = $tca->checkPerms($this->data['user'], $this->data['perms'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			return array('view' => '403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				return array('view' => '403');
			}
		}				
		
		$cats = array();
		foreach($getCategories as $cat){
			$getCat = $this->model->get('blog_categories', $cat['categoryId']);
			$cats[] = $getCat;
		}
		$getPost['categories'] = $cats;
		
		$output['template'] = 'blog';
		$output['view'] = '';
		$output['force-view'] = 'Blog/Post/post';
		$output['post'] = $getPost;
		$output['disableComments'] = true;
		$output['user'] = Slick_App_Account_Home_Model::userInfo();
		$output['title'] = $getPost['title'];
		$output['commentError'] = '';
		$output['comments'] = array();
		

		return $output;
		
	}
	
	
	protected function checkCreditPayment()
	{
		ob_end_clean();
		header('Content-Type: application/json');		
		$output = array('result' => null, 'error' => null);
		if(isset($_SESSION['blog-credit-check-progress'])){
			unset($_SESSION['blog-credit-check-progress']);
			echo json_encode($output);
			die();
		}
		$_SESSION['blog-credit-check-progress'] = 1;
		
		//get latest deposit address
		$getAddress = $this->meta->getUserMeta($this->user['userId'], 'article-credit-deposit-address');
		if(!$getAddress){
			http_response_code(400);
			$output['error'] = 'No deposit address found';
		}
		else{
			//check balances including the mempool
			$assetInfo = $this->inventory->getAssetData($this->blogSettings['submission-fee-token']);
			$xcp = new Slick_API_Bitcoin(XCP_CONNECT);
			$btc = new Slick_API_Bitcoin(BTC_CONNECT);
			try{
				$getPool = $xcp->get_mempool();
				$getBalances = $xcp->get_balances(array('filters' => array('field' => 'address', 'op' => '=', 'value' => $getAddress)));
				
				$received = 0;
				$confirmCoin = 0;
				$newCoin = 0;
				foreach($getBalances as $balance){
					if($balance['asset'] == $assetInfo['asset']){
						$confirmCoin = $balance['quantity'];
						if($assetInfo['divisible'] == 1 AND $confirmCoin > 0){
							$confirmCoin = $confirmCoin / SATOSHI_MOD;
						}
						$received+= $confirmCoin;
					}
				}
				foreach($getPool as $pool){
					if($pool['category'] == 'sends'){
						$parse = json_decode($pool['bindings'], true);
						if($parse['destination'] == $getAddress AND $parse['asset'] == $assetInfo['asset']){
							//check TX to make sure its an actual unconfirmed transaction
							$getTx = $btc->gettransaction($pool['tx_hash']);
							if($getTx AND $getTx['confirmations'] == 0){
								$newCoin = $parse['quantity'];
								if($assetInfo['divisible'] == 1 AND $newCoin > 0){
									$newCoin = $newCoin / SATOSHI_MOD;
								}
								$received+= $newCoin;
							}
						}
					}
				}
			}
			catch(Exception $e){
				http_response_code(400);
				$output['error'] = 'Error retrieving data from xcp server';
			}
			
			//check for previous payment orders on this address, deduct from total seen
			$prevOrders = $this->model->getAll('payment_order', array('address' => $getAddress, 'orderType' => 'blog-submission-credits'));
			$pastOrdered = 0;
			foreach($prevOrders as $prevOrder){
				$prevData = json_decode($prevOrder['orderData'], true);
				$pastOrdered += $prevData['new-received'];
			}
			
			$received -= $pastOrdered;

			//calculate change, number of credits etc.
			$getChange = floatval($this->meta->getUserMeta($this->user['userId'], 'article-credit-deposit-change'));
			$getCredits = intval($this->meta->getUserMeta($this->user['userId'], 'article-credits'));
			$submitFee = intval($this->blogSettings['submission-fee']);
			$origReceived = $received;
			$received += $getChange;
			$leftover = $received % $submitFee;
			$numCredits = floor($received / $submitFee);
			
			//check if enough for at least 1 credit
			if($numCredits > 0){
				
				//save as store order
				$orderData = array();
				$orderData['userId'] = $this->user['userId'];
				$orderData['credits'] = $numCredits;
				$orderData['credit-price'] = $submitFee;
				$orderData['new-received'] = $origReceived;
				$orderData['previous-change'] = $getChange;
				$orderData['leftover-change'] = $leftover;
				
				$order = array();
				$order['address'] = $getAddress;
				$order['account'] = XCP_PREFIX.'BLOG_CREDITS_'.$this->user['userId'];
				$order['amount'] = $numCredits * $submitFee;
				$order['asset'] = $assetInfo['asset'];
				$order['received'] = $origReceived;
				$order['complete'] = 1;
				$order['orderTime'] = timestamp();
				$order['orderType'] = 'blog-submission-credits';
				$order['completeTime'] = $order['orderTime'];
				$order['orderData'] = json_encode($orderData);
				
				$saveOrder = $this->model->insert('payment_order', $order);
				if(!$saveOrder){
					http_response_code(400);
					$output['error'] = 'Error saving payment order';
					echo json_encode($output);
					die();					
				}
				
				//save credits and leftover change
				$newCredits = $getCredits + $numCredits;
				$updateCredits = $this->meta->updateUserMeta($this->user['userId'], 'article-credits', $newCredits);
				$updateChange = $this->meta->updateUserMeta($this->user['userId'], 'article-credit-deposit-change', $leftover);
			
				//setup response data
				$output['result'] = 'success';
				$output['credits'] = $newCredits;
				$output['new_credits'] = $numCredits;
				$output['received'] = $origReceived;
				$output['old_change'] = $getChange;
				$output['new_change'] = $leftover;
			}
			else{
				$output['result'] = 'none';	
			}
		}
		
		ob_end_clean();
		unset($_SESSION['blog-credit-check-progress']);
		echo json_encode($output);
		die();
	}
	
	private function trashPost($restore = false)
	{
		if(!isset($this->args[3])){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		$getPost = $this->model->get('blog_posts', $this->args[3]);
		if(!$getPost){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		if(($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfPost'])
		OR ($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherPost'])){
			return array('view' => '403');
		}

		if($getPost['published'] == 1 AND !$this->data['perms']['canPublishPost']){
			return array('view' => '403');
		}
		
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			return array('view' => '403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				return array('view' => '403');
			}
		}			
		
		if($restore){
			$restorePost = $this->model->edit('blog_posts', $this->args[3], array('trash' => 0));
			Slick_Util_Session::flash('blog-message', $getPost['title'].' restored from trash', 'success');
			$this->redirect($this->site.$this->moduleUrl.'/trash');
		}
		else{
			$delete = $this->model->edit('blog_posts', $this->args[3], array('trash' => 1));
			Slick_Util_Session::flash('blog-message', $getPost['title'].' moved to trash', 'success');
			$this->redirect($this->site.$this->moduleUrl);
		}
		return true;
	}		
		
	private function clearTrash()
	{

		$trashPosts = $this->model->getAll('blog_posts', array('siteId' => $this->data['site']['siteId'],
															 'userId' => $this->user['userId'], 
															 'trash' => 1));
															 
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');															 
		
		foreach($trashPosts as $getPost){
			if(($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfPost'])
			OR ($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherPost'])){
				return array('view' => '403');
			}

			if($getPost['published'] == 1 AND !$this->data['perms']['canPublishPost']){
				return array('view' => '403');
			}
			
			$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
			if(!$postTCA){
				return array('view' => '403');
			}
			$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
			foreach($getCategories as $cat){
				$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
				if(!$catTCA){
					return array('view' => '403');
				}
			}			
			
			$delete = $this->model->delete('blog_posts', $getPost['postId']);
		}
		
		Slick_Util_Session::flash('blog-message', 'Trash bin emptied!', 'success');
		$this->redirect($this->site.$this->moduleUrl.'/trash');
	
		return true;
	}		
	
	public function comparePostVersions()
	{
		try{
			$getPost = $this->accessPost();
		}
		catch(Exception $e){
			return array('view' => $e->getMessage());
		}
		
		$v1 = false;
		$v2 = false;
		if(isset($this->args[4])){
			$v1 = intval($this->args[4]);
		}
		if(isset($this->args[4])){
			$v2 = intval($this->args[5]);
		}
		
		$compare = $this->model->comparePostVersions($getPost['postId'], $v1, $v2);
		$compare['v1_user'] = array('userId' => $compare['v1_user']['userId'], 'username' => $compare['v1_user']['username'], 'slug' => $compare['v1_user']['slug']);
		$compare['v2_user'] = array('userId' => $compare['v2_user']['userId'], 'username' => $compare['v2_user']['username'], 'slug' => $compare['v2_user']['slug']);
		
		ob_end_clean();
		header('Content-Type: application/json');
		$output = $compare;
		
		echo json_encode($output);
		die();
	}	

}
