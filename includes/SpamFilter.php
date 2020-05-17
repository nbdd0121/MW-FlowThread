<?php
namespace FlowThread;

class SpamFilter {

	/**
	 * Validate if a regular expression is valid
	 */
	private static function validateRegex($regex) {
		wfSuppressWarnings();
		$ok = preg_match($regex, '');
		wfRestoreWarnings();

		if ($ok === false) {
			return false;
		}

		return true;
	}

	/**
	 * Parse a line in spam blacklist
	 */
	private static function parseLine($line) {
		$line = trim(preg_replace('/#.*$/', '', $line)); // Remove comments and trim space
		if (!$line) {
			// The line does not contain a regular expression
			return null;
		}

		// Extract regex and options from the result
		$result = null;
		preg_match('/^(.*?)(?:\s*<([^<>]*)>)?$/', $line, $result);
		@list($full, $regex, $opts) = $result;

		if (!$line) {
			// Cannot contain only options
			return null;
		}

		if (!$opts) {
			// Default value
			$opts = '';
		}

		// Must be a valid regex
		// This can also prevent problems when we joining the regex using |
		if (!self::validateRegex('/' . $regex . '/')) {
			// Abort for invalid regex
			return null;
		}

		return array(
			'regex' => $regex,
			'opt' => $opts,
		);
	}

	/**
	 * Parse option part in blacklist line (enclosed in <>)
	 */
	private static function parseOptions($opts) {
		$options = array();
		$segments = explode('|', $opts);
		foreach ($segments as $opt) {
			// Extract key=value pair
			$exploded = explode('=', $opt, 2);
			$key = $exploded[0];
			$value = isset($exploded[1]) ? $exploded[1] : '';

			switch ($key) {
			case 'replace':
				// Replace the text instead of marking as spam
				$options['replace'] = $value;
				break;
			default:
				if (in_array($key, \User::getAllRights())) {
					// If the name is a user right
					if (isset($options['right'])) {
						$options['right'][] = $key;
					} else {
						$options['right'] = array($key);
					}
				} else if (in_array($key, \User::getAllGroups())) {
					// If the name is a user group
					if (isset($options['group'])) {
						$options['group'][] = $key;
					} else {
						$options['group'] = array($key);
					}
				}
			}
		}
		return $options;
	}

	/**
	 * Parse whole spam blacklist
	 */
	private static function parseLines($lines) {
		$batches = array();
		foreach ($lines as $line) {
			$parsed = self::parseLine($line);
			if ($parsed) {
				if (isset($batches[$parsed['opt']])) {
					// Concatenate regexes to speed up
					$batches[$parsed['opt']] .= '|' . $parsed['regex'];
				} else {
					$batches[$parsed['opt']] = $parsed['regex'];
				}
			}
		}
		$ret = array();
		foreach ($batches as $opt => $regex) {
			$ret[] = array(
				'/' . $regex . '/iu',
				self::parseOptions($opt),
			);
		}
		return $ret;
	}

	private static function getBlackList() {
		$cache = \ObjectCache::getMainWANInstance();
		return $cache->getWithSetCallback(
			wfMemcKey('flowthread', 'spamblacklist'),
			60,
			function () {
				$source = wfMessage('flowthread-blacklist')->inContentLanguage();
				if ($source->isDisabled()) {
					return array();
				}
				$lines = explode("\n", $source->text());
				return self::parseLines($lines);
			}
		);
	}

	public static function validate($text, $poster, $wikitext) {
		$blacklist = self::getBlackList();
		$spammed = false;
		$ret = array(
			'good' => true,
		);

		foreach ($blacklist as $line) {
			list($regex, $opt) = $line;
			if (preg_match($regex, $text)) {
				if (isset($opt['group'])) {
					// When user is in the allowed group list, we skip this rule
					if (count(array_intersect($opt['group'], $poster->getGroups()))) {
						continue;
					}
				}

				if (isset($opt['right'])) {
					// Right-based control
					foreach ($opt['right'] as $item) {
						if ($poster->isAllowed($item)) {
							continue 2;
						}
					}
				}

				if (isset($opt['replace'])) {
					$replaceText = $opt['replace'];
					if ($wikitext) {
						$replaceText = '<nowiki>' . $replaceText . '</nowiki>';
					}
					// Do text replace instead of moving into spam
					$text = preg_replace($regex, $replaceText, $text);
					continue;
				}

				// Mark as bad
				$ret['good'] = false;
			}
		}

		$ret['text'] = $text;
		return $ret;
	}

	public static function sanitize($html) {
		return preg_replace('/position(?:\/\*[^*]*\*+([^\/*][^*]*\*+)*\/|\s)*:(?:\/\*[^*]*\*+([^\/*][^*]*\*+)*\/|\s)*fixed/i', '', $html);
	}
}