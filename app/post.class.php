<?php
defined('PROJECT_PATH') OR exit('No direct script access allowed');

class Post
{
	private static function login_protected(){
		if(!User::is_logged_in()){
			throw new Exception(__("You need to be logged in to perform this action."));
		}
	}

	private static function parse_content($c){
		// Step 1: Preserve HTML tags using unique placeholders
		$html_placeholders = [];
		$placeholder_counter = 0;
		
		// Preserve allowed HTML tags (a, img, center, div, span, etc.)
		$c = preg_replace_callback('/<[^>]+>/i', function($matches) use (&$html_placeholders, &$placeholder_counter) {
			$placeholder = '§§§HTMLTAG' . $placeholder_counter . 'END§§§';
			$html_placeholders[$placeholder] = $matches[0];
			$placeholder_counter++;
			return $placeholder;
		}, $c);

		// Step 2: Escape HTML entities (for non-tag content)
		$c = htmlentities($c, ENT_QUOTES, 'UTF-8');

		// Step 3: Unescape placeholders so we can work with them
		foreach($html_placeholders as $placeholder => $html) {
			$c = str_replace(htmlentities($placeholder), $placeholder, $c);
		}

		// Step 4: Parse Markdown features
		
		// Code blocks (triple backticks) - must be processed before inline code
		$c = preg_replace_callback('/```([a-zA-Z0-9_+-]*)\n(.*?)\n```/s', function($matches) {
			$lang = $matches[1] ? htmlspecialchars($matches[1]) : '';
			$code = $matches[2];
			return '<code class="' . $lang . '">' . $code . '</code>';
		}, $c);

		// Inline code (single backticks)
		$c = preg_replace_callback('/`([^`]+)`/', function($matches) {
			return '<code>' . $matches[1] . '</code>';
		}, $c);

		// Headers (# ## ###)
		$c = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $c);
		$c = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $c);
		$c = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $c);

		// Bold - support both **text** and __text__
		$c = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $c);
		$c = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $c);

		// Italic - support both *text* and _text_ (but not if already part of ** or inside placeholders)
		$c = preg_replace('/(?<!\*)\*(?!\*)([^§]+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $c);
		$c = preg_replace('/(?<!_)_(?!_)([^§]+?)(?<!_)_(?!_)/s', '<em>$1</em>', $c);

		// Strikethrough (~~text~~)
		$c = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $c);

		// Horizontal rules (---)
		$c = preg_replace('/^---$/m', '<hr>', $c);

		// Blockquotes (> text)
		$c = preg_replace_callback('/^(&gt;|>) (.+)$/m', function($matches) {
			return '<blockquote>' . $matches[2] . '</blockquote>';
		}, $c);

		// Unordered lists (- item or * item)
		$c = preg_replace_callback('/(?:^|\n)((?:(?:^|\n)[-*] .+)+)/m', function($matches) {
			$items = preg_replace('/^[-*] (.+)$/m', '<li>$1</li>', $matches[1]);
			return "\n<ul>" . $items . "</ul>\n";
		}, $c);

		// Ordered lists (1. item)
		$c = preg_replace_callback('/(?:^|\n)((?:(?:^|\n)\d+\. .+)+)/m', function($matches) {
			$items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $matches[1]);
			return "\n<ol>" . $items . "</ol>\n";
		}, $c);

		// Tables (|col1|col2|)
		$c = preg_replace_callback('/\n((?:\|.+\|\n?)+)/m', function($matches) {
			$rows = explode("\n", trim($matches[1]));
			$html = '<table border="1">';
			foreach($rows as $i => $row) {
				if(preg_match('/^\|[\s\-:|]+\|$/', $row)) {
					// Skip separator rows like |---|---|
					continue;
				}
				$cells = array_map('trim', explode('|', trim($row, '|')));
				$tag = ($i === 0) ? 'th' : 'td';
				$html .= '<tr>';
				foreach($cells as $cell) {
					$html .= "<$tag>$cell</$tag>";
				}
				$html .= '</tr>';
			}
			$html .= '</table>';
			return "\n" . $html . "\n";
		}, $c);

		// Auto-link URLs (but not if already in <a> tags or placeholders)
		$c = preg_replace_callback('/(https?\:\/\/[^\s<>"]+)/i', function($matches) {
			// Don't link if it's inside a placeholder
			if(strpos($matches[0], '§§§') !== false) {
				return $matches[0];
			}
			return '<a href="' . $matches[0] . '" target="_blank">' . $matches[0] . '</a>';
		}, $c);

		// Convert quotes "text" to „text"
		$c = preg_replace('/&quot;(.+?)&quot;/i', '„$1"', $c);

		// Hashtags (#tag) to span.tag
		$c = preg_replace('/(\#[A-Za-z0-9-_]+)(\s|$)/i', '<span class="tag">$1</span>$2', $c);

		// Step 5: Convert line breaks
		$c = nl2br($c);

		// Step 6: Restore HTML placeholders
		foreach($html_placeholders as $placeholder => $html) {
			$c = str_replace($placeholder, $html, $c);
		}

		return $c;
	}

	private static function raw_data($raw_input){
		$default_input = [
			"text" => '',
			"plain_text" => '',
			"feeling" => '',
			"persons" => '',
			"location" => '',
			"content_type" => '',
			"content" => '',
			"privacy" => ''
		];

		// Handle only allowed keys
		$raw_output = array();
		foreach($default_input as $key => $def){
			// Key exists in input
			if(array_key_exists($key, $raw_input)){
				$raw_output[$key] = $raw_input[$key];
			} else {
				$raw_output[$key] = $default_input[$key];
			}
		}

		if($raw_output['privacy'] != "public" && $raw_output['privacy'] != "friends"){
			$raw_output['privacy'] =  "private";
		}

		return $raw_output;
	}

	public static function insert($r){
		self::login_protected();

		$data = self::raw_data($r);

		if(empty($data['text'])){
			throw new Exception(__("No data."));
		}

		$data['plain_text'] = $data['text'];
		$emoji_map = [
    	    ':smile:'        => '😄',
    		':laugh:'        => '😂',
    		':wink:'         => '😉',
    		':heart:'        => '❤️',
			':broken_heart:' => '💔',
			':fire:'         => '🔥',
			':star:'         => '⭐',
			':check:'        => '✅',
			':cross:'        => '❌',
			':thumbs_up:'    => '👍',
			':thumbs_down:'  => '👎',
			':clap:'         => '👏',
			':party:'        => '🥳',
			':thinking:'     => '🤔',
			':sweat:'        => '😅',
			':cry:'          => '😢',
			':sleep:'        => '😴',
			':rocket:'       => '🚀',
			':zap:'          => '⚡',
			':warning:'      => '⚠️',
			':tada:'         => '🎉',
			':coffee:'       => '☕',
			':cake:'         => '🍰',
			':sun:'          => '☀️',
			':moon:'         => '🌙',
			':cloud:'        => '☁️',
			':rainbow:'      => '🌈',
			':flower:'       => '🌸',
			':dog:'          => '🐶',
			':cat:'          => '🐱',
		];
		foreach($emoji_map as $code => $emoji){
		    $data['text'] = str_replace($code, $emoji, $data['text']);
	}

		$data['text'] = self::parse_content($data['text']);
		$data['datetime'] = 'NOW()';
		$data['status'] = '1';

		$data['id'] = DB::get_instance()->insert('posts', $data)->last_id();

		$data['datetime'] = date("d M Y H:i");
		unset($data['plain_text']);

		return $data;
	}

	public static function update($r){
		self::login_protected();

		$data = self::raw_data($r);

		$data['plain_text'] = $data['text'];
		$emoji_map = [
			':smile:'        => '😄',
			':laugh:'        => '😂',
			':wink:'         => '😉',
			':heart:'        => '❤️',
			':broken_heart:' => '💔',
			':fire:'         => '🔥',
			':star:'         => '⭐',
			':check:'        => '✅',
			':cross:'        => '❌',
			':thumbs_up:'    => '👍',
			':thumbs_down:'  => '👎',
			':clap:'         => '👏',
			':party:'        => '🥳',
			':thinking:'     => '🤔',
			':sweat:'        => '😅',
			':cry:'          => '😢',
			':sleep:'        => '😴',
			':rocket:'       => '🚀',
			':zap:'          => '⚡',
			':warning:'      => '⚠️',
			':tada:'         => '🎉',
			':coffee:'       => '☕',
			':cake:'         => '🍰',
			':sun:'          => '☀️',
			':moon:'         => '🌙',
			':cloud:'        => '☁️',
			':rainbow:'      => '🌈',
			':flower:'       => '🌸',
			':dog:'          => '🐶',
			':cat:'          => '🐱',	
		];
		foreach($emoji_map as $code => $emoji){
    	$data['text'] = str_replace($code, $emoji, $data['text']);
	}

		$data['text'] = self::parse_content($data['text']);

		DB::get_instance()->update('posts', $data, "WHERE `id` = ? AND `status` <> 5", $r["id"]);

		unset($data['plain_text']);

		return $data;
	}

	public static function hide($r){
		self::login_protected();

		DB::get_instance()->query("
			UPDATE `posts`
			SET `status` = 4
			WHERE `id` = ?
			AND `status` <> 5
		", $r["id"]);
		return true;
	}

	public static function show($r){
		self::login_protected();

		DB::get_instance()->query("
			UPDATE `posts`
			SET `status` = 1
			WHERE `id` = ?
			AND `status` <> 5
		", $r["id"]);
		return true;
	}

	public static function delete($r){
		self::login_protected();

		// Check if soft delete is enabled
		if(Config::get_safe("soft_delete", true)){
			// Soft delete: move to trash (status = 5)
			DB::get_instance()->query("
				UPDATE `posts`
				SET `status` = 5
				WHERE `id` = ?
			", $r["id"]);
		} else {
			// Hard delete: permanently remove
			self::permanent_delete($r);
		}

		return true;
	}

	public static function trash($r){
		self::login_protected();

		// Move post to trash (status = 5)
		DB::get_instance()->query("
			UPDATE `posts`
			SET `status` = 5
			WHERE `id` = ?
		", $r["id"]);

		return true;
	}

	public static function restore($r){
		self::login_protected();

		// Restore post from trash (status = 1)
		DB::get_instance()->query("
			UPDATE `posts`
			SET `status` = 1
			WHERE `id` = ?
			AND `status` = 5
		", $r["id"]);

		return true;
	}

	public static function permanent_delete($r){
		self::login_protected();

		// Get post content to find associated images
		$post = DB::get_instance()->query("
			SELECT `content_type`, `content`
			FROM `posts`
			WHERE `id` = ?
		", $r["id"])->first();

		// Delete associated images if configured
		if(Config::get_safe("hard_delete_files", true) && $post){
			if($post['content_type'] === 'img_link' || $post['content_type'] === 'link'){
				// No local images to delete for external links
			} else {
				// Parse content for image references
				$content = json_decode($post['content'], true);
				if(is_array($content) && isset($content['images'])){
					foreach($content['images'] as $image){
						if(isset($image['path'])){
							$image_path = PROJECT_PATH . $image['path'];
							if(file_exists($image_path)){
								@unlink($image_path);
							}
						}
						if(isset($image['thumb'])){
							$thumb_path = PROJECT_PATH . $image['thumb'];
							if(file_exists($thumb_path)){
								@unlink($thumb_path);
							}
						}
					}
				}
			}
		}

		// Permanently delete the post
		DB::get_instance()->query("
			DELETE FROM `posts`
			WHERE `id` = ?
		", $r["id"]);

		return true;
	}

	public static function edit_data($r){
		self::login_protected();

		return DB::get_instance()->query("
			SELECT `plain_text`, `feeling`, `persons`, `location`, `privacy`, `content_type`, `content`
			FROM `posts`
			WHERE `id` = ?
			AND `status` <> 5
		", $r["id"])->first();
	}

	public static function get_date($r){
		self::login_protected();

		if (DB::connection() === 'sqlite') {
			$datetime = "strftime('%Y %m %d %H %M', `posts`.`datetime`)";
		} else if (DB::connection() === 'postgres') {
			$datetime = "to_char(datetime,'YYYY MM DD HH24 MI')";
		} else {
			$datetime = "DATE_FORMAT(`datetime`,'%Y %c %e %k %i')";
		}

		$date = DB::get_instance()->query("
			SELECT $datetime AS `date_format`
			FROM `posts`
			WHERE `id` = ?
			AND `status` <> 5
		", $r["id"])->first("date_format");
		$date = array_map("intval", explode(" ", $date));
		$date[4]  = floor($date[4]/10)*10;
		return $date;
	}

	public static function set_date($r){
		self::login_protected();

		$d = $r["date"];
		if (DB::connection() === 'sqlite') {
			$datetime = vsprintf("%04d-%02d-%02d %02d:%02d", $d);
		} else {
			$datetime = vsprintf("%04d/%02d/%02d %02d:%02d", $d);
		}

		DB::get_instance()->query("
			UPDATE `posts`
			SET `datetime` = ?
			WHERE `id` = ?
			AND `status` <> 5
		", $datetime, $r["id"]);
		return [ "datetime" => date("d M Y H:i", strtotime($datetime)) ];
	}

	public static function parse_link($r){
		self::login_protected();

		$l = $r["link"];

		preg_match('/^https?:\/\/([^:\/\s]+)([^\/\s]*\/)([^\.\s]+)\.(jpe?g|png|gif)((\?|\#)(.*))?$/i', $l, $img);
		if($img){
			return [
				"valid" => true,
				"content_type" => "img_link",
				"content" => [
					"src" => $l,
					"host" => $img[1]
				]
			];
		}

		preg_match('/^https?:\/\/(www\.)?([^:\/\s]+)(.*)?$/i', $l, $url);
		$curl_request_url = $l;

		// Get content
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_ENCODING , "");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; Proxycat/1.1)");
		curl_setopt($ch, CURLOPT_REFERER, '');
		curl_setopt($ch, CURLOPT_TIMEOUT, 7); // 7sec

		// Proxy settings
		if($proxy = Config::get_safe("proxy", false)){
			$proxytype = Config::get_safe("proxytype", false);
			$proxyauth = Config::get_safe("proxyauth", false);
			if($proxytype === 'URL_PREFIX'){
				$curl_request_url = $proxy.$curl_request_url;

				if($proxyauth){
					curl_setopt($ch, CURLOPT_USERPWD, $proxyauth);
				}
			} else {
				curl_setopt($ch, CURLOPT_PROXY, $proxy);

				if($proxyauth){
					curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyauth);
				}

				switch ($proxytype) {
					case 'CURLPROXY_SOCKS4':
						$proxytype = CURLPROXY_SOCKS4;
						break;
					case 'CURLPROXY_SOCKS5':
						$proxytype = CURLPROXY_SOCKS5;
						break;
					case 'CURLPROXY_HTTP':
					default:
						$proxytype = CURLPROXY_HTTP;
						break;
				}

				curl_setopt($ch, CURLOPT_PROXYTYPE, $proxytype);
			}
		}

		curl_setopt($ch, CURLOPT_URL, $curl_request_url);
		$html = curl_exec($ch);
		curl_close($ch);

		// Parse
		$doc = new DOMDocument();
		@$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);

		// Get title
		$nodes = $doc->getElementsByTagName('title');
		$title = $nodes->item(0)->nodeValue;

		// Content
		$content = [
			"link" => $l,
			"title" => ($title ? $title : $url[2]),
			"is_video" => false,
			"host" => $url[2]
		];

		// Metas
		$metas = $doc->getElementsByTagName('meta');
		for($i = 0; $i < $metas->length; $i++){
			$meta = $metas->item($i);

			$n = $meta->getAttribute('name');
			$p = $meta->getAttribute('property');
			$c = $meta->getAttribute('content');

			if($n == 'twitter:description' || $p == 'og:description' || $n == 'description'){
				$content["desc"] = substr($c, 0, 180);
			}

			if($n == 'twitter:title' || $p == 'og:title' || $p == 'title'){
				$content["title"] = $c;
			}

			if($p == 'og:url'){
				$content["link"] = $c;
			}

			if($p == 'og:type'){
				$content["is_video"] = (preg_match("/video/", $c));
			}

			if($n == 'twitter:image:src' || $p == 'og:image'){
				// Absolute url
				if(preg_match("/^(https?:)?\/\//", $c)) {
					$content["thumb"] = $c;
				}

				// Relative url from root
				elseif(preg_match("/^\//", $c)) {
					preg_match("/^((?:https?:)?\/\/([^\/]+))(\/|$)/", $l, $m);
					$content["thumb"] = $m[1].'/'.$c;
				}

				// Relative url from current directory
				else {
					preg_match("/^((?:https?:)?\/\/[^\/]+.*?)(\/[^\/]*)?$/", $l, $m);
					$content["thumb"] = $m[1].'/'.$c;
				}
			}

			if($n == 'twitter:domain'){
				$content["host"] = $c;
			}
		}

		return [
			"valid" => true,
			"content_type" => "link",
			"content" => $content
		];
	}

	public static function upload_image(){
		self::login_protected();

		return Image::upload();
	}

	public static function load($r){
		$from = [];
		if(preg_match("/^[0-9]{4}-[0-9]{2}$/", @$r["filter"]["from"])){
			$from = $r["filter"]["from"]."-01 00:00";
		}

		if(preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/", @$r["filter"]["from"])){
			$from = $r["filter"]["from"]." 00:00";
		}

		$to = [];
		if(preg_match("/^[0-9]{4}-[0-9]{2}$/", @$r["filter"]["to"])){
			$to = $r["filter"]["to"]."-01 00:00";
		}

		if(preg_match("/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/", @$r["filter"]["to"])){
			$to = $r["filter"]["to"]." 00:00";
		}

		$id = [];
		if(@$r["filter"]["id"]){
			$id = intval($r["filter"]["id"]);
		}

		$tag = [];
		if(preg_match("/^[A-Za-z0-9-_]+$/", @$r["filter"]["tag"])){
			$tag = '#'.$r["filter"]["tag"];
		}

		$loc = [];
		if(@$r["filter"]["loc"]){
			$loc = $r["filter"]["loc"];
		}

		$person = [];
		if(@$r["filter"]["person"]){
			$person = $r["filter"]["person"];
		}

		if (DB::connection() === 'sqlite') {
			$datetime = "strftime('%d %m %Y %H:%M', `posts`.`datetime`)";
		} else if (DB::connection() === 'postgres') {
			$datetime = "to_char(posts.datetime,'DD Mon YYYY HH24:MI')";
		} else {
			$datetime = "DATE_FORMAT(`posts`.`datetime`,'%d %b %Y %H:%i')";
		}

		$like_match = "LIKE ".DB::concat("'%'", "?", "'%'");

		return DB::get_instance()->query("
			SELECT
				`id`, `text`, `feeling`, `persons`, `location`, `privacy`, `content_type`, `content`,
				$datetime AS `datetime`, (`status` <> 1) AS `is_hidden`
			FROM `posts`
			WHERE ".
				(!User::is_logged_in() ? (User::is_visitor() ? "`privacy` IN ('public', 'friends') AND " : "`privacy` = 'public' AND ") : "").
				($from ? "`posts`.`datetime` > ? AND " : "").
				($to ? "`posts`.`datetime` < ? AND " : "").
				($id ? "`id` = ? AND " : "").
				($tag ? "`plain_text` $like_match AND " : "").
				($loc ? "`location` $like_match AND " : "").
				($person ? "`persons` $like_match AND " : "").
				"`status` <> 5
			ORDER BY `posts`.`datetime` ".(@$r["sort"] == 'reverse' ? "ASC" : "DESC")."
			LIMIT ? OFFSET ?
			", $from, $to, $id, $tag, $loc, $person, $r["limit"], $r["offset"]
		)->all();
	}

	public static function login($r){
		return User::login($r["nick"], $r["pass"]);
	}

	public static function logout(){
		return User::logout();
	}

	public static function handshake($r){
		return ["logged_in" => User::is_logged_in(), "is_visitor" => User::is_visitor()];
	}
}
