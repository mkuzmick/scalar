<?php
/**
 * @projectDescription		Book controller for outputting the HTML for Scalar's front-end
 * @author					Craig Dietrich
 * @version					2.3
 */

function sortSearchResults($a, $b) {
	$x = strtolower($a->versions[key($a->versions)]->title);
	$y = strtolower($b->versions[key($a->versions)]->title);
    return strcmp($x, $y);	
}

class Book extends MY_Controller {

	private $template_has_rendered = false;
	private $models = array('annotations', 'paths', 'tags', 'replies', 'references');
	private $rel_fields = array('start_seconds','end_seconds','start_line_num','end_line_num','points','datetime','paragraph_num');
	private $vis_views = array('vis', 'visindex', 'vispath', 'vismedia', 'vistag');

	/**
	 * Load the current book
	 */
	
	public function __construct() {

		parent::__construct();
		$this->load->model('book_model', 'books');
		$this->load->model('page_model', 'pages');
		$this->load->model('version_model', 'versions');	
		$this->load->model('annotation_model', 'annotations');
		$this->load->model('path_model', 'paths');
		$this->load->model('tag_model', 'tags');
		$this->load->model('reply_model', 'replies');
		$this->load->model('reference_model', 'references');
		$this->load->library('RDF_Object', 'rdf_object');
		$this->load->library('statusCodes');	
		$this->load->helper('inflector');	
	
		$this->data['book'] = $this->data['page'] = null;	
		// Book being asked for
		$this->scope = strtolower($this->uri->segment('1'));
		$this->data['book'] = (!empty($this->scope)) ? $this->books->get_by_slug($this->scope) : null;
		if (empty($this->data['book'])) show_404();	// Book couldn't be found
		$this->set_user_book_perms(); 
		if (!$this->data['book']->url_is_public && !$this->login_is_book_admin('reader')) $this->require_login(1); // Protect book
		$this->data['book']->contributors = $this->books->get_users($this->data['book']->book_id);
		$this->data['base_uri'] = confirm_slash(base_url()).confirm_slash($this->data['book']->slug);
		// Template
		$this->data['template'] = $this->template->config['active_template'];
		if (isset($_GET['template']) && array_key_exists($_GET['template'], array_slice($this->template->config, 1))) {
			$this->data['template'] = $_GET['template'];    
		} elseif ($this->data['book']->template != $this->data['template'] && in_array($this->data['book']->template, $this->template->config['selectable_templates'])) {
			$this->data['template'] = $this->data['book']->template; 
		}
		// Init
		$this->data['no_content_author'] = 'Content hasn\'t yet been added to this page, click Edit below to add some.';
		$this->data['no_content'] = 'Content hasn\'t yet been added to this page.';
		$this->data['models'] = $this->models;
		$this->data['mode'] = null;
		$this->data['can_edit'] = $this->login_is_book_admin('reviewer');

	}

	/**
	 * Load the current page
	 * @return null
	 */
	
