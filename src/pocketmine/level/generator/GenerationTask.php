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

namespace pocketmine\level\generator;

use pocketmine\level\dimension\Dimension;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\generic\GenericChunk;
use pocketmine\level\SimpleChunkManager;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class GenerationTask extends AsyncTask{

	public $state;
	public $levelId;
	public $dimensionId;
	public $saveId;
	public $chunk;

	public function __construct(Dimension $dimension, Chunk $chunk){
		$this->state = true;
		$this->levelId = $dimension->getLevel()->getId();
		$this->dimensionId = $dimension->getSaveId();
		$this->chunk = $chunk->fastSerialize();
	}

	public function onRun(){
		/** @var SimpleChunkManager $manager */
		$manager = $this->getFromThreadStore("generation.level{$this->levelId}:{$this->dimensionId}.manager");
		/** @var Generator $generator */
		$generator = $this->getFromThreadStore("generation.level{$this->levelId}:{$this->dimensionId}.generator");
		if($manager === null or $generator === null){
			$this->state = false;
			return;
		}

		/** @var Chunk $chunk */
		$chunk = GenericChunk::fastDeserialize($this->chunk);
		if($chunk === null){
			//TODO error
			return;
		}

		$manager->setChunk($chunk->getX(), $chunk->getZ(), $chunk);

		$generator->generateChunk($chunk->getX(), $chunk->getZ());

		$chunk = $manager->getChunk($chunk->getX(), $chunk->getZ());
		$chunk->setGenerated();
		$this->chunk = $chunk->fastSerialize();

		$manager->setChunk($chunk->getX(), $chunk->getZ(), null);
	}

	public function onCompletion(Server $server){
		$level = $server->getLevel($this->levelId);
		if($level !== null){
			$dimension = $level->getDimension($this->dimensionId);
			if($dimension !== null){
				if($this->state === false){
					$dimension->registerGenerator();
					return;
				}

				/** @var Chunk $chunk */
				$chunk = GenericChunk::fastDeserialize($this->chunk, $level->getProvider());
				if($chunk === null){
					//TODO error
					return;
				}
				$dimension->generateChunkCallback($chunk->getX(), $chunk->getZ(), $chunk);
			}
		}
	}
}
