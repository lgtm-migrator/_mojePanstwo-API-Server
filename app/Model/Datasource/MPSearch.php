<?
class MPSearch {

    public $cacheSources = true;
    public $description = 'Serwer szukania platformy mojePaństwo';
	public $API;
	
	private $_index = 'mojepanstwo_v1';
	private $_data_prefix = 'data';
    private $_excluded_fields = array('datachannel', 'dataset', 'search', 'q');
    private $_fields_multi_dict = array();
    
    public function query(){
	    return null;
    }
    
    public function getSchemaName()
    {
        return null;
    }
	
    public function __construct($config)
    {

        require_once(APP . 'Vendor' . DS . 'autoload.php');
        $this->API = new Elasticsearch\Client(array(
	    	'hosts' => array(
	    		$config['host'] . ':' . $config['port'],
	    	),
	    ));
        // parent::__construct($config);

    }
    
    public function getCurrentUser($field = false) {
	 	
		App::uses('CakeSession', 'Model/Datasource');
		$Session = new CakeSession();	
		$user = $Session->read('Auth.User');
				
		if( $user && is_array($user) ) {
						
			if( $field===false )
				return $user;
			else
				return isset( $user[$field] ) ? $user[$field] : false;
 			
		} else return false;
	
	}
    
    public function search($body) {
	    
	    $params = array(
	    	'index' => $this->_index,
	    	'body' => $body,
	    );
	    
	    return $this->API->search($params);
	    
    }
    
    public function getObject($dataset, $id, $field='id') {
	    
	    if( $field!='id' )
	    	$field = 'data.' . $field;
	    
	    $params = array(
			'index' => $this->_index,
			'type' => 'objects',
			'body' => array(
				'from' => 0, 
				'size' => 1,
				'query' => array(
					'filtered' => array(
				        'filter' => array(
				            'and' => array(
				                'filters' => array(
				                    array(
				                        'term' => array(
				                        	'dataset' => $dataset,
				                        ),
				                    ),
				                    array(
				                    	'term' => array(
				                        	$field => $id,
				                        ),
				                    ),
				                ),
				                '_cache' => true,
				            ),
				        ),
				    ),
				),
				'fields' => array('dataset', 'id', 'slug'),
				'partial_fields' => array(
					'source' => array(
						'include' => array('data'),
					),
				),
			),
		);

		
		// echo "\n\n"; var_export( $params );
	    $es_result = $this->API->search($params);
	    // echo "\n\n"; debug( $es_result ); die();
	    
	    
	    $object = false;
	    if( $es_result && $es_result['hits']['total'] )
		    return $this->doc2object( $es_result['hits']['hits'][0] );
	    else 
	    	return false;
	    
	    
    }
    
    public function doc2object($doc) {
	    
	    // echo "\n\n"; debug( $doc );
	    
	    $output = array(
            'global_id' => $doc['_id'],
            'dataset' => $doc['fields']['dataset'][0],
    		'id' => $doc['fields']['id'][0],
    		'slug' => $doc['fields']['slug'][0],
            'score' => $doc['_score'],
            'data' => $doc['fields']['source'][0]['data'],            
    	);
    	
    	if( 
	    	isset( $doc['fields']['source'][0]['contexts'] ) && 
	    	!empty( $doc['fields']['source'][0]['contexts'] )
    	) {
	    	
	    	$context = array();
    		foreach( $doc['fields']['source'][0]['contexts'] as $key => $value ) {
	    		
	    		$key_parts = explode('.', $key);
	    		$value_parts = explode("\n\r", $value);
	    		
	    		$context[] = array(
		    		'creator' => array(
			    		'dataset' => $key_parts[0],
			    		'id' => $key_parts[1],
			    		'global_id' => $value_parts[0],
			    		'name' => $value_parts[1],
			    		'slug' => $value_parts[2],
			    		'url' => @$value_parts[5],
		    		),
		    		'action' => $key_parts[2],
		    		'label' => $value_parts[3],
		    		'sentence' => $value_parts[4],
	    		);
	    		
    		}
    		$output['contexts'] = $context;
    	
    	}
    	
    	if( 
    		isset( $doc['highlight']['text'] ) && 
    		is_array( $doc['highlight']['text'] ) && 
    		isset( $doc['highlight']['text'][0] )
    	)
    		$output['hl'] = $doc['highlight']['text'][0];
    	
    	return $output;
	    
    }	
	