	public function _remap() {

		try {

			// Defaults
			$max_recursions   = 2;
			$default_view     = 'plain';
			// URI segment
			$uri = explode('.',implode('/',array_slice($this->uri->segments, 1)));
			$slug = $uri[0];
			$slug_first_segment = (strpos($slug,'/')) ? substr($slug, 0, strpos($slug,'/')) : $slug;
			if (empty($slug)) {
				header('Location: '.$this->data['base_uri'].'index');
				exit;
			}				
			// Ajax login check
			if ('login_status'==$slug_first_segment) return $this->login_status();
			// Load page based on slug
			$page = $this->pages->get_by_slug($this->data['book']->book_id, $slug);
			if (!empty($page)) {
				// Protect
				if (!$page->is_live) $this->protect_book('Reader');
				// Version being asked for
				$version_num = (int) get_version($this->uri->uri_string());
				if (!empty($version_num)) {
					$version = $this->versions->get_by_version_num($page->content_id, $version_num);
					if (!empty($version)) $version_datetime = $version->created;
				}	
				// Build nested array of page relationship
				$this->data['page'] = $this->rdf_object->index(
			                         	$this->data['book'], 
			                         	$page, 
			                         	$this->data['base_uri'],
			                         	RDF_Object::RESTRICT_NONE,
			                         	RDF_Object::REL_ALL,
			                         	RDF_Object::VERSIONS_MOST_RECENT,
			                         	RDF_Object::REFERENCES_ALL,
			                         	$max_recursions
			                          );                      
				// Set the view based on the page's default view
				$this->data['view'] = $this->data['page']->versions[$this->data['page']->version_index]->default_view;
				// Page creator
				$this->set_page_user_fields(); 
			}
			
			// View and view-specific method
			if ($ext = get_ext($this->uri->uri_string())) {
				$this->data['view'] = $ext;
			} elseif (!isset($this->data['view'])) {
				$this->data['view'] = $default_view;
			}
			if (in_array($this->data['view'], $this->vis_views)) {
				$this->data['viz_view'] = $this->data['view'];  // Keep a record of the specific viz view being asked for
				$this->data['view'] = $this->vis_views[0];  // There's only one viz page (Javascript handles the specific viz types)
			}
			$method_name = $this->data['view'].'_view';
			if (method_exists($this, $method_name)) $this->$method_name();	
			// URI segment method
			if (method_exists($this, $slug_first_segment)) $this->$slug_first_segment();	
			
		} catch (Exception $e) {
			header($e->getMessage());
			exit;
		}	

		if ($this->template_has_rendered) return;
		$this->template->set_template($this->data['template']);
		foreach ($this->template->template['regions'] as $region) {
			$this->template->write_view($region, 'modules/chrome/'.$this->data['template'].'/'.$region, $this->data);
		}
		$this->template->render();		

	}

	// Return logged in status in JSON format
	private function login_status() {
		
		header('Content-type: application/json');
		if ($this->data['login']->is_logged_in) {
			echo '{"is_logged_in":1,"user_id":'.$this->data['login']->user_id.',"fullname":"'.htmlspecialchars($this->data['login']->fullname).'"}';
			exit;
		} else {
			die('{"is_logged_in":0}');
		}		
		
	}

	// Tags (list all tags in cloud)
	private function tags() {

		if (strlen($this->uri->segment(3))) return;
		$this->data['book_tags'] = $this->tags->get_all($this->data['book']->book_id, null, null, true);
		for ($j = 0; $j < count($this->data['book_tags']); $j++) {
			$this->data['book_tags'][$j]->versions = $this->versions->get_all($this->data['book_tags'][$j]->content_id, null, 1);
			$this->data['book_tags'][$j]->versions[0]->tag_of = $this->tags->get_children($this->data['book_tags'][$j]->versions[0]->version_id);
		}
		$this->data['view'] = __FUNCTION__;

	}
	
	// Place an external page in an iframe with Scalar header
	private function external() {

		$this->data['link'] = (@!empty($_GET['link'])) ? $_GET['link'] : null;
		$this->data['prev'] = (@!empty($_GET['prev'])) ? $_GET['prev'] : null;
		
		if (empty($this->data['link']) || empty($this->data['prev'])) $this->kickout();
		if (!stristr($this->data['prev'], base_url())) $this->kickout();
		
		// Special case known domains that don't allow iframes
		foreach ($this->config->item('iframe_redlist') as $forbidden) {
			if (stristr($this->data['link'], $forbidden)) {
				header('Location: '.$this->data['link']);
				exit;
			}
		}	

		$this->template->set_template('external');
		$this->template->write_view('content', 'modules/chrome/'.$this->data['template'].'/external', $this->data);
		$this->template->render();	
		$this->template_has_rendered = true;
		
	}
	
	// Resources (list of all pages|media)
	private function resources() {

		if ('vis'==$this->data['view']) return;
		$this->data['book_content'] = $this->pages->get_all($this->data['book']->book_id, null, null, true);
		for ($j = 0; $j < count($this->data['book_content']); $j++) {
			$this->data['book_content'][$j]->versions = $this->versions->get_all($this->data['book_content'][$j]->content_id, null, 1);
		}
		$this->data['view'] = __FUNCTION__;
		
	}
	
	// Table of contents (designed by each books' authors)
	private function toc() {

		$this->data['book_versions'] = $this->books->get_book_versions($this->data['book']->book_id, true);
		$this->data['view'] = __FUNCTION__;
		
	}	
	
