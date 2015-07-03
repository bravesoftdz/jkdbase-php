<?PHP
/**
 *  Jim Kinsman's dbase wrapper 
 *   
 *  Example
 *  <code>
 *  $db = new jkdbase('myfile.dbf');
 *  $rows = $db->fetch_all();
 *  </code>
 */
 
 
 if (!function_exists('is_assoc_array')){
    function is_assoc_array($ary){
	return ( is_array($ary) && array_diff_key($ary,array_keys(array_keys($ary))) );
    }
 
 }
 
class jkdbase{
  private $file = null;
  private $db = null;
  public function dbase_db(){ return $this->db; }
  private $rw = null;
  private $numrecords = null;
  private $fetch_i = 1;
  private $use_trim = false;

  public function __construct($file, $rw = 0, $use_trim = true)
  {
        if ($rw == 'read'){ $rw = 0; }
        else if ($rw == 'write'){ $rw = 2; }
        
        if ($rw !== 0 && $rw !== 2){
	  throw new Exception('Invalid read/write bit, must be 0 for read, or 2 for read/write');
        }	

	$this->file = $file;
        $this->db = dbase_open($file, $rw);
        if ($this->db){
           $this->numrecords = $this->numrecords();
        }else{
            throw new Exception("Could not open database file $file");
        }
	$this->rw = $rw;
	
	$this->use_trim = $use_trim;
  }

  public function __destruct()
  {
     dbase_close($this->db);
  }

  private $header_info = null;
  public function header_info(){
     $this->header_info = dbase_get_header_info ($this->db);
     return $this->header_info;
  }
  
  
  
  /**
   * Create an empty record based on the header_info
   * header_info is an array of column information, 
   * in this function we use the 'name' key and value to 
   * populate an empty associative array
   */  
  public static function dbase_empty_record_from_header_info($dbase_header_info){
    
    $empty_record = array();
    foreach($dbase_header_info as $i => $key_info){
       $empty_record[ $key_info['name'] ] = '';
    }
    return $empty_record;
  }
  
  
  /**
   * Create an empty record based on the header_info
   */
  public function get_empty_record(){
     if (empty($this->header_info)){ $this->header_info(); }
     return self::dbase_empty_record_from_header_info($this->header_info);
  }

  public function numrecords(){
    return dbase_numrecords($this->db);
  }

  /**
   * Delete all of the entries marked for deletion
   */
  public function pack(){
    return dbase_pack($this->db);
  }



  /**
   * Match a string (supports 'preg', 'exact', 'contains', 'starts-with', 'greater-than','less-than')
   * @usedby find_fetch_all()
   */
  public static function match($matchtype, $pattern, $value, $ignore_case = true)
  {
      //TODO: allow !preg !exact !contains
      //allow DOES NOT match
      /*
      if (!empty($matchtype) && $matchtype{0} == '!'){
          $negate = true;
          $matchtype{0} = ' ';
          $matchtype = trim($matchtype);
      }*/
      
      //echo 'mt: '.$matchtype.' pattern: '.$pattern.' actual value: '.$value.'<br>';
      if ($matchtype == 'anything'){ return true; }
      
      if ($pattern == ''){ return trim($value) == $pattern; } //empty matches nothing
      
      if ($matchtype == 'is-empty'){
         return trim($value) == '';
      }
      
      if ($matchtype == 'preg' && preg_match($pattern, $value)){ return true; }

      if ($ignore_case){ $pattern = strtolower($pattern); $value = strtolower($value); }

      if ($matchtype == 'exact')
      {
	 //use trim because dbase pads with extra spaces
	 return (trim($pattern) == trim($value));
      }
      else if ($matchtype == 'contains')
      {
   	 $pos = strpos($value, $pattern);
         return ($pos !== false);
      }
      else if ($matchtype == 'starts-with')
      {
   	   $pos = strpos($value, $pattern);
         return ($pos === 0);
      }
      else if ($matchtype == 'greater-than'){
         return (float)$value > (float)$pattern;
      }else if ($matchtype == 'less-than'){
         return (float)$value < (float)$pattern;
      }
      else return false; //invalid match type
  }
  
  
  

