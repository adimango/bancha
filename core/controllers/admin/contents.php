<?php
/**
 * Contents Controller
 *
 * This controller manage, creates and deletes any type of content
 *
 * @package		Bancha
 * @author		Nicholas Valbusa - info@squallstar.it - @squallstar
 * @copyright	Copyright (c) 2011-2014, Squallstar
 * @license		GNU/GPL (General Public License)
 * @link		http://squallstar.it
 *
 */

if ( ! defined('BASEPATH')) exit('No direct script access allowed');

Class Core_Contents extends Bancha_Controller
{

    /**
     * @var string Current section
     */
    private $_section;

  	public function __construct()
  	{
	    parent::__construct();
        $this->load->database();

	    //We are always in staging here
	    $this->content->set_stage(TRUE);

	    //Views base path
	    $this->view->base = 'admin/';

	    //Contents-Pages section
	    $this->_section = $this->uri->segment(2);
	    $this->view->set('_section', $this->_section);

	    //All actions needs user login
	    $this->auth->needs_login();

	    //Loads the events model
	    $this->load->events();
  	}

    /**
    * Content types list
    */
    public function index()
    {
    	redirect(admin_url('schemes'));
    }

    /**
    * Legacy name of the record_list function
    * @param int|string $tipo
    * @param int $page
    * @param int $per_page
    */
    public function type($tipo='', $page=0, $per_page=0)
    {
    	$this->record_list($tipo, $page, $per_page);
    }

    /**
    * A record list of a single content type
    * @param int|string $tipo
    * @param int $page
    * @param int $per_page
    */
    public function record_list($tipo='', $page=0, $per_page = 0)
    {
        if ($tipo == '')
        {
            $this->index();
            return;
        }
        $type = $this->content->type($tipo);

        //ACL Check
        $this->auth->check_permission('content', $type['name']);

    	$this->records->set_type($tipo);
        $this->view->set('tipo', $type);

        //Record publish
        $to_publish = $this->input->get('publish');
        if ($to_publish)
        {
        	$done = $this->records->publish($to_publish, $type);
        	if ($done)
        	{
				if ($type['tree'])
				{
					$this->pages->publish($to_publish);
					$this->tree->clear_cache();
				}

				$this->view->message('success', 'The record ['.$to_publish.'] has been published.');
        	}
        }

    	//Record depublish
        $to_depublish = $this->input->get('depublish');
        if ($to_depublish)
        {
        	$done = $this->records->depublish($to_depublish, $type);
        	if ($done)
        	{
				if ($type['tree'])
				{
					$this->pages->depublish($to_publish);
					$this->tree->clear_cache();
				}
				$this->view->message('success', 'The record ['.$to_depublish.'] has been depublished.');
        	}
        }

        //Various actions
        if ($this->input->post('action'))
        {
        	$records = $this->input->post('record');
        	if (is_array($records) && count($records))
        	{
            	switch ($this->input->post('action'))
            	{
            		case 'publish':
            			foreach ($records as $record)
            			{
            				$this->records->publish($record, $type);
            				if ($type['tree']) $this->pages->publish($record);
            				$this->view->message('success', _('The records have been published.'));
            			}
            			break;
        			case 'depublish':
        				foreach ($records as $record)
        				{
        					$this->records->depublish($record, $type);
        					if ($type['tree']) $this->pages->depublish($record);
        					$this->view->message('success', _('The records have been depublished.'));
        				}
        				break;
        			case 'delete':
        				foreach ($records as $record)
        				{
        					$this->delete_record(NULL, $record, TRUE);
        					$this->view->message('success', _('The records have been deleted.'));
        				}
        				break;
                    case 'discard':
                    	foreach ($records as $record)
        				{
        					$this->records->discard($record);
        					$this->view->message('success', _('The records have been rolled back to the production ones.'));
        				}
                        break;
            	}
        	}
        }

        //Automatic filters
        $this->db->start_cache();
        $post_filters = $this->input->post('filter');
        foreach (array_keys($type['fields']) as $field) {
        	if ($type['fields'][$field]['admin'] === true) {
        			$admin_fields[] = $field;
        			$filters[$field] = isset($post_filters[$field]) ? $post_filters[$field] : '';
        			if ($filters[$field] != '') {
        				$this->records->like($field, $filters[$field]);
        			}
        	}
        }

        $parent_id = $this->input->get('parent');
        if (is_numeric($parent_id))
        {
        	$this->records->where('id_parent', $parent_id);
        }

        //Manual filters
        $filters_manual = array($type['primary_key'], 'published');
        foreach ($filters_manual as $filter) {
        	$filters[$filter] = isset($post_filters[$filter]) ? $post_filters[$filter] : '';
        	if ($filters[$filter] != '') {
        		$this->records->where($filter, $filters[$filter]);
        	}
        }

        $this->session->set_userdata('record_filters', $filters);

        $this->db->stop_cache();

        $this->view->set('filters', $filters);
        $this->view->set('admin_fields', $admin_fields);

        //Pagination
        $per_page = $per_page != 0 ? $per_page : $this->config->item('records_per_page');
        $pagination = array(
            	'total_rows'	=> $this->records->type($tipo)->count(),
            	'per_page'		=> $per_page,
            	'base_url'		=> admin_url('contents/type/'.$tipo.'/'),
            	'uri_segment'	=> 5,
            	'cur_tag_open'	=> '<a href="#" class="active">',
            	'cur_tag_close'	=> '</a>'
        );

        $this->view->set('total_records', $pagination['total_rows']);

        $this->load->library('pagination');
        $this->pagination->initialize($pagination);

        //We get the records
        $records = $this->records->type($tipo)
        						 ->set_adminlist(TRUE)
        						 ->order_by($type['order_by']['field'], $type['order_by']['sort'])
        						 ->limit($pagination['per_page'], $page)
        						 ->get();

        $this->db->flush_cache();

        $this->view->set('records', $records);

        if ($this->session->flashdata('message')) {
        	$this->view->message('success', $this->session->flashdata('message'));
        }

        $this->view->render_layout('content/record_list');
    }

    /**
    * Adds a child record
    * @param int|string $type
    * @param int $child_id
    */
    public function add_child_record($type='', $child_id='')
    {
    	$this->edit_record($type, '', $child_id);
    }

    /**
    * Edit form for a single record
    * @param int|string $type
    * @param int $record_id
    */
    public function edit_record($type='', $record_id='', $child_id='')
    {

        $this->load->categories();
        $this->load->hierarchies();
        $this->load->documents();

        $tipo = $this->content->type($type);
        $this->records->set_type($type);

        //ACL Check
        $this->auth->check_permission('content', $tipo['name']);

        //Add-Edit record
        if ($this->input->post('id_type', FALSE)) {

        	$id_type = $this->input->post('id_type', FALSE);

          	$record = $this->content->make_record($id_type, $this->input->post(NULL, FALSE));

            //We save the record
            $record->id = $this->records->save($record);

            if ($record->id) {
	      	    if ($tipo['has_categories']) {
	      		   //We update the linked record categories
	      		   $this->categories->set_record_categories($record->id, $this->input->post('categories'));
	      	    }

    	      	if ($tipo['has_attachments'] && count($_FILES))
                {
    	      		$files_copy = $_FILES;

    				foreach ($files_copy as $name => $val)
                    {
    					$count = count($val['tmp_name']);

    					for ($i = 0; $i < $count; $i++)
    					{
    						if ($val['tmp_name'][$i] != '')
                            {
    							$upload_config = array(
    								'allowed_types' => $tipo['fields'][$name]['mimes'],
    								'encrypt_name'	=> $tipo['fields'][$name]['encrypt_name'],
    								'max_size'		=> $tipo['fields'][$name]['size'],
    								'resized'		=> isset($tipo['fields'][$name]['resized']) ? $tipo['fields'][$name]['resized'] : FALSE,
    								'thumbnail'		=> isset($tipo['fields'][$name]['thumbnail']) ? $tipo['fields'][$name]['thumbnail'] : FALSE
    							);

    							$single_file = array(
    								'name'		=> $val['name'][$i],
    								'type'		=> $val['type'][$i],
    								'tmp_name'	=> $val['tmp_name'][$i],
    								'size'		=> $val['size'][$i],
    							);

    							//Fix for handling multiple files
    							$_FILES[$name] = $single_file;

    							$this->documents->upload($name, $upload_config, array(
    								'id'	=> $record->id,
    								'table'	=> $tipo['table'],
    								'field'	=> $name,
    								'type'	=> $tipo['name'],
    								'name'	=> $single_file['name']
    							));
    						}
    					}
    				}
    	      	}
            }

            //We update all the alternative texts
            if ($this->input->post('_alt_text', FALSE)) {
                $alt_texts = $this->input->post('_alt_text', FALSE);
                $priorities = $this->input->post('_priority', FALSE);
                foreach ($alt_texts as $document_id => $new_text) {
                    if (isset($priorities[$document_id]))
                    {
                        //TODO: Sometimes the key $priorities[$document_id] is not set...
                        $this->documents->update_alt_text($document_id, $new_text, $priorities[$document_id]);
                    }
                }
            }

            //We delete this hierarchies
            if ($tipo['has_hierarchies'])
            {
                $new_hierarchies = $this->input->post('_hierarchies', FALSE);
                $this->hierarchies->update_record_hierarchies($record->id, $new_hierarchies);
            }

            //We clear the cache of this type
            $this->tree->clear_cache($tipo['name']);
            if (in_array($tipo['name'], $this->config->item('default_tree_types')))
            {
            	$this->tree->clear_cache();
            }

            $value = $record->get('title');
            if (!$value || $value == '')
            {
                $value = $record->get($tipo['edit_link']);
            }

            $content_edit_link = '<a href="'.admin_url(''.$this->_section.'/edit_record/'.$tipo['name'].'/'.$record->id).'">'.$value.'</a>';

            if ($this->input->post('_bt_save_list'))
            {
                $msg = $this->lang->_trans('The content %n has been saved.', array('n' => $content_edit_link));
          		$this->session->set_flashdata('message', $msg);
                redirect(ADMIN_PUB_PATH.$this->_section.'/type/' . $tipo['name']);

            } else if ($this->input->post('_bt_publish')) {
          		$this->records->publish($record->id, $type);
          		if ($tipo['tree'])
          		{
          			$this->pages->publish($record->id);
          		}
                $msg = $this->lang->_trans('The content %n has been published.', array('n' => $content_edit_link));
                $msg .= ' <a target="_blank" href="' . admin_url('preview/content/' . $tipo['name'] . '/' . $record->id) . '">' . _('View content') . ' &rarr;</a>';
                $this->session->set_flashdata('message', $msg);
                redirect(ADMIN_PUB_PATH.$this->_section.'/type/' . $tipo['name']);
            } else {

            	//We add the preview link
            	$saved_msg = _('The content has been saved.') . ' <a target="_blank" href="' . admin_url('preview/content/' . $tipo['name'] . '/' . $record->id) . '">' . _('View content') . ' &rarr;</a>';

                if ($record_id == '')
                {
                    //If it's a new record, we redirect to the same page (F5 refresh fix for duplicate records)
                    $this->session->set_flashdata('message', $saved_msg);
                    redirect(ADMIN_PUB_PATH.$this->_section.'/edit_record/' . $tipo['name'] . '/' . $record->id);
                } else {
                    $this->view->message('success', $saved_msg);
                }
            }

        } else if ($record_id != '') {
            //Edit record
            $record = $this->records->get($record_id);
        } else {
        	//New record
    		$record = $this->content->make_record($tipo['id']);
        }

        if (!$record)
        {
        	show_error(_('Something went wrong...') . ' (content/edit_record)');
        }

        if ($tipo['tree'])
        {
    		$tree = $this->tree->parent_types($tipo['id'])
    						   ->exclude_page($record->id)
    						   ->exclude_parent($record->id)
    						   ->show_invisibles()
    						   ->get_linear_dropdown();

    		$this->view->set('tree', $tree);

            //The first option value
    		$parent_tree = array(
    			'' => '--- '._('First level').' ---'
    		);

    		foreach ($tree as $item_key => $item_val)
            {
    			$parent_tree[$item_key] = $item_val;
    		}
    		$this->view->set('parent_tree', $parent_tree);

    		//If it has a parent page
    		if ($child_id != '')
            {
    			$record->set('id_parent', $child_id);
    		}

    		//We build the current url of this page
    		if ($record->id)
    		{
    			$this->view->set('page_url', $this->pages->get_record_url($record->id));
    		}
        }

        if ($tipo['has_categories'])
        {
        	$this->view->set('categories', $this->categories->type($tipo['id'])->order_by('name', 'ASC')->get());
    		$record->set('categories', $this->categories->get_record_categories($record->id));
        }

        if ($tipo['has_hierarchies'])
        {
            if ($record->id)
            {
            	$hierarchies = $this->hierarchies->get_record_hierarchies($record->id);
            	$this->hierarchies->set_active_nodes($hierarchies);
            }
        	$this->config->set_item('hierarchies', $this->hierarchies->get_tree());
        }

        if ($tipo['has_attachments'] && $record->id)
        {
    		$record->set_documents();
        }

        //Additional set-ups before the page rendering
        foreach ($tipo['fields'] as $field_name => $field_value)
        {
        	if (isset($field_value['extract']))
            {
                //We extract the custom options
    			$tipo['fields'][$field_name]['options'] = $this->records->get_field_options($field_value);
        	}
            else if ($tipo['fields'][$field_name]['type'] == 'hierarchy')
            {
                //We need to extract the active hierarchies for this field
                $val = $record->get($field_name);
                if (is_array($val) && count($val))
                {
                    $this->hierarchies->set_active_nodes($val);
                }
                $tipo['fields'][$field_name]['options'] = $this->hierarchies->get_tree();
            }
        }

        if ($this->session->flashdata('message'))
        {
            $this->view->message('success', $this->session->flashdata('message'));
        }

        $this->view->set('tipo', $tipo);
        $this->view->set('record', $record);

        $this->view->render_layout('content/record_edit');
    }

    /**
    * Deletes a record from the DB
    * @param int|string $tipo
    * @param int $id_record
    * @param bool $callback
    * @return bool success (only when $callback is set to false)
    */
    public function delete_record($tipo='', $id_record='', $callback=FALSE)
    {
      	if ($id_record != '')
        {
    		if ($tipo)
            {
    			$tipo = $this->content->type($tipo);
    		} else {
    			$record = $this->records->get($id_record);
    			$tipo = $this->content->type($record->_tipo);
    		}

            //ACL Check
            $this->auth->check_permission('content', $tipo['name']);

    		$done = $this->records->delete_by_id($id_record, $tipo['id']);

    		if (!$done)
            {
                show_error($this->lang->_trans('Cannot delete the record %r of type %t', array('r' => $id_record, 't' => $tipo['description'])), 500, _('Error'));
    		}else {

    			if ($tipo['tree'])
    			{
    				//We delete the linked pages
    				$this->pages->delete_all($id_record);
    			}

    			//We delete the linked documents
      			$this->load->documents();
    			$this->documents->delete_by_binds($tipo['table'], $id_record);

    			$this->tree->clear_cache();

    			if ($tipo != '')
    			{
    				$this->tree->clear_cache($tipo['name']);
    			}

    			if (!$callback)
    			{
    				$this->session->set_flashdata('message', $this->lang->_trans('The record %n has been deleted.', array('n' => $id_record)));
    				redirect(ADMIN_PUB_PATH.$this->_section.'/type/' . $tipo['name']);
    			} else {
                    return true;
                }
    		}
      	}else {
      		show_error(_('The record ID was not set.'), 500, _('Error'));
      	}
    }

    /**
    * Form to insert a new content type
    */
    public function add_type()
    {
        //ACL Check
        $this->auth->check_permission('types', 'add');

        if ($this->input->post()) {
            $type_name = $this->input->post('type_name');
            if ($type_name)
            {
            	$done = $this->content->add_type(array(
            		'name'          => $type_name,
            		'description'   => $this->input->post('type_description'),
            		'structure'     => $this->input->post('type_tree'),
            		'label_new'     => $this->input->post('type_label_new'),
                    'scheme_format' => $this->input->post('scheme_format')
            	));

                if ($done)
                {
                	redirect(admin_url('schemes'));
                }

            } else {
                $this->view->set('message', _('Please insert a name for this type.'));
            }
        }
        $this->view->render_layout('content/type_add');
    }

    /**
    * Content type scheme edit
    * @param int|string $type The content type
    */
    public function type_edit_xml($type = '') {
  		$tipo = $this->content->type($type);

        $source = isset($tipo['source']) ? $tipo['source'] : 'xml';

        //ACL Check
        $this->auth->check_permission('types', 'manage');
        $this->auth->check_permission('content', $tipo['name']);

  		$xml_path = $this->config->item('xml_typefolder') . $tipo['name'] . '.' . $source;

  		if ($this->input->post('xml')) {
			$done = write_file($xml_path, $this->input->post('xml'));
			if ($done) {

				$link = '<a href="'.admin_url('contents/type/'.$tipo['name']).'">'.$tipo['name'].'</a>';
                $msg = $this->lang->_trans('The scheme of the content type %n has been updated', array('n' => $link));
				$this->session->set_flashdata('message', $msg);

				$this->content->rebuild();
				redirect(admin_url('schemes'));
			} else {
				show_error(_('Cannot save that scheme.'), 500, _('Saving error'));
			}
  		}

  		$xml = read_file($xml_path) OR show_error(_('Cannot read the file.'));

  		$this->view->set('tipo', $tipo);
  		$this->view->set('xml', $xml);
  		$this->view->render_layout('content/type_edit_xml');
    }

    /**
    * Lista delle categorie di un tipo
    */
    public function type_categories($type = '') {
		$this->load->categories();

		$tipo = $this->content->type($type);

        //ACL Check
        $this->auth->check_permission('content', $tipo['name']);

		$category_name = $this->input->post('category_name');

		if (strlen($category_name)) {
			if (!$this->categories->exists($tipo['id'], $category_name)) {
  			$done = $this->categories->add(
  				$tipo['id'],
  				$category_name
  			);
  			if ($done) {
  				$this->view->set('message_ok', $this->lang->_trans('The category %n has been added.', array('n' => '['.$category_name.']')));
  			} else {
  				$this->view->set('message', $this->lang->_trans('Cannot insert the category %n.', array('n' => '['.$category_name.']')));
  			}
			} else {
				$this->view->set('message', $this->lang->_trans('A category named %n already exists.', array('n' => '['.$category_name.']')));
			}
		}

		$categories = $this->categories->type($tipo['id'])->order_by('name')->get();

		$this->view->set('tipo', $tipo);
		$this->view->set('categories', $categories);
		$this->view->render_layout('content/type_categories');
    }

    /**
    * Deletes a category
    * @param int|string $type_id
    * @param int $cat_id
    */
    public function type_categories_delete($type_id='', $cat_id='') {
		if ($cat_id != '')
        {
			$this->load->categories();
			$done = $this->categories->delete_by_id($cat_id);

			if ($done)
            {
				$this->view->set('message_ok', $this->lang->_trans('The category %n has been deleted.', array('n' => '['.$cat_id.']')));
			} else {
				$this->view->set('message', 'Cannot delete the category.');
			}
			$this->type_categories($type_id);
    	}
    }

    /**
    * Deletes a content type
    * @param int|string $type The content type
    */
    public function type_delete($type='')
    {
  		$tipo = $this->content->type($type);

        //ACL Check
        $this->auth->check_permission('types', 'delete');
        $this->auth->check_permission('content', $tipo['name']);

  		if ($this->input->post('cancel'))
        {
  		    redirect(ADMIN_PUB_PATH.'contents');
  		} else if ($this->input->post('delete'))
        {
	  		$xml_path = $this->config->item('xml_typefolder') . $tipo['name'] . '.' . (isset($tipo['source']) ? $tipo['source'] : 'xml');
	  		$done = file_exists($xml_path) ? @unlink($xml_path) : TRUE;
	  		if ($done)
            {
	  			//Deletes the ACLs
	  			$this->load->users();
	  			$this->users->delete_acl('content', $tipo['name']);

	  			//Deletes the events
	  			$this->load->events();
	  			$this->events->delete_by_content_type($tipo['name']);

	  			//Deletes the type from the Database
	  			$this->db->where('name', $tipo['name'])->delete('types');

	  			//Deletes the dead records
	  			if ($this->config->item('delete_dead_records') == TRUE)
	  			{
	  				$this->db->where('id_type', $tipo['id'])
	  				->delete($tipo['table']);
	  				if ($tipo['stage'])
	  				{
	  					$this->db->where('id_type', $tipo['id'])
	  					->delete($tipo['table_stage']);
	  				}
	  			}

	  			//Rebuild the cache
	  			$this->content->rebuild();

	  			$this->session->set_flashdata('message', $this->lang->_trans('The type named %n has been removed.', array('n'=>'['.$tipo['name'].']')));
	  			redirect(ADMIN_PUB_PATH.'contents');
	  		}
  		} else {
  			$this->view->set('tipo', $tipo);
  			$this->view->render_layout('content/type_delete');
  		}
    }
}