    public function read(Model $model, $queryData = array())
    {
		
		// Configure::write('debug', 2);
        // if( $this->getCurrentUser('id')=='2375' ) { echo "\n\n"; debug( 'asd' ); }
		
		// debug( $queryData ); die();
		
		
        $params = array();
		$src = false;
		
        App::import('model', 'MPCache');
        $this->MPCache = new MPCache();
    	$available_datasets = $this->MPCache->getAvailableDatasets();
    	
    	    	    	
    	
    	$queryLimit = (isset($queryData['limit']) && $queryData['limit']) ?
                    $queryData['limit'] :
                    20;
                    
        $queryLimit = max( min( $queryLimit, 1000 ), 0 );
    	
    	$queryPage = (isset($queryData['page']) && is_numeric($queryData['page'])) ?
                    $queryData['page'] :
                    1;
    	
    	$queryQ = (isset($queryData['q']) && $queryData['q']) ?
                    mb_convert_encoding($queryData['q'], 'UTF-8', 'UTF-8') :
                    false;
                    
        $queryFields = (isset($queryData['fields']) && $queryData['fields']) ?
                    $queryData['fields'] :
                    false;
                
        $queryMode = (isset($queryData['mode']) && $queryData['mode']) ?
                    $queryData['mode'] :
                    'full_prefix';
                    
        $queryContext = (isset($queryData['context']) && $queryData['context']) ?
                    $queryData['context'] :
                    false;
                                            	
		$queryFilters = ( isset( $queryData['filters'] ) && is_array( $queryData['filters'] ) ) ? 
			$queryData['filters'] : 
			array();
		
		// debug( $queryFilters );
		
		$queryFacets = ( isset( $queryData['facets'] ) && is_array( $queryData['facets'] ) ) ? 
			$queryData['facets'] : 
			array();
		
		
		$queryOrder = ( isset( $queryData['order'] ) && is_array( $queryData['order'] ) ) ? 
			$queryData['order'] : 
			array();
						
		if ( isset( $queryData['order'] ) && is_array( $queryData['order'] ) && isset($queryData['order'][0]) && is_array($queryData['order'][0]) )
			$queryOrder = $queryData['order'][0];
		
			
					 		
		$queryObjects = ( isset( $queryData['objects'] ) && is_array( $queryData['objects'] ) ) ? 
			$queryData['objects'] : 
			array();
		
		if( empty($queryObjects) )
			$queryObjects = ( isset( $queryData['conditions']['objects'] ) && is_array( $queryData['conditions']['objects'] ) ) ? 
				$queryData['conditions']['objects'] : 
				array();
				
		$force_main_weights = false;
		
        $alerts_groups_data = array();
        $and_filters = array();
                       
        foreach( $queryFilters as $key => $value ) {
        	
        	
        	
        	if( $key == 'dataset' ) {
        		
        		$_key = is_array($value) ? 'terms' : 'term';
        		$and_filters[] = array(
	        		$_key => array(
	        			'dataset' => $value,
	        		),
	        	);
        		
        	} elseif( $key == '_source' ) {
        	
        		$src = $value;
        		if( $src ) {
	        		
	        		include( __DIR__ . '/MPSearchSources.php' );
	        		
        		}
        	
        	} elseif( $key == '_feed' ) {
        	
        		$and_filters[] = array(
	        		'term' => array(
	        			'feeds' => $value,
	        		),
	        	);
        	
        	} elseif( $key == '_app' ) {
	        	
	        	$_app_filter = array(
		        	'or'
	        	);
	        	
	        	if(
	        		( $app_data = $this->MPCache->getApp($value) ) && 
	        		( isset( $app_data['datasets'] ) ) && 
	        		( !empty( $app_data['datasets'] ) ) 
	        	) {
	        		
	        		$_app_filter = array();
	        		       		
	        		foreach( $app_data['datasets'] as $dataset )
		        		$_app_filter[] = array(
			        		'term' => array(
				        		'dataset' => $dataset
			        		),	
		        		);
		        		
		        	$and_filters[] = array(
			        	'or' => $_app_filter,
		        	);
	        	
	        	} else {
		        	
		        	$and_filters[] = array(
			        	'term' => array(
				        	'dataset' => false,
			        	),
		        	);
		        	
	        	}
		        	
		        	
		        	
	        	
			} elseif( $key == 'page' ) {
			
				// ignore this key
        	
        	} elseif( $key == 'limit' ) {

				// ignore this key
        	
        	} else {
        		
        		
        		if( $key == '_date' ) {
        			
        			$key = 'date';
    
				   		
				   	// debug( $value );
        			
        		} else {
		        	
		        	$key = $this->_data_prefix . '.' . $key;
	        	
	        	}
        		
        		
        		if( is_string($value) || is_numeric($value) ) {
        		
	        		$_value = strtoupper( $value );
	        		
					if( $_value == 'LAST_24H' ) {   		
						
						$range = array(
							'gte' => 'now-1d',
						);
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
					
					} elseif( $_value == 'LAST_1D' ) {   		
						
						$range = array(
							'gte' => 'now-1d',
						);
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
					
					} elseif( $_value == 'LAST_3D' ) {   		
						
						$range = array(
							'gte' => 'now-3d',
						);
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
					
					} elseif( $_value == 'LAST_7D' ) {   		
						
						$range = array(
							'gte' => 'now-7d',
						);
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
					
					} elseif( $_value == 'LAST_1M' ) {   		
						
						$range = array(
							'gte' => 'now-1M',
						);
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
					
					} elseif( $_value == 'LAST_1Y' ) {   		
						
						$range = array(
							'gte' => 'now-1Y',
						);
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
					
					} elseif( preg_match('^\[(.*?) TO (.*?)\]^i', $value, $match) ) {
						
						$range = array();
						
						if( ($gte = $this->formatDate( $match[1] )) && ($gte != '*') )
							$range['gte'] = strtolower( $gte );
						if( ($lte = $this->formatDate( $match[2] )) && ($lte != '*') )
							$range['lte'] = strtolower( $lte );
	
						
						$and_filters[] = array(
							'range' => array(
								$key => $range,
							),
						);
						
					} else {
						
						$and_filters[] = array(
			        		'term' => array(
			        			$key => $value,
			        		),
			        	);
						
					}
				
				} elseif( is_array( $value ) ) {
					
					$and_filters[] = array(
		        		'terms' => array(
		        			$key => $value,
		        		),
		        	);
	        	
	        	}
	        	        	
        	}
        	
        	
        
        }
        
        if( !empty( $queryObjects ) ) {
        	
        	$ors = array();
        	
	        foreach( $queryObjects as $obj ) {
		        
		        $ors[] = array(
        			'and' => array(
        				array(
        					'term' => array(
				        		'dataset' => $obj['dataset'],
		        			),
        				),
        				array(
        					'term' => array(
				        		'id' => $obj['object_id'],
		        			),
        				),
        			),
        		);
		        
	        }
	        
	        $and_filters[] = array(
	        	'or' => $ors,
	        );
        
        }
        
        
        
      
        
        
        
        
        
	    
	    $filtered = array();
	    $_from = ( $queryPage - 1 ) * $queryLimit;
	    
        $params = array(
			'index' => $this->_index,
			'type' => 'objects',
			'body' => array(
				'from' => $_from, 
				'size' => $queryLimit,
				'query' => array(),
				'fields' => array('dataset', 'id', 'slug'),
				'partial_fields' => array(
					'source' => array(
						'include' => array('data'),
					),
				),
			),
		);
		
		
		if( !empty($queryFields) )
			$params['body']['partial_fields']['source']['include'] = $queryFields;
			
		if( !empty($queryContext) )
			$params['body']['partial_fields']['source']['include'] = array_merge($params['body']['partial_fields']['source']['include'], array('contexts.' . $queryContext . '.*'));
		
		
		$sort = array();
		
		if( $queryQ ) {
			
			$do_highlights = false;

	    	if( $queryMode == 'full' ) {
		    	
		    	$filtered['query']['match_phrase'] = array(
					"text" => array(
						"query" => $queryQ,
						'analyzer' => 'pl',
						'slop' => 10,
					),
		    	);
		    	
		    	$do_highlights = true;
		    	
	    	} elseif( $queryMode == 'full_prefix' ) {
		    	
		    	/*
		    	$filtered['query']['match'] = array(
					"text_pl.suggest" => array(
						"query" => $queryQ,
						'analyzer' => 'morfologik',
					),
		    	);
		    	*/
		    	
		    	
		    	$filtered['query']['multi_match'] = array(
					"query" => $queryQ,
				    "type" => "phrase",
				    "fields" => array("title.suggest", "text"),
					'analyzer' => 'pl',
					'slop' => 10,
		    	);
		    	
		    	
		    	/*
		    	$params['body']['query']['filtered']['query']['match_phrase'] = array(
					"text_pl" => array(
						"query" => $queryQ,
						'analyzer' => 'morfologik',
						'slop' => 10,
					),
		    	);
		    	*/
		    	
		    	$do_highlights = true;
		    	
	    	} elseif( $queryMode == 'title' ) {
		    	
		    	$filtered['query']['match_phrase'] = array(
					"title" => array(
						"query" => $queryQ,
						'analyzer' => 'pl',
						'slop' => 10,
					),
		    	);
		    	
	    	} elseif( $queryMode == 'title_prefix' ) {
		    	
		    	$filtered['query']['match'] = array(
					"title.suggest" => array(
						"query" => $queryQ,
						'analyzer' => 'standard',
					),
		    	);
		    	
	    	} elseif( $queryMode == 'suggester_main' ) {
		    	
		    	$filtered['query']['multi_match'] = array(
					"query" => $queryQ,
				    "type" => "phrase",
				    "fields" => array("title.suggest", "text"),
					'analyzer' => 'pl',
					'slop' => 10,
		    	);
						    	
		    	$and_filters[] = array(
	    			'term' => array(
	    				'weights.main.enabled' => true,
	    			),
		    	);
		    	
	    	} elseif( $queryMode == 'search_main' ) {
		    	
		    	$filtered['query']['multi_match'] = array(
					"query" => $queryQ,
				    "type" => "phrase",
				    "fields" => array("title.suggest", "text"),
					'analyzer' => 'pl',
					'slop' => 10,
		    	);
				
				
				
				if( !$src ) {
    	
			    	$and_filters[] = array(
		    			'term' => array(
		    				'weights.main.enabled' => true,
		    			),
			    	);
		    	
		    	}
		    	
		    	$do_highlights = true;
		    	
	    	}
	    	
	    	
	    	
	    	
			
			
			$sort[] = array(
				'_score' => 'desc',
			);
			
			
			
			
			if( $do_highlights ) {
			
				$params['body']['highlight'] = array(
		    		'fields' => array(
		    			'text_pl' => array(
		    				'index_options' => 'offsets',
		    				'number_of_fragments' => 1,
		    			),
		    		),
		    		
		    	);
		    	
		    	/*
		    	$params['body']['suggest'] = array(
				    "text" => $queryQ,
				    "didyoumean" => array(
				      "phrase" => array(
				        "analyzer" => "simple",
				        "field" => "text",
				        "size" => 1,
				        "real_word_error_likelihood" => 0.95,
				        "max_errors" => 0.5,
				        "gram_size" => 2,
				        "direct_generator" => array(
				        	array(
					          "field" => "text",
					          "suggest_mode" => "always",
					          "min_word_length" => 1
					        ),
					    ),
				        "highlight" => array(
				          "pre_tag" => "<em>",
				          "post_tag" => "</em>"
				        )
				      )
				    )
				);
				*/ 
			
			}	
	    		
	    }
	    
		
		
		$filtered['filter'] = array(
            'and' => array(
                'filters' => $and_filters,
                '_cache' => true,
            ),
	    );

		$params['body']['query']['filtered'] = $filtered;	    
	    	    	    
	    foreach( $queryOrder as $order ) {
		    
		    $parts = explode(' ', $order);
		    $field = isset($parts[0]) ? $parts[0] : false;
		    
		    if( $field ) {				
				
				$direction = ( isset($parts[1]) && in_array($parts[1], array('asc', 'desc')) ) ? $parts[1] : 'desc';
				
				if( $field == '_title' ) {
					
					$sort[] = array(
						'title.raw' => $direction,
					);
				
				} elseif( $field == '_weight' ) {
					
					$sort[] = array(
						'weights.main.score' => $direction,
					);
				
				} elseif( $field == '_date' ) {
					
					$sort[] = array(
						'date' => $direction,
					);
				
				} elseif( stripos($field, 'feed_dataset_order.')===0 ) {
					
					$sort[] = array(
						$field => $direction,
					);
				
				} elseif( $field == 'score' ) {
				
				
				
				} else {									
					
					$field = $this->_data_prefix . '.' . $field;					
					$sort[] = array(
						$field => $direction,
					);
					
				}
				
			}
		    
	    }
        
        
        $sort[] = array(
        	'date' => 'desc',
        );
        
        
        $sort[] = array(
        	'title.raw' => 'asc',
        );
        
        
        
        if( !empty($sort) )
	        $params['body']['sort'] = $sort;
                
        if( !empty($queryFacets) ) {
	        
	        $aggs = array();
	                
	        foreach( $queryFacets as $facet ) {
	        	
	        	$p = stripos($facet, 'alerts:');
	        	
	        	if( $facet == 'dataset' ) {
		        	
		        	$aggs['dataset'] = array(
		        		'terms' => array(
		        			'field' => 'dataset',
		        			'size' => 20,
		        		),
		        	);
		        	
	        	} elseif( $p===0 ) {
		        	
		        	$group_ids = explode(',', substr($facet, 7));
		        	
		        	$aggs['alerts'] = array(
        				'nested' => array(
			                "path" => "objects.alerts",
			            ),
			            'aggs' => array(
			                'groups' => array(
				                'terms' => array(
			                		'field' => 'objects.alerts.group_id',
			                		'include' => '(' . implode('|', $group_ids) . ')',
			                	),
		                	),
			            ),
		        	);
		        	
		        } else {
		        	
		        	$aggs[ $facet ] = array(
		        		'terms' => array(
		        			'field' => $this->_data_prefix . '.' . $facet,
		        			'exclude' => '0',
		        			'size' => 20,
		        		),
		        	);
		        	
	        	}
	        }
	        
	        if( !empty($aggs) )
		        $params['body']['aggs'] = $aggs;
	        
        }
        
        
        
        
        /*
        if( 
        	$force_main_weights || 
        	( !$src && ( ($queryMode == 'suggester_main') || ($queryMode == 'search_main') ) )
        ) {
	    */
	      
	        $params['body']['query'] = array(
	        	'function_score' => array(
	        		'query' => $params['body']['query'],
	        		'field_value_factor' => array(
						'field' => 'weights.main.score'
			        ),
	        	),
	        );
	        
	    /*    
        }
        */
        
        
        
  

        
        
        
        
        
        
        
        // Configure::write('debug', 2);
        // echo "\n\n"; debug( $params );
	    $es_result = $this->API->search( $params );
        // echo "\n\n"; debug( $es_result );
        
        
        
        
        
        
        
        
        
        
        
        
        
        

        
        
        $output = array(
        	'pagination' => array(
        		'count' => null,
        		'total' => null,
        		'from' => null,
        		'to' => null,
        	),
        	'performance' => array(
        		'took' => null,
        	),
        	'dataobjects' => array(),
        	'didyoumean' => false,
        );
        
        
        if( $es_result ) {
        	
        	$_count = count( $es_result['hits']['hits'] );
        	$output['performance']['took'] = $es_result['took'];
        	
        	$output['pagination'] = array_merge($output['pagination'], array(
        		'count' => $_count,
        		'total' => $es_result['hits']['total'],
        		'from' => $_from + 1,
        		'to' => $_from + $_count,
        	));
        	
		    if( $_count ) {
			    
			    foreach( $es_result['hits']['hits'] as $doc )
			    	$output['dataobjects'][] = $this->doc2object( $doc );
			    
		    } else {
		    
			    if( isset($es_result['suggest']) && isset($es_result['suggest']['didyoumean']) && !empty($es_result['suggest']['didyoumean']) ) {
				    
				    $didyoumean = @array_shift( $es_result['suggest']['didyoumean'] );
				    
				    if( isset($didyoumean['options']) && !empty($didyoumean['options']) )
				    	$output['didyoumean'] = $didyoumean['options'][0]['highlighted'];
				    
			    }
		    
		    }
	    
	    }
	    
	    
	    if( !empty( $queryFacets ) ) 		    
		    foreach( $queryFacets as $field ) {
		    	
		    	if( is_array($field) )
		    		$field = $field[0];
		    	
		    	if( $field=='alerts' )
		    		$output['facets'][$field][] = $es_result['aggregations'][$field]['groups']['buckets'];
			    elseif( isset( $es_result['aggregations'][$field] ) ) 
				    $output['facets'][$field][] = $es_result['aggregations'][$field]['buckets'];
				    
			}
        
        
        return $output;

    }

	private function formatDate( $inp ) {
		
		if( $inp == '*' ) {
			return false;
		} elseif( in_array($inp, array('NOW/DAY')) ) {
			return 'now';
		} else {
			return $inp;
		}
		
	}

}