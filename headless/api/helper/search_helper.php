<?php
// @deprecated Use headless\api\helper\search_service instead.
// This legacy procedural helper is kept for backward compatibility and
// will be removed in a future release.

function index_post_for_search($db, int $post_id, int $topic_id, int $forum_id, string $text, string $title): void
{
	$text = strtolower(strip_tags($text));
	$title = strtolower(strip_tags($title));

	$words = preg_split('/[^\w\x{80}-\x{FF}]+/u', $text . ' ' . $title, -1, PREG_SPLIT_NO_EMPTY);
	$words = array_unique($words);

	$common_words = ['the','a','an','is','was','are','were','be','been','being','have','has','had','do','does','did','will','would','could','should','may','might','shall','can','need','dare','ought','used','to','of','in','for','on','with','at','by','from','as','into','through','during','before','after','above','below','between','out','off','over','under','again','further','then','once','here','there','when','where','why','how','all','each','every','both','few','more','most','other','some','such','no','nor','not','only','own','same','so','than','too','very','just','because','but','and','or','if','while','that','this','these','those','it','its','you','your','he','him','his','she','her','they','them','their','what','which','who','whom'];

	$word_ids = [];
	foreach ($words as $word)
	{
		$word = mb_substr(trim($word), 0, 255);
		if (mb_strlen($word) < 2 || mb_strlen($word) > 30) continue;
		if (in_array($word, $common_words)) continue;

		$sql = 'SELECT word_id FROM ' . SEARCH_WORDLIST_TABLE . "
				WHERE word_text = '" . $db->sql_escape($word) . "'";
		$result = $db->sql_query($sql);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);

		if ($row)
		{
			$word_ids[$word] = (int) $row['word_id'];
			$sql = 'UPDATE ' . SEARCH_WORDLIST_TABLE . '
					SET word_count = word_count + 1
					WHERE word_id = ' . (int) $row['word_id'];
			$db->sql_query($sql);
		}
		else
		{
			$sql = 'INSERT INTO ' . SEARCH_WORDLIST_TABLE . " (word_text, word_common, word_count)
					VALUES ('" . $db->sql_escape($word) . "', 0, 1)";
			$db->sql_query($sql);
			$word_ids[$word] = (int) $db->sql_nextid();
		}
	}

	$title_words = preg_split('/[^\w\x{80}-\x{FF}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY);
	$title_words = array_unique($title_words);

	foreach ($word_ids as $word => $word_id)
	{
		$is_title = in_array($word, $title_words) ? 1 : 0;

		$sql = 'INSERT IGNORE INTO ' . SEARCH_WORDMATCH_TABLE . ' (post_id, word_id, title_match)
				VALUES (' . (int) $post_id . ', ' . (int) $word_id . ', ' . $is_title . ')';
		$db->sql_query($sql);
	}
}
