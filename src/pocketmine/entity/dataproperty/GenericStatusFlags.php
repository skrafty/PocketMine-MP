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

namespace pocketmine\entity\dataproperty;

interface GenericStatusFlags{

	const ONFIRE = 0;
	const SNEAKING = 1;
	const RIDING = 2;
	const SPRINTING = 3;
	const USINGITEM = 4;
	const INVISIBLE = 5;
	const TEMPTED = 6;
	const INLOVE = 7;
	const SADDLED = 8;
	const POWERED = 9;
	const IGNITED = 10;
	const BABY = 11;
	const CONVERTING = 12;
	const CRITICAL = 13;
	const SHOWNAME = 14;
	const ALWAYS_SHOWNAME = 15;
	const NOAI = 16;
	const SILENT = 17;
	const WALLCLIMBING = 18;
	const RESTING = 19;
	const SITTING = 20;
	const ANGRY = 21;
	const INTERESTED = 22;
	const CHARGED = 23;
	const TAMED = 24;
	const LEASHED = 25;
	const SHEARED = 26;
	const GLIDING = 27;
	const ELDER = 28;
	const MOVING = 29;
	const BREATHING = 30;
	const CHESTED = 31;
	const STACKABLE = 32;
	const SHOWBASE = 33;
	const REARING = 34;
	const VIBRATING = 35;
	const IDLING = 36;
}