  /**
   * Returns the number of values that match in $patterns_ary comparing with $values_ary
   * 0 - means no matches found
   * 
   */
  public static function match_array($matchtype, $patterns_ary, $values_ary, $ignore_case = true)
  {
     $matches_found = 0;
     $mismatches_found = 0;
     $keynotfound_count = 0;
     
     foreach($patterns_ary as $ptrn_key => $pattern)
     {
        if (isset($values_ary[$ptrn_key]))
        {
           if (self::match($matchtype, $pattern, $values_ary[$ptrn_key], $ignore_case))
           {
              $matches_found++;
           }
           else
           {
              $mismatches_found++;
           }
        }
        else //values_ary  does not even contain the key in patterns_ary, is this a mismatch?
        {
           $keynotfound_count++;
        }
     }
     return $matches_found;
  }

  private $ffa_info = null;
  public function fetch_find_all_info(){ return $this->ffa_info; }


  private $add_info = null;
  /**
   * Get the information about the last added row using ->add()
   */
  public function add_info(){ return $this->add_info; }
  
  
  /**
   * Get the information based on the last dbase_delete() performed
   *
   *   Note: $this->fetch_find_all_info will also be modified if 
   *      $search_if_first_failed is true
   */
  private $dbase_delete_info = null;
  public function delete_info(){ return $this->dbase_delete_info; }
  
  /**
   * Delete a record from the database
   * given the WHERE values, start with the $suggested_dbase_index
   * to see if it matches
   *
   * @param array $wherevalues 
   */
  public function delete($wherevalues, $suggested_dbase_index = null, $search_if_first_failed = false, $delete_attempt_max = 1)
  {
     $this->dbase_delete_info = array();
     if (!is_array($wherevalues) || count($wherevalues) < 2){
        throw new Exception('Not enough where values to match');
     }
     
     $do_find = false;
     
     //if we want this function to delete multiple values
     //ADVANCED USERS CAN CHANGE THIS IF WE NEED TO DELETE MULTIPLE FIELDS.
     //BUT FOR PROTECTION OF THE DBASE, WE WILL ONLY ALLOW 1 DELETION
     if (!is_numeric($delete_attempt_max) || $delete_attempt_max > 1){ 
	    $delete_attempt_max = 1; //only allow deleting a max attempt of 1
     }
     
     if (!empty($suggested_dbase_index))
     {
	$suggested_row = dbase_get_record_with_names($this->db, $suggested_dbase_index);
	if (!empty($suggested_row) && is_array($suggested_row)){
	    $this->dbase_delete_info['suggested_row'] = $suggested_row;
	    if (self::match_array('exact', $wherevalues, $suggested_row, false))
	    {
		$this->dbase_delete_info['suggested_match_found'] = true;
		
		//the suggested_dbase_index was correct!!
		if ($delete_attempt_max >= 1){
		
		   return (dbase_delete_record($this->db, $suggested_dbase_index) ? 1 : 0);
		}else{
		   return false;
		
		}
		
	    }
	}
	else //suggested_dbase_index didnt return a valid row, 
	{
	   $do_find = true;
	}
     }
     else //lets go ahead and find everyone who might match
     {
        $do_find = true;
     }
     
     
     if ($search_if_first_failed && $do_find)
     {
        $this->dbase_delete_info['found_records'] = $this->fetch_find_all($wherevalues, array('ignore_case'=>false, 'matchtype'=>'exact', 'min_match_count'=> 0, 'negate'=>false));
        if (!empty($this->dbase_delete_info['found_records'])){
               $this->dbase_delete_info['records_deleted'] = array();
               foreach($this->dbase_delete_info['found_records'] as $i => $found_record){
                 if ($i >= $delete_attempt_max){
                   break;  
                 }               
                 $this->dbase_delete_info['records_deleted'][] = dbase_delete_record($this->db, $found_record['__jkdbase_recordnumber']);
               }
               return count($this->dbase_delete_info['records_deleted']);
        }
     }
     //not even searched, nothing deleted
     return false; 
     
  }
  
  
  
