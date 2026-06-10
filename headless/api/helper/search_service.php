<?php
namespace headless\api\helper;

class search_service
{
	protected $db;
	protected $search_wordlist_table;
	protected $search_wordmatch_table;

	public function __construct($db, $search_wordlist_table, $search_wordmatch_table)
	{
		$this->db = $db;
		$this->search_wordlist_table = $search_wordlist_table;
		$this->search_wordmatch_table = $search_wordmatch_table;
	}

	public function indexPost(int $post_id, int $topic_id, int $forum_id, string $text, string $title): void
	{
		$text = mb_strtolower(strip_tags($text));
		$title = mb_strtolower(strip_tags($title));

		$words = preg_split('/[^\p{L}\p{N}]+/u', $text . ' ' . $title, -1, PREG_SPLIT_NO_EMPTY);
		$words = array_unique($words);

		$common_words = ['the','a','an','is','was','are','were','be','been','being','have','has','had','do','does','did','will','would','could','should','may','might','shall','can','need','dare','ought','used','to','of','in','for','on','with','at','by','from','as','into','through','during','before','after','above','below','between','out','off','over','under','again','further','then','once','here','there','when','where','why','how','all','each','every','both','few','more','most','other','some','such','no','nor','not','only','own','same','so','than','too','very','just','because','but','and','or','if','while','that','this','these','those','it','its','you','your','he','him','his','she','her','they','them','their','what','which','who','whom','bir','bu','ve','veya','ile','icin','gibi','kadar','ama','veya','de','da','mi','mu'];

		$word_ids = [];
		$new_words = [];

		foreach ($words as $word)
		{
			$word = mb_substr(trim($word), 0, 255);
			$len = mb_strlen($word);
			if ($len < 2 || $len > 30)
			{
				continue;
			}
			if (in_array(mb_strtolower($word), $common_words))
			{
				continue;
			}
			$new_words[] = $word;
		}

		$new_words = array_unique($new_words);

		if (empty($new_words))
		{
			return;
		}

		$this->db->sql_transaction('begin');

		try
		{
			$escaped_words = array_map([$this->db, 'sql_escape'], $new_words);
			$quoted_words = array_map(function ($w) { return "'" . $w . "'"; }, $escaped_words);

			$sql = 'SELECT word_id, word_text FROM ' . $this->search_wordlist_table . '
					WHERE word_text IN (' . implode(',', $quoted_words) . ')';
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$word_ids[$row['word_text']] = (int) $row['word_id'];
			}
			$this->db->sql_freeresult($result);

			foreach ($new_words as $word)
			{
				if (isset($word_ids[$word]))
				{
					$sql = 'UPDATE ' . $this->search_wordlist_table . '
							SET word_count = word_count + 1
							WHERE word_id = ' . $word_ids[$word];
					$this->db->sql_query($sql);
				}
				else
				{
					$sql = 'INSERT INTO ' . $this->search_wordlist_table . " (word_text, word_common, word_count)
							VALUES ('" . $this->db->sql_escape($word) . "', 0, 1)";
					$this->db->sql_query($sql);
					$word_ids[$word] = (int) $this->db->sql_nextid();
				}
			}

			$title_words = preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY);
			$title_words = array_unique($title_words);

			$match_values = [];
			foreach ($word_ids as $word => $wid)
			{
				$is_title = in_array($word, $title_words) ? 1 : 0;
				$match_values[] = '(' . (int) $post_id . ', ' . $wid . ', ' . $is_title . ')';
			}

			if (!empty($match_values))
			{
				$sql = 'INSERT IGNORE INTO ' . $this->search_wordmatch_table . ' (post_id, word_id, title_match)
						VALUES ' . implode(',', $match_values);
				$this->db->sql_query($sql);
			}

			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}
}
