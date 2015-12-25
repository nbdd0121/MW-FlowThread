<?php
namespace FlowThread;

class UUID {
	const HEX_LEN = 22;
	const BIN_LEN = 11;

	/**
	 * Hex UUID String
	 * @var string
	 */
	private $binValue = null;

	/**
	 * @var string
	 */
	private $hexValue = null;

	private $timestamp = 0;

	private function __construct() {

	}

	public static function fromHex($value) {
		$ret = new static;
		$ret->hexValue = $value;
		return $ret;
	}

	public static function fromBin($value) {
		$ret = new static;
		$ret->binValue = $value;
		return $ret;
	}

	public static function generate() {
		$hex = \UIDGenerator::newTimestampedUID88(16);
		$hex = str_pad($hex, static::HEX_LEN, '0', STR_PAD_LEFT);
		return self::fromHex($hex);
	}

	public function getHex() {
		if(!$this->hexValue) {
			$this->hexValue = str_pad(bin2hex($this->binValue), static::HEX_LEN, '0', STR_PAD_LEFT);
		}
		return $this->hexValue;
	}

	public function getBin() {
		if(!$this->binValue) {
			$this->binValue = pack('H*', $this->hexValue);
		}
		return $this->binValue;
	}

	public function getTimestamp() {
		if(!$this->timestamp) {
			$this->timestamp = intval((hexdec(substr($this->getHex(), 0, 12)) >> 2) / 1000);
		}
		return $this->timestamp;
	}
}