  /**
   * Add a new record to the database
   * If it is an associative array, you must use get_empty_record() 
   * to ensure that everything is empty EXCEPT those in $row
   *
   * TODO: After it gets added, how do we know without doing a search?
   * @param array $row an array of rows
   * @param bool $fillempty do we populate the array with empty values? (using the header_info)
   * @return bool true on added, false on failure (dbase_add_record() result)
   */
  public function add($row, $fillempty = true)
  {
      $this->add_info = array();
      
      if (is_assoc_array($row)){
         if ($fillempty){
            $new_row = $this->get_empty_record();
            
            $this->add_info['invalid_key_count'] = 0;
            //copy all values to the $new_row
            foreach($new_row as $k => $v){
               if (isset($row[$k])){
                  $new_row[$k] = $row[$k];
               }else{
                 $this->add_info['invalid_key_count']++;
               }
            }
            
            
            //ok now $row should ONLY contain valid data
            $row = $new_row;
         
         }
         
         
         //we can't add a deleted row, therefore:
         unset($row["deleted"]); // drop the field 
         
         
         $this->add_info['associative_row'] = $row;
         
          // then convert to numeric to store: 
          $rarr = array(); 
          foreach ($row as $i=>$vl) $rarr[] = $vl; 
          
          $row = $rarr;
         
         $this->add_info['row'] = $row; 
      }//end if an associative array
      
      //first convert record to a normal array (if it is associative)
      $this->add_info['added'] = dbase_add_record($this->db, $row);
      
      return $this->add_info['added'];
  }


  public static $dbase_update_records = array();
  /**
   * Helper function to update a specific field value without modifying anything else
   * It is really important to have a $where_ary because the dbases's index may change 
   * because of either sorting the list in an external application or inserting a new row.
   *
   * this will obviously fail if it is opened in read-only mode (stupid jim)
   *
   * @param array $where_ary if not empty, the update will not complete unless these values match in the where array
   */
  private static function dbase_update($db, $index, $update_ary, $where_ary = array(), $trim_compare = false)
  {
      //echo 'db: '.$db.' updating index '. $index;
      
      $row = dbase_get_record_with_names($db, $index); 
      //print_r($where_ary); echo ' whereary <br> row to update:'; print_r($row); echo ' --row to update <br>';
      //echo 'row fetched vs where_ary: ';print_r(array_diff_assoc($where_ary, $row));
      $matching_where_key_count = 0;
      
      //echo 'count: '.count($where_ary);
      
      foreach($where_ary as $wkey => $wvalue)
      {
          if (isset($row[$wkey])) //do nothing if the where key does not exist
	  {
	        $matching_where_key_count++;
		if ( ($trim_compare && trim($row[$wkey]) != trim($wvalue)) ||
		     (!$trim_compare && $row[$wkey] != $wvalue) )
		{
                    //echo 'where value does not match: '.$wkey.' '.$wvalue.' !== '.$row[$wkey];
                    return false; //where value did not match, therefore return false
		}
	  }
      }
      //echo 'mwkc: "'.$matching_where_key_count.'"';
      if ($matching_where_key_count < 2){
         return false; //for security purposes force the update to verify at least 2 valid where keys
      }
     //the user may want to search for a user who DOES match the where_ary if returned false above


      unset($row["deleted"]); // drop the field 
      
      
      
      $before_row = $row;
      
      //go and update each specific field
      foreach($update_ary as $key => $value)
      {
          //question: Do we allow deleting of a specific field?
	  if (isset($row[$key]))
          {
              $row[$key] = $value;
	  }
      }
      // then convert to numeric to store: 
      $rarr = array(); 
      foreach ($row as $i=>$vl) $rarr[] = $vl; 
      //echo '<br>updating index '.$index.print_r($rarr, true);
      $replaced =  dbase_replace_record($db, $rarr, $index); 
      
      self::$dbase_update_records[] = array('index'=> $index,
         'before'=>$before_row,
         'after'=>$row, 
         'was_replaced'=>$replaced);
      
      return $replaced;
  }
  
