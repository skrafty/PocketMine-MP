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

namespace pocketmine\entity\component;

use pocketmine\math\Vector3;

class RideableComponent extends Component{
	/** @var RiderComponent[] */
	protected $riders = [];

	/** @var int */
	protected $maxRiderCount = 1;

	/**
	 * Returns an array of rider components attached to this component.
	 * @since API 3.0.0
	 *
	 * @return RiderComponent[]
	 */
	public function getRiders() : array{
		return $this->riders;
	}

	/**
	 * Returns the rider at the specified index.
	 * @since API 3.0.0
	 *
	 * @return RiderComponent|null
	 */
	public function getRider(int $index = 0){
		return $this->riders[$index] ?? null;
	}

	private function getSeatDescription(int $index = 0) : Vector3{
		return new Vector3(0, 0, 0);
		//TODO: add custom seating positions for multiple riders, rider default body rotation, rider rotation limit
	}

	/**
	 * Adds a rider to the component.
	 * @since API 3.0.0
	 *
	 * @param RiderComponent $rider
	 * @param int            $index
	 *
	 * @return bool indication of success
	 */
	public function addRider(RiderComponent $rider, int $index = 0){
		if($index < $this->maxRiderCount and $index >= 0 and !isset($this->riders[$index])){
			$this->riders[$index] = $rider;

			return true;
		}

		return false;
	}

	/**
	 * Removes a rider to the component.
	 * @since API 3.0.0
	 *
	 * @param RiderComponent $rider
	 *
	 * @return bool indication of success
	 */
	public function removeRider(RiderComponent $remove){
		foreach($this->riders as $index => $rider){
			if($rider === $remove){
				unset($this->riders[$index]);

				return true;
			}
		}

		return false;
	}

}