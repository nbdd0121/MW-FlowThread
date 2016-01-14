<?php
namespace FlowThread;

class SpamFilter {
	public static function validate($text) {
		$blacklist = self::getBlackList();

		if ($blacklist && preg_match($blacklist, $text)) {
			return false;
		}

		return true;
	}

	public static function badCodeFilter($html) {
		return preg_replace('/position(?:\/\*[^*]*\*+([^\/*][^*]*\*+)*\/|\s)*:(?:\/\*[^*]*\*+([^\/*][^*]*\*+)*\/|\s)*fixed/i', '', $html);
	}

	private static function stripLines($lines) {
		return array_filter(array_map('trim', preg_replace('/#.*$/', '', $lines)));
	}

	private static function validateRegex($regex) {
		wfSuppressWarnings();
		$ok = preg_match($regex, '');
		wfRestoreWarnings();

		if ($ok === false) {
			return false;
		}

		return true;
	}

	private static function buildBlacklist() {
		$source = wfMessage('flowthread-blacklist')->inContentLanguage();
		if ($source->isDisabled()) {
			return null;
		}
		$lines = explode("\n", $source->text());
		$lines = self::stripLines($lines);
		$lines = array_filter($lines, function ($regex) {
			return self::validateRegex('/' . $regex . '/');
		});
		if (!count($lines)) {
			return null;
		}
		return '/' . implode('|', $lines) . '/i';
	}

	public static function getBlackList() {
		$cache = \ObjectCache::getMainWANInstance();
		return $cache->getWithSetCallback(
			wfMemcKey('flowthread', 'spamblacklist'),
			60,
			function () {
				return self::buildBlacklist();
			}
		);
	}
}