  //remember the database must be packed after this
  public function delete_all()
  {
     $rows = $this->fetch_all();
     foreach($rows as $i => $row)
     {
        dbase_delete_record($this->db, $i);
     }
     //now pack the database to make it permanent
  }
  
 /**
  * 
  * Update a bunch of records at once.
  * (this could be dangerous depending on which records we pass in!!)
  *
  * @param $records array an array of records to update
  * @param $update_ary associative array of updates to make
  */
 public function update_mass_records($records, $update_ary, $formula_update_ary = null){
    if ($this->rw === 0){ throw new Exception('cannot update a database opened in read-only mode'); }
    
    return self::dbase_update_mass_records($this->db, $records, $update_ary, $this->use_trim, $formula_update_ary);
 }

 /**
  * 
  * Update a bunch of records at once. 
  * Note: each record has to have a __jkdbase_recordnumber (generated from fetch_find_all)
  * (this could be dangerous depending on which records we pass in!!)
  *
  * @param dbase $db dbase database (opened in rw mode)
  * @param $records array an array of records to update
  * @param $update_ary associative array of updates to make
  * @param bool $use_trim (trim the suckers of spaces first)
  * @param array $formula_update_ary special formula array to mass-update with style (for example 'PK_ID'=> array('function'=> AUTOINCREMENT, 'row_i_offset'=>1) will renumber the PK_ID starting with index 1, then 2, then 3 in the order it was found
  */
 public static function dbase_update_mass_records($db, $records, $update_ary, $use_trim, $formula_update_ary=null){
    $stats = array();
    $affected_rows = 0;
    $failed_rows = 0;
    $skipped_records = 0;
    
    //reset the update records to nothing
    self::$dbase_update_records = array();
    
    if (!is_array($records)){ throw new Exception('$records is not an array'); }
    if (!is_array($update_ary) || count($update_ary) == 0){ 
      if (!is_array($formula_update_ary) || count($formula_update_ary) == 0){ 
         throw new Exception('$update_ary AND formula_update_array is empty or invalid'); 
      }
    }
   
    
    //go ahead and start doing an update
    foreach($records as $i => $record){
       if (!is_array($record)){
         $stats[] = $i.' - '.$record.' is not a valid array';
         $skipped_records++;
         continue;
       }else if (count($record) < 3){
         $stats[] = $i.' '.print_r($record, true).' - had less than 3 entries and was denied due to potential mass update problems.';
         $skipped_records++;
       }
    
       if (!isset($record['__jkdbase_recordnumber'])){ 
          $stats[] = $i.' '.print_r($record, true).' __had no jkdbase_recordnumber';
          $skipped_records++;
          continue;
       }
       
       //generate some sort of an update array from the $formula_update_ary
       if (!empty($formula_update_array)){
           if (empty($update_ary)){ $update_ary = array(); }
           foreach($formula_update_ary as $col => $formula){
              if (isset($formula['function']['AUTOINCREMENT'])){
                 $offset = isset($formula['function']['row_i_offset'])?$formula['function']['row_i_offset'] : 0;
                 $update_ary[$col] = $i + $offset;
              }
           }
       }
       
       if (self::dbase_update($db, $record['__jkdbase_recordnumber'], $update_ary, $record, $use_trim)){
         $affected_rows++;
       }else{
         $failed_rows++;
         $stats[] = $i.' '.print_r($record, true).' - failed dbase_update()';
       }
    
    }
    
    return array('errors'=>$stats, 
                 'affected_rows'=>$affected_rows, 
                 'failed_rows'=>$failed_rows, 
                 'skipped_records_count'=>$skipped_records);
 }
 
 

