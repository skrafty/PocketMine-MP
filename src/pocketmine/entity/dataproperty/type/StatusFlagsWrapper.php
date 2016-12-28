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

namespace pocketmine\entity\dataproperty\type;

use pocketmine\entity\dataproperty\EntityDataManager;

/**
 * Wrapper class to manage status-flag properties, such as generic entity data flags.
 * @since API 3.0.0
 */
class StatusFlagsWrapper{
	/** @var DataProperty */
	protected $property;

	public function __construct(int $propertyKey){
		if(($property = EntityDataManager::createProperty($propertyKey)) === null){
			throw new \UnexpectedValueException("Could not create status flag wrapper: null property returned");
		}
		$this->property = $property;
	}

	/**
	 * Returns whether the bit at the specified position in the data property is set
	 * @since API 3.0.0
	 *
	 * @param int $bitshift
	 *
	 * @return bool true if bit is set, false if not.
	 */
	public function getFlag(int $bitshift) : bool{
		assert($bitshift < PHP_INT_SIZE * 8, "Cannot retrieve bit greater than " . PHP_INT_SIZE * 8 . " on this system");
		return (($this->property->getValue() & (1 << $bitshift))) !== 0;
	}

	/**
	 * Sets a flag at the specified position if it is not already set to that value.
	 * @since API 3.0.0
	 *
	 * @param int  $bitshift
	 * @param bool $value
	 */
	public function setFlag(int $bitshift, bool $value){
		assert($bitshift < PHP_INT_SIZE * 8, "Cannot set bit greater than " . PHP_INT_SIZE * 8 . " on this system");
		if($this->getFlag($bitshift) !== $value){
			$this->property->setValue($this->property->getValue() ^ (((int) $value) << $bitshift));
		}
	}

	/**
	 * Returns a broken-down array of data flags (1 or 0)
	 * TODO: adjust this to handle strings (long on 32-bit)
	 * @since API 3.0.0
	 *
	 * @return int[]
	 */
	public function getAllFlags(){
		$raw = $this->property->getValue();
		$bits = [];
		while($raw !== 0){
			$bits[] = $raw & 1;
			$raw = ($bits >> 1) & PHP_INT_MAX; //need logical right-shift!
		}

		return $bits;
	}

	/**
	 * Returns the actual data property itself.
	 * @since API 3.0.0
	 *
	 * @return DataProperty
	 */
	public function getProperty() : DataProperty{
		return $this->property;
	}

}