	// Search pages
	private function search() {
		
		$this->load->helper('text');
		$this->data['can_edit'] = false;
		$this->data['sq'] =@ $_GET['sq'];;
		$this->data['terms'] = search_split_terms($this->data['sq']);
		$this->data['result'] = $this->pages->search($this->data['book']->book_id, $this->data['terms']);
		usort($this->data['result'], "sortSearchResults");	
		$this->data['view'] = __FUNCTION__;
		
	}
	
	// Import (import from an external archive)
	private function import() {
		
		if (!$this->login_is_book_admin()) $this->kickout();

		// Translate the import URL to information about the archive
		$archive = $this->uri->segment(3);
		$archive_title = str_replace('_',' ',$archive);
		$archives_rdf_url = confirm_slash(APPPATH).'rdf/xsl/archives.rdf';
		$archives_rdf = file_get_contents($archives_rdf_url);
		$archives_rdf = str_replace('{$base_url}', confirm_slash($this->data['app_root']), $archives_rdf);
		$archives =  $this->rdf_store->parse($archives_rdf);
		$found = array();
		foreach ($archives as $archive_uri => $archive) {
			$title = $archive['http://purl.org/dc/elements/1.1/title'][0]['value'];
			$identifier =@ $archive['http://purl.org/dc/terms/identifier'][0]['value'];
			if (strtolower($title) == strtolower($archive_title)) $found[$archive_uri] = $archive;
			if (!isset($found[$archive_uri]) && strtolower($identifier) == strtolower($archive_title)) $found[$archive_uri] = $archive;
		}
		if (!$found) die('Could not find archive');
		$this->data['external'] = $this->rdf_store->helper($found);

		$this->data['view'] = __FUNCTION__;
		$this->data['hide_edit_bar'] = true;

	}

	// Upload a file
	// This uploads a file only and returns its URL; all other operations to create a media page are through the save API
	private function upload() {

		$action = (isset($_POST['action'])) ? strtolower($_POST['action']) : null;

		if (!$this->login_is_book_admin()) {
			if ($action == 'add') {
				echo json_encode( array('error'=>'Not logged in or not an author') );
			} else {
				$this->kickout();
			}
			exit;
		};

		$this->data['view'] = __FUNCTION__;

		if ($action == 'add') {
			$return = array();
			try {
				//throw new Exception ("You can't do that");
				if (empty($_FILES)) throw new Exception('Could not find uploaded file');
				$path =@ $_POST['slug_prepend'];
				$targetPath = confirm_slash(FCPATH).confirm_slash($this->data['book']->slug).$path;
				if (!file_exists($targetPath)) mkdir($targetPath, 0777, true);		 
				$tempFile = $_FILES['source_file']['tmp_name'];
				$targetFile = rtrim($targetPath,'/') . '/' . $_FILES['source_file']['name'];
				/*
				$fileTypes = array('jpg','jpeg','gif','png'); // File extensions
				$fileParts = pathinfo($_FILES['Filedata']['name']);
				if (!in_array($fileParts['extension'],$fileTypes)) {
					throw new Exception ('Invalid file type');
				}
				*/
				if (!move_uploaded_file($tempFile,$targetFile)) throw new Exception('Problem moving temp file');				
				$url = ((!empty($path))?confirm_slash($path):'').$_FILES['source_file']['name'];
				$return['error'] = '';
				$return['url'] = $url;
			} catch (Exception $e) {
				$return['error'] =  $e->getMessage();
			}		
			echo json_encode($return);
			exit;						
		}
		
	}
	