 public function update_record($index, $update_ary, $where_ary = array())
 {
    return self::dbase_update($this->db, $index, $update_ary, $where_ary, $this->use_trim);
 }

 /**
 * Is the variable an associative array?
 */
 public static function is_assoc_array($ary){
    return ( is_array($ary) && array_diff_key($ary,array_keys(array_keys($ary))) );
 }
 

  /**
   * Find all records matching $keyvals,
   * For example, $db->fetch_find_all(array('LAST_NAME'=>'Smith'));
   * @param array $opts negate = true - find all that DO NOT match, matchtype = 'preg' or an array('LAST_NAME'=>'preg'), 'min_match_count' => 0 - match all keys, 1 - match if at least 1 found
   */
  public function fetch_find_all($keyvals, $opts = array('matchtype'=>'preg', 'min_match_count'=> 1, 'negate'=>false))
  {
	  $this->ffa_info = array();
          if ((empty($keyvals)) || !( is_array($keyvals) && array_diff_key($keyvals,array_keys(array_keys($keyvals)))) ){
		throw new Exception('Nothing to find or not a valid associative array, try array(\'LAST_NAME\'=>\'Smith\')');
          }
	  $defaults = array('matchtype'=>'preg', 
	                   'min_match_count'=>0, //by default match all provided
	                   'ignore_case'=>false,  
	                   'negate'=>false,      //return the negation of results
					   'not'=>array(),       //negation of specific key (FIRST_NAME,LAST_NAME) would reverse the $keyvals[FIRST_NAME]= match
	                   'limit'=>false,       //find first x results only then quit
	                   'start_search_from_index'=>1, //do we start from the middle of the dbase?
	                   'search_deleted'=>false,    //also include results prepared to be deleted (not packed)
	                   'specific_columns_only'=>false,//search/return only these columns
                      'search_within'=>false, //search ONLY within specific group (ie. array('PK_ID'=>array(134,23, 890, 2089)) )
                      'search_exclude'=>false
                      );$opts = array_merge($defaults, $opts);
	  extract($opts, EXTR_SKIP);

	  //convert all values to lowercase
	  if (!empty($ignore_case)){ foreach($keyvals as &$value){ $value = strtolower($value); } }

	  //print_r($keyvals); print_r($opts);
	  
	  $rows = array();
	  $record_numbers = dbase_numrecords($this->db);
	  $this->ffa_info['records_found'] = 0;
	  $this->ffa_info['records_searched'] = 0;
	  $this->ffa_info['records_total'] = $record_numbers;
	  $this->ffa_info['errors'] = array();
	  
	  if ($start_search_from_index < 0)
	  { 
	     $start_search_from_index = $record_numbers - $start_search_from_index; 
	  }
	  else if ($start_search_from_index === 0){ 
	     $start_search_from_index = 1;
	  }
	  
	  for ($i = $start_search_from_index; $i <= $record_numbers; $i++) {
	      //go through each record and see if it matches the search
		$row = dbase_get_record_with_names($this->db, $i);
		if ($row === false){ 
		   $this->ffa_info['errors'][] = 'Invalid row on index '.$i.' for db '.$this->db;
		   continue;
		}else if (!empty($row['deleted']) && !$search_deleted){
		   //this row is marked as deleted, use dbase_pack() to permanently remove
		   //however, we are ignoring all deleted rows
		   continue;
		}else if (!empty($search_within)){
		   $continue = false;
		   //for example: $key might be "PK_ID" and values might be array(2338,218,4021);
		   foreach($search_within as $key => $values){
		      if (!in_array($row[$key], $values) )
		      {
		         //ONLY SEARCH OUT WHAT IS LISTED IN $values
               $continue = true;
               break;
		      }
		   }
		   if ($continue){
		      continue;
		   }
		}else if (!empty($search_exclude)){
		   $continue = false;
		   //for example: $key might be "PK_ID" and values might be array(2338,218,4021);
		   foreach($search_exclude as $key => $values){
		      if (in_array($row[$key], $values) )
		      {
		         //ONLY SEARCH OUT WHAT IS LISTED IN $values
               $continue = true;
               break;
		      }
		   }
		   //if it was in the above array, continue on because we are excluding that list
		   if ($continue){
		      continue;
		   }
		}
		
		//remove/hide specific columns
		if (!empty($specific_columns_only) && self::is_assoc_array($specific_columns_only)){
		   //unset all the other columns
		   foreach($row as $key => $val){
		      if (empty($specific_columns_only[$key])){
		         //if not set or 0, then hide it by defaults
		         unset($row[$key]); 
		      }
		   }
		}

		$match_count = 0;
		$key_count = 0;
		$star_key = false;
		
		//go through each key value pair (most often 1)
      foreach($keyvals as $key => $pattern){
		      //does the key even exist in the row?
            if (!isset($row[$key]))
		      { 
		          if ($key == ''){ $key = '**'; }
		          
		          if ($key == '*') //special star key!!! match any value!!!
			  { 
  				$star_key = true; 
				foreach($row as $k => $v){
					if (is_array($matchtype)){
					  $mt = isset($matchtype[$k]) ? $matchtype[$k] : 'exact';
					}else{
					  $mt = $matchtype;
					}
               $matched = self::match($mt, $pattern, $v);
					if (in_array($k, $not)){ $matched = !$matched; }
					if ($matched && !$negate || !$matched && $negate){
						//echo 'star match: '.$pattern.' '.$v.'<br>';
						$key_count++;
						$match_count++;
					}
				}
			  }
			  else if ($key == '**') //another special search (split search by spaces)
			  {
				$star_key = true;
				$double_star_key = true;
				$patterns = explode(' ', $pattern);
				foreach($patterns as $p){
				
				    foreach($row as $k => $v){
                     if (is_array($matchtype)){
                       $mt = isset($matchtype[$k]) ? $matchtype[$k] : 'exact';
                     }else{
                       $mt = $matchtype;
                     }
					    $matched = self::match($mt, $p, $v);
						if (in_array($k, $not)){ $matched = !$matched; }
					    if ($matched && !$negate || !$matched && $negate){
						    //echo 'star match: '.$pattern.' '.$v.'<br>';
						    $key_count++;
						    $match_count++;
					    }
				    }
				
				}
			  }
                          else if ($key == '***') //yet another special search (try to narrow down)
                          {
                                $star_key = true;
                                $triple_star_key = true;
                                $patterns = explode(' ', $pattern);
                                foreach($patterns as $p){
                                
                                    foreach($row as $k => $v){
     					                        if (is_array($matchtype)){
                           					  $mt = isset($matchtype[$k]) ? $matchtype[$k] : 'exact';
                           					}else{
                           					  $mt = $matchtype;
                           					}
                                            $matched = self::match($mt, $p, $v);
											if (in_array($k, $not)){ $matched = !$matched; }
                                            if ($matched && !$negate || !$matched && $negate){
                                                    //echo 'star match: '.$pattern.' '.$v.'<br>';
                                                    $key_count++;
                                                    $match_count++;
                                            }
                                    }
                                
                                }
                          }
			  else //key not found, just pretend it didn't exist
			  {
				continue;
			  } 
		      }
	   	      else //does the specific find key match?
		      {
		         $key_count++;
					if (is_array($matchtype)){
					  $mt = isset($matchtype[$key]) ? $matchtype[$key] : 'exact';
					}else{
					  $mt = $matchtype;
					}
		         $matched = self::match($mt, $pattern, $row[$key]);
				 if (in_array($key, $not)){ $matched = !$matched; }
                         if ($matched && !$negate || !$matched && $negate){ $match_count++;}
		      }
		      
		     
		}//done iterating through all key-values, now check match count
	        //echo 'key count: '.$key_count.' match count: '.$match_count;
		//only add the row if the match count is valid
		if (    ($star_key && $match_count > 1) ||
			(empty($min_match_count) && $key_count >= 1 && $match_count >= $key_count) ||
                        ($min_match_count >= 1 && $match_count >= $min_match_count) )
		{
			$this->ffa_info['records_found']++;

			if (isset($double_star_key)){
			  $row['__jkdbase_matchcount'] = $match_count;
			}
			//store the record number 
			$row['__jkdbase_recordnumber'] = $i;
			if ($this->use_trim){
			  foreach($row as &$val){ $val = trim($val); }
			}
		        $rows[] = $row;
			
			//allow limits
			 if (is_numeric($limit) && $this->ffa_info['records_found'] >= $limit){
			    break;
			 }
			//access later using $info = $db->fetch_find_all_info(); 
			//echo 'dbase record number: '.$info['row_recordnumbers'][$rownum];
			//$this->ffa_info['row_recordnumbers'][] = $i;
                }

		$this->ffa_info['records_searched']++;
	  }
	  
	  //do we do anything with the rows after they are all added? ie sort by match count?
	  if (isset($double_star_key)){
	     self::sortBy($rows, '__jkdbase_matchcount', true);
	     $high_matchcount = 0;
	     $newrows = array();
	     foreach($rows as $row){
	        if ($row['__jkdbase_matchcount'] > $high_matchcount){
	          $high_matchcount = $row['__jkdbase_matchcount'];
	          $newrows[] = $row;
	        }else if ($high_matchcount == $row['__jkdbase_matchcount']){
	        
	          $newrows[] = $row;
	        }else{
	          break;
	        }
	     }
	     //this should shorten the results A LOT!, UNCOMMENT TO GO TO ORIGINAL
	     $rows = $newrows; 
	  }
	  
          return $rows;
  }

