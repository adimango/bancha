<?php
/**
 * Wordpress Adapter Class
 *
 * A library that can read the Wordpress XML Export feed
 *
 * @package		Bancha
 * @author		Nicholas Valbusa - info@squallstar.it - @squallstar
 * @copyright	Copyright (c) 2011-2014, Squallstar
 * @license		GNU/GPL (General Public License)
 * @link		http://squallstar.it
 *
 */

Class Adapter_wordpress implements Adapter
{
	/**
	 * @var array Defines all the accepted mimes of the adapter
	 */
	private $_mimes;

	/**
	 * @var string The content type of the comments
	 */
	public $comment_type = 'Comments';

	public function __construct()
	{
		$this->mimes = array(
			'text/xml', 'application/xml'
		);
	}

	/**
	 * @var array Returns all the accepted mimes of the adapter
	 */
	public function get_mimes()
	{
		return $this->_mimes;
	}

	/**
	 * Parse the wordpress export feed and gives back an array of records (or saves it)
	 * @param mixed $stream The stream to parse
	 * @param bool $to_record Whether each records need to be return as a "Record" object or just an array
	 * @param string $type The default content type (used to create and save records)
	 * @param bool $autosave When set to TRUE, records will also be saved into the database
	 */
	public function parse_stream($stream, $to_record = TRUE, $type = '', $autosave = FALSE)
	{
		$categories = array();
		if ($autosave)
		{
			$B =& get_instance();
			$can_save_comments = $B->content->type_id($this->comment_type);

			//Available categories
			$B->load->categories();
			$blog_categories = $B->categories->type($type)->get();
			if (is_array($blog_categories) && count($blog_categories)) {
				foreach ($blog_categories as $cat) {
					$categories[ strtolower($cat->name) ] = $cat->id;
				}
			}
		}

		//We need to normalize some of the Wordpress nodes
		$prepared_stream = str_replace(
			array('content:encoded>', 'wp:comment>', 'wp:comment_author>', 'wp:comment_author_url>',
				  'wp:comment_date>', 'wp:comment_content>', 'wp:comment_author_email>'),
			array('content>', 'comments>', 'author>', 'www>',
				  'date_publish>', 'content>', 'email>'),

			$stream // (the source)
		);

		$dom = simplexml_load_string($prepared_stream, 'SimpleXMLElement', LIBXML_NOCDATA);

		if (!isset($dom->channel->item)) return FALSE;

		$channel = $dom->channel;
		$data = array();
		foreach ($channel->item as $item)
		{
			$title = (string)$item->title;
			$post = array(
				'title'			=> $title ? $title : _('Without title'),
				'date_publish'	=> date(LOCAL_DATE_FORMAT . ' H:i', strtotime((string)$item->pubDate)),
				'content'		=> (string)$item->content,
				'abstract'		=> (string)$item->description,
				'lang'			=> $B->lang->default_language,
				'categories'	=> array()
			);

			if (isset($item->category[0]))
			{
				foreach ($item->category as $cat) {
					$cat = strtolower((string)$cat);
					if ( in_array($cat, array_keys($categories))) {
						$post['categories'][] = $categories[$cat];
					}
				}
			}
			if (count($item->comments))
			{
				$comments = array();

				foreach ($item->comments as $comment)
				{
					$comments[] = array(
						'author'		=> (string)$comment->author,
						'www'			=> (string)$comment->www,
						'date_publish'	=> (string)$comment->date_publish,
						'content'		=> (string)$comment->content,
						'email'			=> (string)$comment->email,
						'lang'			=> $B->lang->default_language
					);
				}
				$post['comments'] = $comments;
			}
			$data[] = $post;
		}

		/** end of data preparing **/

		if (!$to_record)
		{
			return $data;
		} else {
			$records = array();
			foreach ($data as $row)
			{
				$post = new Record($type);
				if ($type != '')
				{
					$post->set_data($row);
				} else {
					foreach ($row as $key => $val)
					{
						$post->set($key, $val);
					}
				}
				if ($autosave)
				{
					$id = $B->records->save($post);
					$post->id = $id;
					$post->set('id_record', $id);

					//Categories
					if (count($row['categories'])) {
						$B->categories->set_record_categories($id, $row['categories']);
					}

					//Now we can try to save the comments
					$comments = isset($row['comments']) ? $row['comments'] : FALSE;
					if ($can_save_comments && is_array($comments) && count($comments))
					{
						$post_comments_count = 0;
						foreach ($comments as $comment)
						{
							//We try to create and save a single comment
							$post_comment = new Record($this->comment_type);
							$post_comment->set_data($comment);
							$post_comment->set('post_id', $post->id);
							$comment_id = $B->records->save($post_comment);

							if ($comment_id)
							{
								$post_comment->id = $comment_id;
								$post_comment->set('id_record', $comment_id);
								$post_comments_count++;

								//Records array is shared (so we have all the added records)
								$records[] = $post_comment;
							}
						}

						//If the post has comments, let's update the child count
						if ($post_comments_count > 0)
						{
							//$post->set('child_count', $post_comments_count);
							//$B->records->save($post);
						}
					}
				}
				$records[]= $post;
			}
			return $records;
		}
	}
}