	// Save a comment (an anonymous new page) with ReCAPTCHA check (not logged in) or authentication check (logged in)
	// This is a special case; we didn't want to corrupt the security of the save API and its native (session) vs non-native (api_key) authentication
	private function save_anonymous_comment() {
		
		header('Content-type: application/json');
		$return = array('error'=>'');
		
		// Validate
		try {
			require_once(APPPATH.'libraries/recaptcha/recaptchalib.php');
			if (!isset($_POST['action'])||'add'!=strtolower($_POST['action'])) throw new Exception('Invalid action');
			
			// Either logged in or not
			$child_urn   =@ trim($_POST['scalar:child_urn']); 
			$title       =@ trim($_POST['dcterms:title']);
			$description =@ trim($_POST['dcterms:description']);
			$content     =@ trim($_POST['sioc:content']);
			$user_id     =@ (int) trim($_POST['user']);
			
			if (empty($child_urn)) throw new Exception('Could not determine child URN');	
			if (empty($title)) throw new Exception('Comment title is a required field');
			if (empty($content)) throw new Exception('Content is a required field');
			
			// Not logged in
			if (empty($user_id)) {
				$fullname  =@ trim($_POST['fullname']);
				if (empty($fullname)) throw new Exception('Your name is a required field');
				$privatekey = $this->config->item('recaptcha_private_key');
				if (empty($privatekey)) throw new Exception('ReCAPTCHA has not been activated');
  				$resp = recaptcha_check_answer($privatekey, $_SERVER["REMOTE_ADDR"], $_POST["recaptcha_challenge_field"], $_POST["recaptcha_response_field"]);
				if (!$resp->is_valid) throw new Exception('Invalid CAPTCHA answer, please try again');				
			
			// Logged in
			// Note that we're not saving the user as the creator of the page -- just using their info to get fullname
			} else {
 				$user = $this->users->get_by_user_id($user_id);
 				if(!$user) throw new Exception('Could not find user');
 				if ($user->user_id != $this->data['login']->user_id) throw new Exception('Could not match your user ID with your login session.  You could be logged out.');
 				$fullname = $user->fullname;
 				if (empty($fullname)) throw new Exception('Logged in user does not have a name');		
			}
			
			// Save page
			$save = array();
			$save['book_id'] = $this->data['book']->book_id;
			$save['user_id'] = 0;
			$save['title'] = $title;  // for creating slug
			$save['type'] = 'composite';
			$save['is_live'] = 0;  // the save API allows for 0 or 1, which is why the "backdoor" is here, not there
			$content_id = $this->pages->create($save);
			if (empty($content_id)) throw new Exception('Could not save the new content');
			
			// Save version
			$save = array();
			$save['user_id'] = 0;
			$save['title'] = $title;
			$save['description'] = '';
			$save['content'] = $content;
			$save['attribution'] = $this->versions->build_attribution($fullname, $this->input->server('REMOTE_ADDR'));
			$version_id = $this->versions->create($content_id, $save);		
			if (empty($version_id)) throw new Exception('Could not save the new version');  // TODO: delete prev made content
			
			// Save relation
			if (!$this->replies->save_children($version_id, array($child_urn), array(0))) throw new Exception('Could not save relation');  // TODO: delete prev made content and version
			// I suppose we could get the newly created node and output as RDF-JSON to sync with the save API return, but since this is a special case anyways...
		
		} catch (Exception $e) {
			$return['error'] =  $e->getMessage();
		}		
		
		echo json_encode( $return );
		exit;
		
	}
	
	/**
	 * Methods based on the view being asked for
	 */	

	private function versions_view() {

		// TODO: move these actions to the save API
		$action = (isset($_REQUEST['action']) && !empty($_REQUEST['action'])) ? $_REQUEST['action'] : null;		
		if ($action == 'do_delete_versions') {
			$this->load->model('version_model', 'versions');
			// Check persmissions
			if (!$this->data['login_is_super'] && !in_array($this->data['book']->book_id, $this->data['login_book_ids'])) die ('Invalid permissions');
			// Delete versions
			$versions = (array) $_POST['delete_version'];
			if (empty($versions)) die('Could not find versions to delete');
			foreach ($versions as $version_id) {
				$this->versions->delete($version_id);
			}
			$redirect_to = $this->data['base_uri'].$this->data['page']->slug.'.versions?action=deleted_versions';
			header('Location: '.$redirect_to);
			exit;
		} elseif ($action == 'do_reorder_versions') {
			if (!$this->data['login_is_super'] && !in_array($this->data['book']->book_id, $this->data['login_book_ids'])) die ('Invalid permissions');
			$content_id = (int) $this->data['page']->content_id;
			if (empty($content_id)) die('Could not resolve content ID');
			$this->versions->reorder_versions($content_id);
			$redirect_to = $this->data['base_uri'].$this->data['page']->slug.'.versions?action=versions_reordered';
			header('Location: '.$redirect_to);
			exit;			
		}
		
		// Overwrite versions array (which only has the most recent version)
		$this->data['page']->versions = $this->versions->get_all($this->data['page']->content_id);	
		$this->set_page_user_fields();
		$this->data['hide_edit_bar'] = true;
		
	}
	
