<?php

class Collection extends AppModel {

    public $useTable = 'collections';

    public $validate = array(
        'name' => array(
            'rule' => array('minLength', '3'),
            'required' => true,
            'message' => 'Nazwa kolekcji musi zawierać przynajmniej 3 znaki'
        ),
        'user_id' => array(
            'rule' => 'notEmpty',
            'required' => true
        ),
        'description' => array(
            'rule' => array('maxLength', '16383'),
            'required' => false
        ),
    );

	public function publish($id) {
		return $this->syncById($id, true);
	}

	public function unpublish($id) {
		return $this->syncById($id);
	}

	public function syncAll($public = false) {
		foreach(
			$this->DB->selectAssocs("SELECT id FROM `collections`")
			as $id
		)
			$this->syncById($id, $public);
	}
    
    public function afterSave($created, $options) {

        if(isset($this->data['Collection']['id'])) {
            $this->syncById($this->data['Collection']['id']);
        }
	    
    }
    
    public function deleteSync($collection) {
	    	    
	    $ES = ConnectionManager::getDataSource('MPSearch');	    
	   	   
	    $params = array();
		$params['index'] = 'mojepanstwo_v1';
		$params['type']  = 'objects';
		$params['id']    = $collection['global_id'];
		$params['refresh'] = true;
		$params['ignore'] = 404;
		
		$ret = $ES->API->delete($params);
		return $ret;
	    
	}
    
    public function syncById($id, $public = false) {
	    
	    if( !$id )
	    	return false;
	    
	    $data = $this->find('first', array(
		    'conditions' => array(
			    'Collection.id' => $id,
		    ),
	    ));
	    
	    if( $data ) {
		    
	    	return $this->syncByData( $data , $public);
	    
	    } else
	    	return false;
	    
    }
    
    public function syncByData($data, $public = false) {
	    	        
	    if( 
	    	empty($data) || 
	    	!isset($data['Collection'])
	    )
	    	return false;
	    	       	    
	    App::import('model', 'DB');
        $this->DB = new DB();
        
        $data = $data['Collection'];
        
        $data['items_count'] = (int) $this->DB->selectValue("SELECT COUNT(*) FROM `collection_object` WHERE `collection_id`='" . $data['id'] . "'");
        $global_id = $this->DB->selectValue("SELECT id FROM objects WHERE `dataset_id`='210' AND `object_id`='" . addslashes( $data['id'] ) . "' LIMIT 1");
        
	    if( !$global_id ) {
		    
		    $this->DB->insertIgnoreAssoc('objects', array(
			    'dataset' => 'kolekcje',
			    'dataset_id' => 210,
			    'object_id' => $data['id'],
		    ));
		    
		    $global_id = $this->DB->_getInsertId();
		    
	    }
	    
	    $ES = ConnectionManager::getDataSource('MPSearch');	    
	   	   
	    $params = array();
		$params['index'] = 'mojepanstwo_v1';
		$params['type']  = 'collections';
		$params['id']    = $global_id;
		$params['refresh'] = true;
		$params['body']  = array(
			'id' => $data['id'],
			'title' => $data['name'],
			'text' => $data['name'],
			'dataset' => 'kolekcje',
			'slug' => Inflector::slug($data['name']),
			'data' => array(
				'kolekcje.czas_utworzenia' => $data['created_at'],
			    'kolekcje.id' => $data['id'],
			    'kolekcje.nazwa' => $data['name'],
			    'kolekcje.description' => $data['description'],
			    'kolekcje.user_id' => $data['user_id'],
			    'kolekcje.items_count' => $data['items_count'],
			),
		);
				
		$ret = $ES->API->index($params);

		if($public) {
			$params['type'] = 'objects';
			$ret = $ES->API->index($params);
		} else {
			$deleteParams = array();
			$deleteParams['index'] = 'mojepanstwo_v1';
			$deleteParams['type'] = 'objects';
			$deleteParams['id'] = $global_id;
			$deleteParams['refresh'] = true;
			$deleteParams['ignore'] = array(404);
			$ES->API->delete($deleteParams);
		}

		return $data['id'];	    
	    
    }

}