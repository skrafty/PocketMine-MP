<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types = 1);

namespace pocketmine\level\dimension;

use pocketmine\network\protocol\ChangeDimensionPacket;

/**
 * Manages dimension properties by network ID that cannot be modified freely due to client limitations.
 */
class DimensionType{

	/** @var DimensionType[] */
	private static $types = [];

	public static function init(){
		self::$types = [
			ChangeDimensionPacket::DIMENSION_OVERWORLD => new DimensionType(ChangeDimensionPacket::DIMENSION_OVERWORLD, 256, true,  true),
			ChangeDimensionPacket::DIMENSION_NETHER    => new DimensionType(ChangeDimensionPacket::DIMENSION_NETHER,    128, false, false),
			ChangeDimensionPacket::DIMENSION_THE_END   => new DimensionType(ChangeDimensionPacket::DIMENSION_THE_END,   256, false, false)
		];
	}

	/**
	 * Returns a dimension type by dimension ID.
	 * @since API 3.0.0
	 *
	 * @param int $networkId
	 *
	 * @return DimensionType|null
	 */
	public static function get(int $networkId){
		return self::$types[$networkId] ?? null;
	}

	private $networkId;
	private $buildHeight;
	private $hasSkyLight;
	private $hasWeather;

	private function __construct(int $networkId, int $buildHeight, bool $hasSkyLight, bool $hasWeather){
		$this->networkId = $networkId;
		$this->buildHeight = $buildHeight;
		$this->hasSkyLight = $hasSkyLight;
		$this->hasWeather = $hasWeather;
	}

	final public function getNetworkId() : int{
		return $this->networkId;
	}

	final public function getMaxBuildHeight() : int{
		return $this->buildHeight;
	}

	final public function hasSkyLight() : bool{
		return $this->hasSkyLight;
	}

	final public function hasWeather() : bool{
		return $this->hasWeather;
	}
}