	private function history_view() {
		
		// Overwrite versions array (which only has the most recent version by default)
		$this->data['page']->versions = $this->versions->get_all($this->data['page']->content_id);	
		end($this->data['page']->versions); 
		$this->data['page']->version_index = key($this->data['page']->versions);
		$this->set_page_user_fields();
		
	}
	
	private function meta_view() {
		
		$versions = $this->versions->get_all($this->data['page']->content_id);  // TODO: trap for current datetime
		foreach ($versions as $version) {
			if ($version->version_id == $this->data['page']->versions[$this->data['page']->version_index]->version_id) continue;
			$version->meta = $this->versions->rdf($version);
			$this->data['page']->versions[] = $version;
		}
		$this->set_page_user_fields();
		$this->data['page']->meta = $this->pages->rdf($this->data['page']);
		$this->data['page']->versions[$this->data['page']->version_index]->meta = $this->versions->rdf($this->data['page']->versions[$this->data['page']->version_index]);
		
	}
	
	private function edit_view() {

		$this->data['template'] = $this->template->config['active_template'];
		
		// User
		$user_id = @$this->data['login']->user_id;
		if (empty($user_id)) show_error('Not logged in');	

		// Book 
		$book_id =@ (int) $this->data['book']->book_id;
		$book_slug = $this->data['book']->slug;
		if (empty($book_id)) show_error('No book found');
		if (empty($book_slug)) show_error('Invalid book URI segment');	

		// Content
		$content_id =@ (int) $this->data['page']->content_id;
		$is_new = (!empty($content_id)) ? false : true;

		// Protect
		if ($is_new) {
			$this->protect_book('commentator');
		} elseif (!$this->pages->is_owner($user_id, $content_id)) {	
			$this->protect_book('reviewer');
		}	
		
		$this->data['mode'] = 'editing';
		$this->data['is_new'] = $is_new;

		// Page or media file, continue to
		$this->data['is_file'] = false;
		$this->data['file_url'] = null;
		$this->data['continue_to'] = null;
		if (!empty($this->data['page']) && !empty($this->data['page']->versions) && isset($this->data['page']->versions[$this->data['page']->version_index])) {
			if ($this->data['page']->type=='media') {
				$this->data['is_file'] = true;
				$this->data['file_url'] = $this->data['page']->versions[$this->data['page']->version_index]->url;
			}
			if (!empty($this->data['page']->versions[$this->data['page']->version_index]->continue_to_content_id)) {
				$this->data['continue_to'] = $this->pages->get($this->data['page']->versions[$this->data['page']->version_index]->continue_to_content_id);
				$this->data['continue_to']->versions = $this->versions->get_all($this->data['continue_to']->content_id, null, 1);
				$this->data['continue_to']->version_index = 0;
			}
		} 
		
		
		// Page URI segment
		if (!empty($this->data['page']) && !empty($this->data['page']->slug)) {
			$this->data['page_url'] = $this->data['page']->slug;
		} elseif (substr($this->uri->uri_string(),-9,9)=='/new.edit') {
			$this->data['page_url'] = '';
		} else {
			$this->data['page_url'] = ltrim($this->uri->uri_string(),'/');
			if (substr($this->data['page_url'], 0, strlen($this->data['book']->slug))==$this->data['book']->slug) $this->data['page_url'] = substr($this->data['page_url'], strlen($this->data['book']->slug));
			$this->data['page_url'] = ltrim($this->data['page_url'], '/');
			$this->data['page_url'] = rtrim($this->data['page_url'], '.edit');
		}

		// Page view options
		$this->data['page_views'] = array(
			'plain' => 'Single column',
			'text' => 'Text emphasis',
			'media' => 'Media emphasis',
			'split' => 'Split emphasis',
			'par' => 'Media per paragraph (above)',
		    'revpar' => 'Media per paragraph (below)',
			'vis' => 'Visualization: Radial',
			'visindex' => 'Visualization: Index',
			'vispath' => 'Visualization: Paths',
			'vismedia' => 'Visualization: Media',
			'vistag' => 'Visualization: Tags',
			'history' => 'History browser'
		);

		// Metadata terms
		$this->data['ontologies'] = $this->config->item('ontologies');
		// TODO: remove built in fields
		
		// Styling 
		$this->data['book_images'] = $this->books->get_images($book_id);
		$this->data['book_audio'] = $this->books->get_audio($book_id);
		
	}	

}
?>
