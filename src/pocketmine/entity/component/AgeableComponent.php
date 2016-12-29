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

class AgeableComponent extends Component{

	/** @var int */
	protected $age;
	/** @var int */
	protected $minAge = -24000;
	/** @var int */
	protected $growUpAge = 0;

	public function __construct(Entity $entity, int $age = 0, int $minAge = -24000, int $growUpAge = 0, int $maxAge = 0){
		parent::__construct($entity);
		$this->age = max(-24000, min(0, $age));
		$this->minAge = $minAge;
		$this->growUpAge = $growUpAge;
		$this->maxAge = $maxAge;
	}

	public function getAge() : int{
		return $this->age;
	}

	public function setAge(int $age){
		$this->age = $age;
		$this->entity->scheduleComponentUpdate($this);
	}

	public function isBaby() : bool{
		return $this->age < 0;
	}

	public function setBaby(bool $value = true){
		$this->setAge($value ? $this->minAge : $this->maxAge);
	}

	public function growUp(){
		$this->age = $this->maxAge;
		$this->entity->getDataManager()->getProperty(DataPropertyKeys::GENERIC_STATUS_FLAGS)->setFlag(GenericStatusFlags::BABY, false);
		//TODO: scale of adult vs. baby, bounding boxes
		$this->entity->cancelComponentUpdate($this);
	}

	public function onUpdate(int $tickDiff) : bool{
		if($this->age < $this->maxAge){
			$this->age += $tickDiff;
		}

		if($this->age >= $this->maxAge){
			$this->growUp();
			return false;
		}

		return true;
	}

}