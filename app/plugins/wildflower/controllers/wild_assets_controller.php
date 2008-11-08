<?php
class WildAssetsController extends WildflowerAppController {
	
	public $helpers = array('Cache');
	public $components = array('RequestHandler', 'Wildflower.JlmPackager');
	public $paginate = array(
        'limit' => 12,
        'order' => array('created' => 'desc')
    );
	
	function wf_create() {
	    $this->WildAsset->create($this->data);
	    
	    if (!$this->WildAsset->validates()) {
	        $this->feedFileManager();
	        return $this->render('wf_index');
	    }
	    
	    // Check if file with the same name does not already exist
	    $fileName = trim($this->data[$this->modelClass]['file']['name']);
        $uploadPath = Configure::read('Wildflower.uploadDirectory') . DS . $fileName;
        
        // Rename file if already exists
        $i = 1;
        while (file_exists($uploadPath)) {
            // Append a number to the end of the file,
            // if it alredy has one increase it
            $newFileName = explode('.', $fileName);
            $lastChar = mb_strlen($newFileName[0], Configure::read('App.encoding')) - 1;
            if (is_numeric($newFileName[0][$lastChar]) and $newFileName[0][$lastChar - 1] == '-') {
                $i = intval($newFileName[0][$lastChar]) + 1;
                $newFileName[0][$lastChar] = $i;
            } else {
                $newFileName[0] = $newFileName[0] . "-$i";
            }
            $newFileName = implode('.', $newFileName);
            $uploadPath = Configure::read('Wildflower.uploadDirectory') . DS . $newFileName;
            $fileName = $newFileName;
        }
   
        // Upload file
        $isUploaded = @move_uploaded_file($this->data[$this->modelClass]['file']['tmp_name'], $uploadPath);
        
        if (!$isUploaded) {
            $this->WildAsset->invalidate('file', 'File can`t be moved to the uploads directory. Check permissions.');
            $this->feedFileManager();
            return $this->render('wf_index');
        }
        
        // Make this file writable and readable
        chmod($uploadPath, 0777);
        
        $this->WildAsset->data[$this->modelClass]['name'] = $fileName;
        if (empty($this->WildAsset->data[$this->modelClass]['title'])) {
            $this->WildAsset->data[$this->modelClass]['title'] = $fileName;
        }
        $this->WildAsset->data[$this->modelClass]['mime'] = $this->WildAsset->data[$this->modelClass]['file']['type'];
        
        $this->WildAsset->save();
        
        $this->redirect(array('action' => 'index'));
	}

	/**
	 * Files overview
	 *
	 */
	function wf_index() {
        $this->feedFileManager();
	}
	
	/**
	 * Delete an upload
	 *
	 * @param int $id
	 */
	 // @TODO make require a POST
	function wf_delete($id) {
	    $this->WildAsset->delete($id);
		$this->redirect(array('action' => 'index'));
	}
	
	/**
	 * Edit a file
	 *
	 * @param int $id
	 */
	function wf_edit($id) {
		$this->data = $this->WildAsset->findById($id);
		$this->pageTitle = $this->data[$this->modelClass]['title'];
	}
	
	/**
	 * Insert image dialog
	 *
	 * @param int $limit Number of images on one page
	 */
	function wf_insert_image($limit = 8) {
		$this->layout = '';
		$this->paginate['limit'] = intval($limit);
		$this->paginate['conditions'] = "{$this->modelClass}.mime LIKE 'image%'";
		$images = $this->paginate($this->modelClass);
		$this->set('images', $images);
	}
	
	function wf_browse_images() {
		$this->paginate['limit'] = 6;
		$this->paginate['conditions'] = "{$this->modelClass}.mime LIKE 'image%'";
		$images = $this->paginate($this->modelClass);
		$this->set('images', $images);
	}
	
	function wf_update() {
	    $this->WildAsset->create($this->data);
	    if (!$this->WildAsset->exists()) return $this->cakeError('object_not_found');
	    $this->WildAsset->saveField('title', $this->data[$this->modelClass]['title']);
	    $this->redirect(array('action' => 'edit', $this->WildAsset->id));
	}
	
	function beforeFilter() {
		parent::beforeFilter();
		
		// Upload limit information
        $postMaxSize = ini_get('post_max_size');
        $uploadMaxSize = ini_get('upload_max_filesize');
        $size = $postMaxSize;
        if ($uploadMaxSize < $postMaxSize) {
            $size = $uploadMaxSize;
        }
        $size = str_replace('M', 'MB', $size);
        $limits = "Maximum allowed file size: $size";
        $this->set('uploadLimits', $limits);
	}
    
    /**
     * Output parsed JLM javascript file
     *
     * The output is cached when not in debug mode.
     */
    function wf_jlm() {
        $javascripts = Cache::read('wf_jlm'); 
        if (empty($javascripts) or Configure::read('debug') > 0) {
            $javascripts = $this->JlmPackager->concate();
            Cache::write('wf_jlm', $javascripts);
        }
        
        $this->layout = false;
        $this->set(compact('javascripts'));
        $this->RequestHandler->respondAs('application/javascript');
        
        $cacheSettings = Cache::settings();
        $file = CACHE . $cacheSettings['prefix'] . 'wf_jlm';
        $this->JlmPackager->browserCacheHeaders(filemtime($file));
    }
	
	private function feedFileManager() {
	    $this->pageTitle = 'Files';
	    $files = $this->paginate($this->modelClass);
        $this->set(compact('files'));
	}
    
}