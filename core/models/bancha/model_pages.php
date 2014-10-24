<?php
/**
 * Pages Model
 *
 * @package		Bancha
 * @author		Nicholas Valbusa - info@squallstar.it - @squallstar
 * @copyright	Copyright (c) 2011-2014, Squallstar
 * @license		GNU/GPL (General Public License)
 * @link		http://squallstar.it
 *
 */

Class Model_Pages extends CI_Model {

	public $table = 'pages';
	public $table_stage = 'pages_stage';
	public $table_current = '';
	public $cache_path;

	public function __construct()
	{
    	parent::__construct();

    	$path = $this->config->item('cache_path');
    	$this->cache_path = ($path == '') ? USERPATH.'cache/' : $path;
	}

	/**
	 * Imposto la tabella su cui fare query
	 * @param bool $stage
	 */
	public function set_stage($stage)
	{
        $this->table_current = $stage ? $this->table_stage : $this->table;
        return $this;
    }

    /**
     * Salvo una pagina
     * @param array $data i dati presi dalla records/save
     */
    public function save($data) {
    	$id_record = isset($data['id_record']) ? $data['id_record'] : '';
    	$id_parent = isset($data['id_parent']) ? $data['id_parent'] : '';

    	$full_uri = $data['uri'];

    	if ($id_parent != '')
    	{
			//Estraggo tutti i parent e creo il full uri
			while (is_numeric($id_parent))
			{
				$parent = $this->db->from($this->records->table_current)
								   ->where('id_record', $id_parent)
								   ->select('uri, id_parent')
								   ->limit(1)->get();
				if ($parent->num_rows())
				{
					$parent = $parent->result_array();
					$parent = $parent[0];
					$full_uri = $parent['uri'].'/'.$full_uri;
					$id_parent = $parent['id_parent'];
				} else {
					$id_parent = FALSE;
				}
			}
    	} else {
    		//Is a Root page
    	}

    	//Preparo i dati da salvare
		$to_save = array(
			'full_uri'		=> $full_uri,
			'uri'			=> $data['uri'],
			'id_parent'		=> $data['id_parent'],
			'title'			=> $data['title'],
			'id_type'		=> $data['id_type'],
			'lang'			=> isset($data['lang']) ? $data['lang'] : $this->lang->current_language,
			'show_in_menu'	=> isset($data['show_in_menu']) ? $data['show_in_menu'] : 'F',
			'date_publish'	=> isset($data['date_publish']) ? $data['date_publish'] : '',
			'priority'		=> isset($data['priority']) ? $data['priority'] : '0',
		);

		//Controllo se esiste
		$exist = $this->db->from($this->table_current)
						  ->select('full_uri')
						  ->limit(1)
						  ->where('id_record', $id_record)
						  ->get()->result_array();
		if (count($exist))
		{
			//Update
			$done = $this->db->where('id_record', $id_record)
							 ->update($this->table_current, $to_save);

			//We clear the page cache
			$this->clear_cache($full_uri);


		} else {
			$to_save['id_record'] = $id_record;
			$done = $this->db->insert($this->table_current, $to_save);
		}

		if ($done && count($exist))
		{
			$old_full_uri = $exist[0]['full_uri'];
			//We update the childs
			$this->update_uris($old_full_uri, $full_uri, $this->table_current, $id_record);
		}

    }

    /**
     * We update the slugs of the child pages
     * @param string $from_uri
     * @param string $to_uri
     * @param string $table
     */
    public function update_uris($from_uri, $to_uri, $table='', $exclude_id='')
    {
    	if ($table == '')
    	{
    		$table = $this->table_current;
    	}
    	if ($exclude_id != '')
    	{
    		$this->db->where('id_record !='.$exclude_id);
    	}
    	$this->db->where('id_record IS NOT NULL')
    			 ->like('full_uri', $from_uri.'%')
				 ->set('full_uri',
					   "replace(full_uri, '".$from_uri."', '".$to_uri."')",
					   FALSE)
			     ->update($table);

    }

    /**
     * Pubblico una pagina
     * @param int $id_record
     */
    public function publish($id_record)
    {
    	$records = $this->db->select('*')
    						->where('id_record', $id_record)
    						->from($this->table_stage)
    						->limit(1)->get()->result_array();

    	if (count($records))
    	{
    		$record = $records[0];

    		//Ottengo la vecchia pagina per il vecchio uri
    		$old_uri = FALSE;
    		$old_records = $this->db->select('full_uri')
    						->where('id_record', $id_record)
    						->from($this->table)
    						->limit(1)->get()->result_array();
    		if (count($old_records)) {
    			$old_uri = $old_records[0]['full_uri'];
    		}

    		//Elimino la vecchia pagina
    		$this->db->where('id_record', $id_record)->delete($this->table);

    		$done = $this->db->insert($this->table, $record);
    		if ($done)
    		{
    			if ($old_uri)
    			{
    				//Aggiorno gli url delle pagine figlie in produzione
    				$this->update_uris($old_uri, $record['full_uri'], $this->table, $id_record);

    				//Cancello la cache
    				$this->clear_cache($old_uri);
    			}

    			//Invalido la cache
    			$this->tree->clear_cache($record['id_type']);
    			return TRUE;
    		}
    	}
    	return FALSE;
    }

    /**
     * Depubblico una pagina
     * @param int $id_record
     * @return bool
     */
    public function depublish($id_record)
    {
    	$record = $this->db->select('full_uri')
    					   ->from($this->table)
    					   ->where('id_record', $id_record)
    					   ->limit(1)->get()->result_array();
    	if (count($record)) {
    		return $this->db->where('id_record', $id_record)->delete($this->table);
    	}
    }

    /**
     * Elimino una pagina e le pagine figlie
     * @param int $id_record
     * @param string $table
     * @return bool
     */
    public function delete($id_record, $table='')
    {

    	$table = $table != '' ? $table : $this->table_current;

  		$record = $this->db->select('full_uri')
    					   ->from($table)
    					   ->where('id_record', $id_record)
    					   ->limit(1)->get()->result_array();
    	if (count($record))
    	{
    		//Cancellazione pagine figlie
    		$full_uri = $record[0]['full_uri'];
    		$this->db->where('id_parent IS NOT NULL')
    				 ->like('full_uri', $full_uri.'%')
    				 ->delete($table);
    		return $this->db->where('id_record', $id_record)->delete($table);
    	}
    }

    /**
     * Elimino pagina e pagine figlie sia in sviluppo che produzione
     * @param int $id_record
     */
    public function delete_all($id_record)
    {
		$this->delete($id_record, $this->table_stage);
    	$this->delete($id_record, $this->table);
    }

    /**
     * Gets the full uri of a page, given the id_record
     * @param int $id_record
     * @return string
     */
    public function get_record_url($id_record)
    {
    	$result = $this->db->select('full_uri')
    					   ->from($this->table_current)
    					   ->where('id_record', $id_record)
    					   ->limit(1)
    					   ->get()->result_array();
    	if (count($result))
    	{
    		return $result[0]['full_uri'];
    	}
    	return FALSE;
    }

    /**
     * Clears the page cache for each theme, or just a single page when a URI is passed
     * @param int $id_record
     */
    public function clear_cache($uri)
    {
    	$uri =	$this->config->item('base_url').
    			$this->config->item('index_page').
    			$uri;
    	$uri = md5($uri);

    	//Gets the current desktop and mobile themes
    	if (!isset($this->settings))
    	{
    		$this->load->settings();
    	}
    	$themes = array(
	    	$this->settings->get('website_desktop_theme'),
	    	$this->settings->get('website_mobile_theme')
	    );

	    foreach ($themes as $theme)
	    {
	    	$filepath = $this->cache_path . $this->output->get_cachefile($uri, $theme);
	    	if (file_exists($filepath))
	    	{
	    		@unlink($filepath);
	    	}
	    }
    }

    /**
     * Tries to find the the semantic URL of a page
     * @see semantic_url() in website_helper
     * @param string|int $type
     */
    public function get_semantic_url($type)
    {
    	if (isset($this->view->semantic_url[$type]))
    	{
    		return $this->view->semantic_url[$type];
    	}
		foreach ($this->config->item('default_tree_types') as $type_name)
		{
			$record = $this->records->type($type_name)
								     ->where('action', 'list')
								     ->where('action_list_type', $type)
								     ->get_first();
			if ($record)
			{
				//Record found. Let's return the page
				$url = $this->get_record_url($record->id);
				$this->view->semantic_url[$type] = $url;
				return $url;
			}
		}
  }
}










