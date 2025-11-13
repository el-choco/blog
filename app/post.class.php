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
		// Preserve HTML tags by temporarily replacing them with placeholders
		$html_tags = [];
		$tag_counter = 0;
		
		// Whitelist of allowed HTML tags
		$allowed_tags = ['center', 'div', 'span', 'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 
		                 'mark', 'small', 'big', 'sub', 'sup', 'code', 'pre', 'blockquote', 
		                 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
		                 'a', 'img', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'details', 'summary'];
		
		$tag_pattern = '/<\/?(' . implode('|', $allowed_tags) . ')(?:\s[^>]*)?\s*\/?>/i';
		
		$c = preg_replace_callback($tag_pattern, function($matches) use (&$html_tags, &$tag_counter) {
			$placeholder = "\x00HTMLTAG" . $tag_counter . "\x00";
			$html_tags[$placeholder] = $matches[0];
			$tag_counter++;
			return $placeholder;
		}, $c);
		
		// Process code blocks first (before other markdown)
		$code_blocks = [];
		$code_counter = 0;
		
		// Fenced code blocks with optional language (```lang or ``` followed by code then ```)
		$c = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function($matches) use (&$code_blocks, &$code_counter) {
			$lang = trim($matches[1]);
			$code = $matches[2];
			$placeholder = "\x00CODEBLOCK" . $code_counter . "\x00";
			
			if(Config::get_safe("highlight", false) && $lang) {
				$code_blocks[$placeholder] = '<code class="'.$lang.'">'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</code>';
			} else {
				$code_blocks[$placeholder] = '<pre><code>'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</code></pre>';
			}
			$code_counter++;
			return $placeholder;
		}, $c);
		
		// Inline code (`code`)
		$c = preg_replace_callback('/`([^`]+)`/', function($matches) use (&$code_blocks, &$code_counter) {
			$placeholder = "\x00CODEBLOCK" . $code_counter . "\x00";
			$code_blocks[$placeholder] = '<code>'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</code>';
			$code_counter++;
			return $placeholder;
		}, $c);
		
		// Process Markdown links/images BEFORE htmlspecialchars to preserve URLs
		// Temporarily store them to prevent double-processing
		$links = [];
		$link_counter = 0;
		
		// Markdown - Images ![alt](url)
		$c = preg_replace_callback('/!\[([^\]]*)\]\(([^\)]+)\)/', function($matches) use (&$links, &$link_counter) {
			$placeholder = "\x00LINK" . $link_counter . "\x00";
			$links[$placeholder] = '<img src="'.htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8').'" alt="'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'">';
			$link_counter++;
			return $placeholder;
		}, $c);
		
		// Markdown - Links [text](url)
		$c = preg_replace_callback('/\[([^\]]+)\]\(([^\)]+)\)/', function($matches) use (&$links, &$link_counter) {
			$placeholder = "\x00LINK" . $link_counter . "\x00";
			$links[$placeholder] = '<a href="'.htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8').'" target="_blank">'.htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8').'</a>';
			$link_counter++;
			return $placeholder;
		}, $c);
		
		// Escape HTML in the remaining text (not in preserved tags or code blocks)
		$c = htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
		
		// Restore HTML tag placeholders (they're now safe because they were whitelisted)
		foreach($html_tags as $placeholder => $tag) {
			$c = str_replace($placeholder, $tag, $c);
		}
		
		// Process Markdown - Headers (must be at start of line)
		$c = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $c);
		$c = preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $c);
		$c = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $c);
		$c = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $c);
		$c = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $c);
		$c = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $c);
		
		// Markdown - Bold (**text** or __text__)
		$c = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $c);
		$c = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $c);
		
		// Markdown - Italic (*text* or _text_) - but preserve single * for legacy bold
		$c = preg_replace('/_([^_]+)_/', '<em>$1</em>', $c);
		// Single * bold (legacy from old system - kept for backwards compatibility)
		$c = preg_replace('/\*([^\*\n]+)\*/', '<strong>$1</strong>', $c);
		
		// Markdown - Strikethrough (~~text~~)
		$c = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $c);
		
		// Markdown - Horizontal rule
		$c = preg_replace('/^(\-\-\-+|___+|\*\*\*+)$/m', '<hr>', $c);
		
		// Markdown - Blockquotes
		$c = preg_replace('/^&gt;\s+(.+)$/m', '<blockquote>$1</blockquote>', $c);
		
		// Markdown - Unordered lists
		$c = preg_replace_callback('/((?:^[\*\-\+]\s+.+$\n?)+)/m', function($matches) {
			$items = preg_replace('/^[\*\-\+]\s+(.+)$/m', '<li>$1</li>', $matches[1]);
			return '<ul>' . $items . '</ul>';
		}, $c);
		
		// Markdown - Ordered lists
		$c = preg_replace_callback('/((?:^\d+\.\s+.+$\n?)+)/m', function($matches) {
			$items = preg_replace('/^\d+\.\s+(.+)$/m', '<li>$1</li>', $matches[1]);
			return '<ol>' . $items . '</ol>';
		}, $c);
		
		// Markdown - Tables
		$c = preg_replace_callback('/(\|.+\|.*\n\|[\s\-\|:]+\|.*\n(?:\|.+\|.*\n?)*)/m', function($matches) {
			$table = $matches[1];
			$lines = explode("\n", trim($table));
			
			if(count($lines) < 3) return $matches[0];
			
			$header = array_shift($lines);
			$separator = array_shift($lines);
			
			// Parse header
			$headers = array_map('trim', explode('|', trim($header, '|')));
			$html = '<table><thead><tr>';
			foreach($headers as $h) {
				$html .= '<th>' . $h . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			
			// Parse rows
			foreach($lines as $line) {
				if(empty(trim($line))) continue;
				$cells = array_map('trim', explode('|', trim($line, '|')));
				$html .= '<tr>';
				foreach($cells as $cell) {
					$html .= '<td>' . $cell . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';
			
			return $html;
		}, $c);
		
		// Original text processing - Quotes replacement
		$c = preg_replace('/&quot;([^&]+)&quot;/', "„$1\"", $c);
		
		// Original text processing - Auto-linking URLs (if not already in a link)
		// Use negative lookbehind to avoid matching URLs that are already part of links
		$c = preg_replace('/(?<!href=&quot;|src=&quot;)(https?\:\/\/[^\s&<]+)/', '<a href="$1" target="_blank">$1</a>', $c);
		
		// Original text processing - Hashtags
		$c = preg_replace('/(\#[A-Za-z0-9-_]+)(\s|$|&)/', '<span class="tag">$1</span>$2', $c);
		
		// Convert line breaks to <br> (but not inside block elements)
		$c = nl2br($c);
		
		// Restore code blocks
		foreach($code_blocks as $placeholder => $code) {
			$c = str_replace($placeholder, $code, $c);
		}
		
		// Restore links
		foreach($links as $placeholder => $link) {
			$c = str_replace($placeholder, $link, $c);
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

		// Move to trash (status=2) instead of permanent delete
		DB::get_instance()->query("
			UPDATE `posts`
			SET `status` = 2
			WHERE `id` = ?
			AND `status` <> 5
		", $r["id"]);

		return true;
	}

	public static function restore($r){
		self::login_protected();

		// Restore from trash (set status back to 1)
		DB::get_instance()->query("
			UPDATE `posts`
			SET `status` = 1
			WHERE `id` = ?
			AND `status` = 2
		", $r["id"]);

		return true;
	}

	public static function permanent_delete($r){
		self::login_protected();

		// Permanently delete from database
		DB::get_instance()->query("
			DELETE FROM `posts`
			WHERE `id` = ?
			AND `status` = 2
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

		// Check if we're showing trash
		$show_trash = isset($r["filter"]["trash"]) && $r["filter"]["trash"] == "true";

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
				$datetime AS `datetime`, (`status` <> 1) AS `is_hidden`, (`status` = 2) AS `is_trashed`
			FROM `posts`
			WHERE ".
				(!User::is_logged_in() ? (User::is_visitor() ? "`privacy` IN ('public', 'friends') AND " : "`privacy` = 'public' AND ") : "").
				($from ? "`posts`.`datetime` > ? AND " : "").
				($to ? "`posts`.`datetime` < ? AND " : "").
				($id ? "`id` = ? AND " : "").
				($tag ? "`plain_text` $like_match AND " : "").
				($loc ? "`location` $like_match AND " : "").
				($person ? "`persons` $like_match AND " : "").
				($show_trash ? "`status` = 2" : "`status` <> 5 AND `status` <> 2")."
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