  //works for php 5.3 or higher
  public static function sortArray($data, $field) { if(!is_array($field)) $field = array($field); usort($data, function($a, $b) use($field) { $retval = 0; foreach($field as $fieldname) { if($retval == 0) $retval = strnatcmp($a[$fieldname],$b[$fieldname]); } return $retval; }); return $data; } 
  public static function sortArrayReverse($data, $field) { if(!is_array($field)) $field = array($field); usort($data, function($a, $b) use($field) { $retval = 0; foreach($field as $fieldname) { if($retval == 0) $retval = strnatcmp($a[$fieldname],$b[$fieldname]); $retval = -1 * $retval; } return $retval; }); return $data; } 
  public static function sortBy(&$items, $key, $descending = false){
  if (is_array($items)){
    return usort($items, function($a, $b) use ($key, $descending){
      $cmp = strCmp($a[$key], $b[$key]);
      return $descending? -$cmp : $cmp;
    });
  }
  return false;
}
  public function fetch_all()
  {
     $rows = array();
	  $record_numbers = dbase_numrecords($this->db);
	  for ($i = 1; $i <= $record_numbers; $i++) {
	      // do something here, for each record
		   $row = dbase_get_record_with_names($this->db, $i);
	      $rows[] = $row;
	  }
     return $rows;
  }
  

  /**
   * Use this to make fetch_assoc() work again from the beginning
   */
  public function reset_fetch(){ $this->fetch_i = 1; }


  /**
   * Fetch an associative array of the current record, then increment record index
   * @param int $exact_dbase_index if we would rather get the exact index (and also set it)
   */
  public function fetch_assoc($exact_dbase_index = null)
  {
    if (!is_null($exact_dbase_index)){ $this->fetch_i = $exact_dbase_index; }
    
    if ($this->fetch_i > $this->numrecords)
    {
       return null;
    }
    $row = dbase_get_record_with_names($this->db, $this->fetch_i);
    $this->fetch_i++;
    if ($this->use_trim){
      foreach($row as &$val){ $val = trim($val); }
    }
    return $row;
  }
}
