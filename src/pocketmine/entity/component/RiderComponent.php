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

class RiderComponent extends Component{
	/** @var RideableComponent[] */
	protected $riding;

	/**
	 * Returns the rideable object
	 * @since API 3.0.0
	 *
	 * @return RideableComponent|null
	 */
	public function getRiding(){
		return $this->riding;
	}

	/**
	 * Adds a rider to the component.
	 * @since API 3.0.0
	 *
	 * @param RideableComponent $rider
	 *
	 * @return bool indication of success
	 */
	public function ride(RideableComponent $rideable){
		if($rideable->addRider($this)){
			$this->riding = $rideable;
		}
	}

}